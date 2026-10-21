# Developer Guide — University Results Portal

This guide explains how the code is organised, how a request moves through it, and how to change, test
and ship it safely. It describes the repository as of commit `b9f086b` (PR #1 merged).

- **Just want to run it locally?** Follow [`local-dev/README.md`](../local-dev/README.md) (step by step, no Docker knowledge needed).
- **Deploying or rebuilding AWS?** See the [README](../README.md), §15 (shipping) and §3–§8 (infrastructure).

---

## 1. The system in one page

The project has two independent halves that meet on the EC2 instance:

| Half | What it is | Where |
|---|---|---|
| **Infrastructure** | Terraform: VPC, ALB, one private EC2, RDS MySQL, Secrets Manager, IAM | `*.tf` |
| **Application** | A WordPress plugin plus boot scripts that install it and load demo data | `wordpress-plugin/`, `install_wordpress.sh`, `initialize_*.{py,sh,php}` |

The architecture diagram, feature list and deploy/verify/cleanup steps are in the [README](../README.md).
This guide covers what's behind them.

Key design decisions (and why):

| Decision | Reason |
|---|---|
| All portal data lives in RDS; uploaded CSVs are never written to disk | The EC2 root volume is lost on replacement, and there is no shared storage yet. |
| Access codes are stored as **bcrypt hashes** in MySQL | A database leak doesn't reveal codes. |
| Plugin and demo files are downloaded from a **pinned Git commit** at boot | Every rebuild installs exactly the tested version. |
| Grades are **computed**, never stored | Changing the grade bands can't leave stale grades behind. |
| Publishing is per **term**, stored in one option | One switch controls what students can see. |

## 2. Repository map

What each top-level file does is listed in the README's [Repository components](../README.md#repository-components).
Things a developer needs to know about them:

- `*.tf`: 42 resources (§3–§7). Every change needs a reviewed plan (§6).
- `install_wordpress.sh` is rendered by Terraform's `templatefile()`, so mind the escaping, and editing it replaces the instance (§8).
- `initialize_wordpress.py` is pinned by its own commit URL, separately from `URP_COMMIT` (§15).

The plugin:

```text
wordpress-plugin/university-results/
    ├── university-results.php    Plugin header, constants, loads classes, registers hooks
    ├── uninstall.php             Removes capability; drops data only if explicitly enabled
    ├── includes/                 One class per file, prefix URP_ (table below)
    ├── templates/                PHP view files (HTML), no database access
    ├── assets/css, assets/js     admin.css, portal.css, admin.js (plain JS, no build step)
    ├── tests/                    PHPUnit (plain-PHP classes only)
    └── composer.json, phpunit.xml.dist   Dev tooling only; removed on EC2
```

### Plugin classes

| Class | File | Responsibility | Uses WordPress? |
|---|---|---|---|
| `URP_Installer` | `class-urp-installer.php` | Creates/upgrades tables (`dbDelta`), grants capability, table names | Yes |
| `URP_Repository` | `class-urp-repository.php` | **All SQL.** Students, results, terms, published terms | Yes (`$wpdb`) |
| `URP_Validator` | `class-urp-validator.php` | Input rules, percentage, grade bands, pass mark | **No** (unit-tested) |
| `URP_CSV_Importer` | `class-urp-csv-importer.php` | Parse + validate CSV, CSV-injection guard | **No** (unit-tested) |
| `URP_Access_Codes` | `class-urp-access-codes.php` | Generate, normalise, hash, verify codes | **No** (unit-tested) |
| `URP_Rate_Limiter` | `class-urp-rate-limiter.php` | Failed-lookup counters (transients), client IP | Yes |
| `URP_Lookup_Shortcode` | `class-urp-lookup-shortcode.php` | Public `[urp_result_lookup]` page | Yes |
| `URP_Admin` | `class-urp-admin.php` | wp-admin menu, form handling, AJAX, CSV download | Yes |
| `URP_CLI` | `class-urp-cli.php` | `wp urp …` commands | Yes (WP-CLI) |

**Rule of thumb:** SQL goes in `URP_Repository`, rules go in `URP_Validator`, HTML goes in `templates/`.
Keep the three "No" classes free of WordPress functions so they stay testable without WordPress.

---

# Part I: Infrastructure

Terraform code in the repository root, and the boot script that turns a new EC2 instance into a working portal.

## 3. Infrastructure: Terraform layout

Terraform code is flat in the repository root, one file per concern, with no modules. A full apply
creates **42 resources** and reads 2 data sources.

| File | Resources | Count |
|---|---|---|
| `versions.tf` | Terraform `>= 1.5.0`, provider `hashicorp/aws ~> 6.0` (locked to **6.69.1** in `.terraform.lock.hcl`) | — |
| `provider.tf` | `aws` provider, region from `var.aws_region` | — |
| `variables.tf` | `aws_region` (default `us-east-1`), `project_name` (default `terraform-wordpress`) | — |
| `networking.tf` | VPC, 6 subnets, Internet Gateway, 3 route tables, 6 route-table associations; data `aws_availability_zones` | 17 |
| `nat.tf` | Elastic IP, NAT Gateway, default route for the private **app** route table | 3 |
| `security.tf` | 3 security groups, 3 ingress rules, 2 egress rules | 8 |
| `alb.tf` | ALB, target group (+ health check), target attachment, HTTP listener | 4 |
| `ec2.tf` | WordPress instance; data `aws_ssm_parameter` (latest Amazon Linux 2023 AMI) | 1 |
| `rds.tf` | DB subnet group, MySQL instance | 2 |
| `iam.tf` | EC2 role, SSM policy attachment, instance profile, inline Secrets Manager/RDS policy | 4 |
| `secrets.tf` | 3 Secrets Manager secrets (containers only; values are written at boot) | 3 |
| `outputs.tf` | `wordpress_alb_url` | — |

### Dependency chain (simplified)

```text
VPC ─┬─ subnets ─┬─ route tables / IGW / NAT
     │           ├─ ALB ── listener ── target group ──┐
     │           ├─ DB subnet group ── RDS ───────────┤
     └─ SGs ─────┘                                    │
IAM role ── instance profile ── inline policy ────────┤   (policy references RDS + secret ARNs)
secrets ──────────────────────────────────────────────┤
                                                      ▼
                       aws_instance.wordpress  (depends_on RDS, DB secret, IAM policy)
                                                      ▼
                       aws_lb_target_group_attachment.wordpress
```

`aws_instance.wordpress` waits for RDS explicitly (`depends_on`), because the boot script connects to the
database on first start. RDS creation is the slowest step (about 5–10 minutes).

**What Terraform does *not* manage:** secret *values*, the RDS-managed master secret (`rds!db-…`, created by
RDS itself), WordPress files and data, and anything inside the database. Those are created by the boot
scripts (§8), so `terraform plan` can't detect drift in them.

## 4. Network design

```text
VPC 10.10.0.0/16  (us-east-1, two AZs from data.aws_availability_zones: names[0] = "A", names[1] = "B")

            AZ A                              AZ B
 public   10.10.1.0/24   ALB node, NAT      10.10.2.0/24   ALB node        → route 0.0.0.0/0 → IGW
 app      10.10.11.0/24  EC2 WordPress      10.10.12.0/24  (unused, for ASG) → route 0.0.0.0/0 → NAT (in A)
 db       10.10.21.0/24  RDS (one AZ)       10.10.22.0/24  (subnet group)    → local routes only
```

| Route table | Associated subnets | Routes |
|---|---|---|
| `aws_route_table.public` | public A, B | `0.0.0.0/0 → IGW` |
| `aws_route_table.private` | app A, B | `0.0.0.0/0 → NAT Gateway` (`aws_route.private_nat`) |
| `aws_route_table.private_db` | db A, B | VPC-local only: **the database has no internet path** |

### Security groups: who can talk to whom

| From → To | Port | Rule |
|---|---|---|
| Internet → ALB | 80/tcp | `alb_http` (`0.0.0.0/0`) |
| ALB → EC2 | 80/tcp | `wordpress_http` (source = ALB security group) |
| EC2 → RDS | 3306/tcp | `rds_mysql` (source = WordPress security group) |
| ALB → anywhere | all | `alb_outbound` |
| EC2 → anywhere (via NAT) | all | `wordpress_outbound`: dnf packages, wordpress.org, GitHub, AWS APIs |
| RDS → anywhere | — | No egress rule. Terraform removes AWS's default allow-all, and replies still work because security groups are stateful. |

There is **no SSH**: no port 22 rule, no key pair, and no public IP on the instance. Shell access is through
**SSM Session Manager** (`AmazonSSMManagedInstanceCore`). There are no VPC endpoints, so calls to Secrets
Manager, RDS and SSM APIs leave through the NAT Gateway. Because the only way into the instance on port 80
is from the ALB security group, the last `X-Forwarded-For` entry can be trusted (Known issue 4).

## 5. Compute, database, secrets and IAM

### EC2 (`ec2.tf`)

| Setting | Value | Notes |
|---|---|---|
| AMI | SSM parameter `/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64` | Always the **latest** AL2023 (see §6 and Known issues) |
| Type / subnet | `t3.micro`, `private_app_a`, no public IP | |
| Disk | 20 GB gp3, encrypted | Lost on replacement, so nothing important is stored here |
| User data | `templatefile("install_wordpress.sh", { wordpress_url = "http://<alb dns>" })` | `user_data_replace_on_change = true` |
| Profile | `Terraform-WordPress-Instance-Profile` | |

### RDS (`rds.tf`)

| Setting | Value | Notes |
|---|---|---|
| Engine | MySQL, **version not pinned** (AWS default at creation) | Pin `engine_version` for repeatable rebuilds |
| Class / storage | `db.t3.micro`, 20 GB gp3, encrypted | |
| Database / master user | `university_results` / `dbadmin` | WordPress uses its own app user, created by `initialize_wordpress.py` |
| Master password | `manage_master_user_password = true` | Stored in an RDS-managed secret, never in Terraform state |
| Availability | `multi_az = false`, `publicly_accessible = false` | Single AZ |
| Lab settings | `deletion_protection = false`, `skip_final_snapshot = true`, `backup_retention_period = 1` | **`terraform destroy` deletes all data with no final snapshot** |

### Secrets Manager (`secrets.tf`)

| Secret | Written by | Contents |
|---|---|---|
| `terraform-wordpress-db-credentials` | `initialize_wordpress.py` | WordPress application DB user password |
| `terraform-wordpress-admin-credentials` | `initialize_wordpress.py` | WordPress administrator login |
| `terraform-wordpress-student-access-codes` | `initialize_access_codes.php` | JSON `{reg_no: code}` for synthetic students |
| `rds!db-…` (not in Terraform) | RDS | Master user credentials |

All three Terraform secrets use `recovery_window_in_days = 0`, so they're deleted immediately on destroy.
That's what lets a rebuild reuse the same names; with the normal 7–30 day window, `apply` would fail with
"secret scheduled for deletion".

### IAM (`iam.tf`): what the instance may do

| Permission | Resource | Used by |
|---|---|---|
| `AmazonSSMManagedInstanceCore` (managed policy) | — | Session Manager shell access |
| `GetSecretValue`, `DescribeSecret`, `PutSecretValue` | DB and admin secrets | `initialize_wordpress.py` |
| `GetSecretValue`, `DescribeSecret`, `PutSecretValue` | student access-codes secret | `initialize_access_codes.php` |
| `GetSecretValue`, `DescribeSecret` | RDS master secret (`master_user_secret[0].secret_arn`) | `initialize_wordpress.py` (creates the app DB user) |
| `GetRandomPassword` | `*` (can't be scoped) | Password generation |
| `rds:DescribeDBInstances` | `*` | Finding the RDS endpoint |

Every secret permission is scoped to a specific ARN. **When you add a secret, add its ARN here as well**,
otherwise the boot script fails with an AccessDenied error.

## 6. Terraform workflow, state and reading plans

### Commands

The deploy sequence (`init` → `fmt -check` → `validate` → `plan -out` → `apply tfplan`) is in the README's
[Deploy the infrastructure](../README.md#deploy-the-infrastructure). As a developer, also:

- Run `terraform fmt -check && terraform validate` (and `bash -n install_wordpress.sh`) before every commit that touches `*.tf` or the boot script.
- Use `terraform plan -refresh-only` to see drift without proposing changes.
- Never run `apply` without a plan you've read; the table below shows what to expect.

### State

State is **local** (`terraform.tfstate` on the developer's Mac; no remote backend). That means one person
operates the environment at a time, from one machine. The state file contains resource IDs and ARNs, so
**never commit it** (`*.tfstate*` and `*.tfplan` are git-ignored) and keep a private backup. Moving to an
S3 backend with locking is the next step if more people need to deploy.

### What a change will do: read the plan symbols

| You change… | Plan shows | Impact |
|---|---|---|
| `install_wordpress.sh` (including `URP_COMMIT`) | `-/+` `aws_instance.wordpress` and `aws_lb_target_group_attachment.wordpress` | Old instance destroyed first: site down until the new one finishes booting (~10 min); RDS data kept |
| *Nothing*, but AWS published a new AL2023 AMI | Same two replacements | Unexpected replacement: see Known issues |
| `instance_type` | `~` in-place (stop/start) | Short outage |
| RDS `instance_class`, `allocated_storage` | `~` in-place modify | May restart the DB |
| RDS `identifier`, `db_name`, `username` | `-/+` replace | **All portal data lost** |
| A subnet's CIDR or AZ | `-/+` the subnet **and everything in it** | Large outage; review carefully |
| A security-group rule | `-/+` that rule | Seconds of blocked traffic |
| A secret's `name` | `-/+` the secret | Value lost; the boot scripts recreate it only on a new instance |
| Tags or descriptions | `~` in-place | Usually harmless (a security group `description` change forces replacement) |

Stop and investigate if the plan shows `destroy` or `-/+` for `aws_db_instance`, the VPC or subnets when you
didn't intend it.

### After the nightly lab cleanup

The lab may delete resources while local state still lists them.

1. `terraform plan -refresh-only` shows what has disappeared, without changing anything.
2. A normal `terraform plan` will then propose to **create** the missing resources. That's expected after a full cleanup.
3. If `apply` fails because a name already exists, something survived outside state (a security group,
   a secret). Decide deliberately: `terraform import` it, or delete it in the console. Don't delete the state file
   to "start fresh", because that orphans anything that still exists.
4. A full rebuild creates a new RDS database, and the boot scripts reload demo data and codes (§8).

### Destroy

`terraform plan -destroy` first, then `terraform destroy`. RDS has no final snapshot and secrets have no
recovery window, so everything is gone immediately. Take screenshots or exports first.

## 7. Infrastructure conventions and extending it

**Naming and tags.** Resources use the `Terraform-*` (title case) or `terraform-*` (lower case, where AWS
requires it, e.g. ALB and RDS identifiers) prefix, plus a `Name` tag. `Project = var.project_name` is only on
some resources. Prefer adding `default_tags { tags = { Project = var.project_name } }` to `provider.tf`
over tagging one resource at a time.

**Adding a resource.** Put it in the file for its concern (or a new `<concern>.tf`), give it a `Name` tag,
reference other resources by attribute (never hard-code IDs or ARNs), run `fmt`/`validate`, and check that
the plan only adds what you expect.

**Adding a secret the instance must use.**
1. Add `aws_secretsmanager_secret` in `secrets.tf` with `recovery_window_in_days = 0` (lab).
2. Add its ARN to the policy in `iam.tf`.
3. Write the value from a boot script, never from Terraform. Values in Terraform end up in state.

**Roadmap items and where they land:**

| Item | Terraform changes |
|---|---|
| HTTPS | `aws_acm_certificate` + DNS validation, `aws_lb_listener` on 443, HTTP listener → redirect action, ALB security group 443 rule, update `wordpress_url` |
| Auto Scaling | `aws_launch_template` (move AMI/profile/user_data from `ec2.tf`), `aws_autoscaling_group` across `private_app_a`/`b` with `target_group_arns`; remove `aws_instance` + attachment; add `URP_TRUST_PROXY_HEADER` |
| Shared uploads | `aws_efs_file_system` + mount targets in both app subnets + NFS security group rule (only needed for WordPress media; the plugin itself doesn't use uploads) |
| Monitoring | `aws_cloudwatch_metric_alarm` on target group `UnHealthyHostCount`, RDS CPU/storage; CloudWatch agent for the install log |
| RDS read replica | Second `aws_db_instance` with `replicate_source_db = aws_db_instance.wordpress.identifier` and `availability_zone` = AZ B (source backups are already enabled: `backup_retention_period = 1`). WordPress only writes to the primary unless a read/write split plugin is added |
| NAT resilience | Second NAT Gateway in public B and a per-AZ app route table (costs more) |
| Hardening | `metadata_options { http_tokens = "required" }` (IMDSv2) on the instance; pin RDS `engine_version`; scope `rds:DescribeDBInstances` to the DB ARN; S3 backend for state |

## 8. Boot sequence on EC2 (`install_wordpress.sh`)

```text
1  dnf install httpd php* mariadb105 python3 …; check AWS CLI; start httpd/php-fpm
2  Apache AllowOverride All (for .htaccess rewrites)
3  download WordPress latest.tar.gz → /var/www/html; install WP-CLI
4  initialize_wordpress.py   (pinned c3296bb…): DB user + secret, wp-config.php, WP install/admin secret
5  .htaccess + permalinks /%postname%/
6  download repo tarball at URP_COMMIT → copy plugin (minus tests/composer/phpunit)
   → /opt/university-results/{sample-data, initialize_demo_data.sh, initialize_access_codes.php}
7  wp plugin activate university-results        (creates tables, capability)
8  initialize_demo_data.sh                      (import, publish 2026-S1, unpublish 2026-S2, page)
9  wp eval-file initialize_access_codes.php     (Secrets Manager ↔ hashes)
```

Log: `/var/log/wordpress-install.log` (connect with SSM Session Manager; manual tasks are in the [operations runbook](plugin-deployment.md)). Steps 6–9 run on **every** new
instance, including when RDS survived:

- Activation is idempotent, and code initialisation only touches students without a hash.
- Imports are upserts, so admin-added students and results are kept. **Edits to the sample students' marks
  are reset to the CSV values.**
- `initialize_demo_data.sh` **unpublishes `2026-S2`** every time, even if an admin had published it.

**Template gotcha:** `ec2.tf` renders this file with `templatefile()`. In the script, `${var}` is a
**Terraform** variable, so Bash variables with braces must be written `$${VAR}`, and `%{` must be written `%%{`
(see the `.htaccess` block). Plain `$VAR` is fine.

---

# Part II: Application

The `university-results` WordPress plugin, its local development workflow, and how changes reach AWS.

## 9. Data model

### Tables (created by `URP_Installer::create_tables()`)

`{prefix}urp_students` (prefix is `wp_`)

| Column | Notes |
|---|---|
| `id` | PK |
| `reg_no` | **Unique**, upper-case, `^[A-Z0-9][A-Z0-9-]{2,31}$`. Not editable after creation. |
| `full_name`, `programme` | Programme is optional |
| `access_code_hash` | bcrypt (`$2y$11$…`), `NULL` = no code issued |
| `created_at`, `updated_at` | UTC |

`{prefix}urp_results`

| Column | Notes |
|---|---|
| `id` | PK |
| `student_id` | → `urp_students.id` (no FK constraint; `delete_student()` deletes results first) |
| `term`, `subject_code`, `subject_name` | e.g. `2026-S1`, `MA101`, `Engineering Mathematics I` |
| `marks`, `max_marks` | `DECIMAL(6,2)`, `0 ≤ marks ≤ max_marks ≤ 1000`, default max 100 |
| | **Unique (`student_id`, `term`, `subject_code`)** — saving the same key updates marks |

### Options and transients (in `wp_options`)

| Key | Contents |
|---|---|
| `urp_db_version` | Schema version; compared with `URP_DB_VERSION` on every load |
| `urp_published_terms` | Array of terms students may see, e.g. `["2026-S1"]` |
| `_transient_urp_rl_reg_<md5>` / `_transient_urp_rl_ip_<md5>` | Failed-lookup counters, 15 minutes |
| `_transient_urp_import_<user id>` | Validated CSV rows awaiting "Confirm import", 15 minutes |

### Grading (`URP_Validator`)

`A+ ≥ 90, A ≥ 80, B ≥ 70, C ≥ 60, D ≥ 50, E ≥ 40, F < 40`; pass mark `PASS_PERCENT = 40`.
The overall term figure is total marks ÷ total max marks.

## 10. How requests flow

### 10.1 Admin forms (Students, Results, Import, Publish & codes)

Every admin screen follows one pattern in `URP_Admin`:

```text
POST form (contains urp_action + nonce "urp_<action>")
  └─▶ load-{page hook}  →  handle_<page>()          (runs before any HTML output)
        posted_action(): capability manage_urp_results + check_admin_referer()
        ├─ valid   → URP_Repository::…  → redirect(?urp_notice=code)   (Post/Redirect/Get)
        └─ invalid → $this->errors / $this->form → page re-renders with messages and typed values
  render_<page>() → template('admin-…', vars)
```

Exceptions, both on purpose: **"New access code"** renders the code once in the same response (it is
never stored), and **"Generate codes"** streams a CSV download and exits.

`?urp_notice=` maps to a fixed message list in `notice_message()`, so no URL text is ever echoed.

### 10.2 Dropdowns and the student lookup (Results form)

`render_results()` passes `window.urpAdmin` (terms newest first, subjects by term, AJAX URL and nonce) to
`assets/js/admin.js`. Typing a reg. no calls `admin-ajax.php?action=urp_student` → `URP_Admin::ajax_student()`
(nonce `urp_student_lookup` + capability), which returns the name, programme and per-term result counts.
Any `<select data-new-input="id">` gets a **"New…"** option. The server reads the typed value from
`<field>_new` via `URP_Admin::choice()` (`NEW_CHOICE = '__new__'`).

### 10.3 CSV import

```text
Upload ─▶ URP_CSV_Importer::prepare(tmp file, type, reg_no_exists callback)
          header check (case-insensitive, BOM stripped, any column order), ≤ 1 MB, ≤ 5000 rows,
          per-row URP_Validator rules, duplicate-in-file detection, unknown-student check
       ─▶ preview table; valid rows kept in the per-user transient with a random token
Confirm ─▶ token checked with hash_equals, transient deleted (single use) ─▶ save_student / save_result
```

The same `prepare()` is used by `wp urp import` (`-` reads STDIN).
`save_*` use `INSERT … ON DUPLICATE KEY UPDATE` and report `created | updated | unchanged` (from MySQL's affected-rows count).

### 10.4 Student lookup (`[urp_result_lookup]`)

```text
POST on the page ─▶ template_redirect → URP_Lookup_Shortcode::handle_submission()
   nocache_headers()
   nonce "urp_lookup" ─ invalid → "This form expired"
   URP_Rate_Limiter::is_blocked(reg_no, ip) → "Too many unsuccessful attempts"
   find_student_by_reg_no → URP_Access_Codes::verify(code, hash|null)   (always one bcrypt check)
      fail    → record_failure(reg_no, ip) → GENERIC_ERROR (same text for every failure)
      success → clear(reg_no) → results_for_student(id, published_terms())
the_content ─▶ render() → templates/result-view.php or lookup-form.php
```

Limits: **5 failures per reg. no** and **20 per IP**, each for 15 minutes (`URP_Rate_Limiter` constants).
There is no REST endpoint and no list view on the public side, by design.

## 11. Access codes: two ways they get created

| Path | Who | Plaintext goes to | Hash goes to |
|---|---|---|---|
| **EC2 boot** — `initialize_access_codes.php` | `install_wordpress.sh` | Secrets Manager secret `terraform-wordpress-student-access-codes` (JSON `{reg_no: code}`) | MySQL |
| **wp-admin / WP-CLI** — "Generate codes", "New access code", `wp urp codes` | Administrator | Shown/downloaded **once**; not stored | MySQL |

How the boot script behaves: it only handles students **without** a hash. It reuses a code that's already
in the secret for that reg. no, writes new ones to the secret, reads the secret back to verify, and only then
writes hashes. It never prints codes.

> ⚠️ The two paths don't sync. If an admin issues a new code in wp-admin, the code in Secrets Manager
> for that student no longer works. Treat Secrets Manager as the source of truth only for codes created at boot.

To read a demo code on AWS (only from a trusted terminal; it is a secret):
`aws secretsmanager get-secret-value --secret-id terraform-wordpress-student-access-codes --query SecretString --output text`

## 12. Local development

```bash
cd local-dev
./setup.sh                        # start Docker, install WP, activate plugin, load sample data
./smoke-test.sh                   # 17 end-to-end checks
docker compose run --rm tests     # 48 PHPUnit tests
docker compose run --rm wpcli wp urp status
```

- The plugin folder is **bind-mounted** into the container. Edit PHP/CSS/JS and refresh the browser; there's no build step.
- Local URLs use plain permalinks (`/?page_id=N`); AWS uses `/student-results/`.
- Locally, codes come from `wp urp codes` → `local-dev/access-codes.csv` (git-ignored). Secrets Manager is not used.
- Data survives `docker compose stop/start/restart`. **`docker compose down -v` wipes it.**
- Logs: `docker compose logs -f wordpress`. `wp db query` doesn't work (MariaDB client vs MySQL 8 auth);
  use `wp eval` or `docker compose exec db mysql …` (see the local README).

## 13. Conventions and security checklist

Code style follows WordPress PHP conventions: tabs, `array()`, spaces inside parentheses, `URP_`/`urp_`
prefixes, PHP 8.1+ (`Requires PHP: 8.1`). Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.

For **every** change, check:

- [ ] **Input:** `wp_unslash()` + `sanitize_text_field()` (helpers `post()`, `get()`, `post_int()`), then `URP_Validator`.
- [ ] **Output:** escape late: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`. Use `textContent` in JS, never `innerHTML`.
- [ ] **SQL:** only in `URP_Repository`, always `$wpdb->prepare()` for values. Table names come from `URP_Installer`.
- [ ] **Writes:** capability `manage_urp_results` **and** a nonce (`posted_action()` does both for admin forms).
- [ ] **Student data:** never add a public endpoint that returns results without a verified code.
- [ ] **Secrets:** never log, echo or commit access codes, passwords or Terraform state.
- [ ] **CSV output:** wrap user-controlled cells in `URP_CSV_Importer::safe_cell()` (spreadsheet formula injection).
- [ ] **Messages:** keep the student-facing failure text identical for "unknown student" and "wrong code".

## 14. Making changes — recipes

### Change plugin code
1. Edit locally, then run `docker compose run --rm tests` and `./smoke-test.sh`. Click through the affected screen.
2. If you added a pure-PHP rule, add a PHPUnit test in `tests/unit/` (and `require_once` the class in `tests/bootstrap.php` if it's new).
3. Commit in small steps. Bump `Version:` / `URP_VERSION` for user-visible releases (it also busts CSS/JS caches).

### Change the database schema
1. Edit the `CREATE TABLE` in `URP_Installer::create_tables()`. Keep `dbDelta`'s format: one column per line, **two spaces** after `PRIMARY KEY`.
2. Bump `URP_DB_VERSION` in `university-results.php`. `maybe_upgrade()` then runs `dbDelta` on the next page load on every server.
3. `dbDelta` adds columns and indexes but **does not drop or rename** them. Write an explicit migration for that.

### Add an admin page
Add `add_submenu_page()` in `URP_Admin::add_menu()` with key `foo`, then `handle_foo()` (POST handling) and
`render_foo()` → `templates/admin-foo.php`. The `load-{hook}` wiring is automatic for keys in `$pages`.

### Add a WP-CLI command
Add a public method to `URP_CLI` with a docblock (`## OPTIONS` format). It becomes `wp urp <method>`.

### Change grade bands or pass mark
Edit `URP_Validator::GRADE_BANDS` / `PASS_PERCENT` and update `ValidatorTest`. No data migration is needed.

### Change demo data
Edit `sample-data/*.csv` (synthetic only), check with
`docker compose run --rm wpcli wp urp import results /sample-data/results.csv --dry-run`, then follow §15 to ship.

## 15. Shipping a change to AWS

The EC2 instance only ever runs the commit pinned in `install_wordpress.sh`:

1. Merge to `main` and push. Copy the **full 40-character SHA** (`git rev-parse HEAD`).
2. Set `URP_COMMIT="<sha>"` in `install_wordpress.sh`. If `initialize_wordpress.py` changed, also update its pinned URL.
3. Validate: `bash -n install_wordpress.sh && terraform fmt -check && terraform validate`.
4. `terraform plan`. Because `user_data_replace_on_change = true`, expect **only**
   `aws_instance.wordpress` and `aws_lb_target_group_attachment.wordpress` to be replaced. Stop if anything else changes.
5. Apply (after review), wait for the bootstrap, then repeat the README's functional checks and read the install log.

What survives an EC2 replacement: everything in RDS and Secrets Manager. What doesn't: files on the
instance (they're reinstalled from the pinned commit). After a **lab cleanup that deletes RDS**, the boot
sequence rebuilds the demo data and codes from scratch.

Never run `terraform apply`/`destroy` without reading the plan, never commit `*.tfstate`, `*.tfvars`,
`local-dev/.env` or `access-codes.csv` (all git-ignored), and remember that the public ALB is **HTTP only**.

---

# Reference

## 16. Troubleshooting

| Symptom | Where to look |
|---|---|
| `/student-results/` returns 404 on AWS | `AllowOverride All` and `.htaccess` (boot steps 2 and 5); `wp rewrite flush --hard`. |
| Boot didn't finish | `/var/log/wordpress-install.log`; the script uses `set -euo pipefail`, so the last line shows the failing step. |
| `initialize_access_codes.php` fails | IAM policy for the secret (`iam.tf`), region `us-east-1`, secret not pending deletion. |
| ALB returns 502/503 | Target unhealthy: health check `GET /` must return 200–399. Is the boot still running? Is Apache up? Check the target group's health in the EC2 console. |
| Boot fails downloading packages or GitHub files | Outbound path: app route table → NAT Gateway → IGW; NAT in public A has an EIP. |
| WordPress "Error establishing a database connection" | `rds_mysql` rule (source = WordPress security group), RDS status *Available*, DB secret value present. |
| `terraform apply`: `ExpiredToken` / `UnrecognizedClientException` | Lab credentials expired: refresh them and re-run `aws sts get-caller-identity`. |
| `terraform apply`: name `already exists` | A resource survived outside state (§6, "After the nightly lab cleanup"). |
| Plan wants to replace the instance although nothing changed | New AL2023 AMI (Known issue 1). |
| Student always gets the generic error | Is the term published? (`wp urp status`) Does the student have a hash? Was the code reissued in wp-admin (§11)? |
| "Too many unsuccessful attempts" | Wait 15 min, or locally `wp transient delete --all`. On AWS see Known issue 4. |
| Admin menu missing | User lacks `manage_urp_results`. Reactivate the plugin to re-grant it to administrators. |
| Import preview says "No student has this registration number" | Import students first. |
| PHP errors | Local: `docker compose logs wordpress`. AWS: `/var/log/httpd/error_log`, `/var/log/php-fpm/www-error.log`. |

## 17. Known issues and limitations

1. **AMI drift replaces the instance.** `ec2.tf` reads the *latest* AL2023 AMI on every plan, so any AWS AMI
   release makes the next `apply` replace the instance, even with no code change. Pin the AMI ID, or add
   `lifecycle { ignore_changes = [ami] }` and update deliberately.
2. **Infrastructure is single-AZ in practice.** One EC2 instance (app A), one NAT Gateway (public A) and a
   single-AZ RDS instance. AZ B subnets exist but host only the second ALB node.
3. **Hardening gaps:** IMDSv2 isn't enforced, the RDS engine version isn't pinned, state is local only,
   tagging is inconsistent, and `iam.tf` repeats one comment line. None of these block the demo.
4. **Per-IP rate limit is shared on AWS.** `URP_TRUST_PROXY_HEADER` isn't set, so `REMOTE_ADDR` is the ALB's
   address. All visitors share each per-IP counter, and 20 failed lookups from anyone in 15 minutes block
   **everyone** behind that ALB node. Fix: add `wp config set URP_TRUST_PROXY_HEADER true --raw` to the boot
   script. This is safe because the EC2 security group only accepts the ALB (`security.tf`, `wordpress_http`).
5. **Pinned plugin commit is a short SHA** (`URP_COMMIT="f807d15"`). It works today, but a full SHA can't become ambiguous.
6. **Local and AWS WordPress versions differ.** Local Docker pins WordPress 6.8.3; EC2 installs the latest
   release (7.x at the time of writing). Test against the AWS version before major changes.
7. **wp-admin codes and Secrets Manager don't sync** (§11).
8. **HTTP only, single instance, no backups, nightly lab cleanup.** Use synthetic data only.

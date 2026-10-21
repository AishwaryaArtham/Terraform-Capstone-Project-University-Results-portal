# Deploying the Results Portal plugin to AWS

> **Status:** Plan only — **not yet deployed**. The plugin has been built and tested locally
> (`local-dev/`). Nothing in this document has been run against AWS yet.

The plugin is application code and stays separate from Terraform. Terraform keeps creating the
infrastructure (ALB → private EC2 → RDS); the plugin is copied onto the EC2 instance and stores all
of its data in the existing RDS database.

## Why this design suits the current and planned architecture

- **All data lives in RDS** (two tables plus a few `wp_options` rows). Uploaded CSVs are read from PHP's
  temporary upload location and never stored, so the plugin does **not** need the pending
  shared-uploads (EFS/S3) work.
- **Activation is repeatable.** Tables are created/upgraded with `dbDelta` on activation and whenever the
  stored schema version changes. A replacement EC2 instance pointing at the same RDS keeps every student,
  result, access-code hash and publish setting.
- **Rate-limit counters are WordPress transients in the database**, so they will be shared correctly by
  multiple instances once an Auto Scaling group exists.
- **The plugin files are on the EC2 root volume**, so they are lost when the instance is replaced and must
  be reinstalled at boot (Stage 2 below).

## Before starting (each lab session)

1. Start the lab and refresh AWS credentials. Confirm region `us-east-1` and the expected account.
2. **You** run `terraform plan` and read it. Continue only if the infrastructure is still present and the
   plan shows no unexpected changes. If the nightly cleanup deleted resources, follow the README's
   *Nightly lab cleanup and Terraform state* section first — don't apply blindly.
3. Check the ALB target is healthy and the WordPress admin login works.
4. Push the `feature/results-plugin` branch (or merge it) to GitHub and note the **full commit SHA** that
   contains the plugin. As with `initialize_wordpress.py`, the install pins that exact commit.

## Stage 1: Install by hand over SSM (first deployment)

Open a shell on the private instance with **Systems Manager → Session Manager** (no SSH or public IP
needed), then run:

```bash
sudo -i
WP=/var/www/html
COMMIT=<full-commit-sha>   # the commit that contains wordpress-plugin/university-results
REPO=AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal

php -v | head -1   # must be PHP 8.1 or newer

cd /tmp && rm -rf urp urp.tar.gz && mkdir urp
curl -fL "https://codeload.github.com/$REPO/tar.gz/$COMMIT" -o urp.tar.gz
tar -xzf urp.tar.gz -C urp --strip-components=1

rm -rf "$WP/wp-content/plugins/university-results"
cp -a urp/wordpress-plugin/university-results "$WP/wp-content/plugins/"
rm -rf "$WP/wp-content/plugins/university-results/tests" \
       "$WP/wp-content/plugins/university-results/composer.json" \
       "$WP/wp-content/plugins/university-results/phpunit.xml.dist"
chown -R apache:apache "$WP/wp-content/plugins/university-results"
rm -rf /tmp/urp /tmp/urp.tar.gz

wp plugin activate university-results --path=$WP --allow-root
wp urp status --path=$WP --allow-root
```

### One-time settings on AWS

```bash
# Behind the ALB, the visitor's IP is the last X-Forwarded-For entry. This is safe because the
# EC2 security group only accepts HTTP from the ALB security group (security.tf: wordpress_http).
wp config set URP_TRUST_PROXY_HEADER true --raw --path=$WP --allow-root

# The page students use:
wp post create --post_type=page --post_title="Check your results" --post_name=results \
  --post_status=publish --post_content='[urp_result_lookup]' --path=$WP --allow-root
```

### Load demo data

Use **only the synthetic files** in `sample-data/`. The ALB is HTTP only, so anything typed or uploaded
(including access codes) crosses the internet unencrypted.

- **Option A — in wp-admin:** Results Portal → Import CSV (students first, then results), then
  Publish & codes → publish `2026-S1` → generate codes (downloads a CSV).
- **Option B — on the server:** the files are already in `/tmp/urp/sample-data` if you run this before
  the cleanup line above.

  ```bash
  wp urp import students /tmp/urp/sample-data/students.csv --path=$WP --allow-root
  wp urp import results  /tmp/urp/sample-data/results.csv  --path=$WP --allow-root
  wp urp publish 2026-S1 --path=$WP --allow-root
  wp urp codes --path=$WP --allow-root      # prints codes; copy what you need for the demo
  ```

### Verify on AWS

- [ ] `wp urp status` shows 6 students and 48 results, with `2026-S1` PUBLISHED.
- [ ] Admin menu **Results Portal** appears; non-admin users can't open it.
- [ ] `http://<alb-dns>/results/` (or `?page_id=…`): a correct code shows only 2026-S1. A wrong code
      and an unknown reg. no show the same message. 5 wrong attempts lock that reg. no.
- [ ] `sudo tail /var/log/httpd/error_log` shows no PHP fatal errors.

### Roll back

`wp plugin deactivate university-results --path=$WP --allow-root` hides the portal and **keeps the data**.
Deleting the plugin from wp-admin also keeps data unless `URP_DELETE_DATA_ON_UNINSTALL` is defined.

## Stage 2: Install automatically at boot (proposed, needs your review)

After Stage 1 works, make every new instance install the same pinned plugin version. **Proposed**
addition to the end of `install_wordpress.sh`, after the Python initializer finishes:

```bash
echo "Installing University Results Portal plugin..."
URP_COMMIT="<full-commit-sha>"
URP_DIR=/var/www/html/wp-content/plugins/university-results

curl -fL "https://codeload.github.com/AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal/tar.gz/$${URP_COMMIT}" \
  -o /tmp/urp.tar.gz
mkdir -p /tmp/urp && tar -xzf /tmp/urp.tar.gz -C /tmp/urp --strip-components=1
rm -rf "$URP_DIR" && cp -a /tmp/urp/wordpress-plugin/university-results "$URP_DIR"
rm -rf "$URP_DIR/tests" "$URP_DIR/composer.json" "$URP_DIR/phpunit.xml.dist" /tmp/urp /tmp/urp.tar.gz
chown -R apache:apache "$URP_DIR"

wp plugin activate university-results --path=/var/www/html --allow-root
wp config set URP_TRUST_PROXY_HEADER true --raw --path=/var/www/html --allow-root
echo "University Results Portal plugin installed."
```

Read these before applying:

1. **`$${...}` is intentional.** `ec2.tf` passes this script through Terraform's `templatefile()`,
   where `${...}` means a Terraform variable. `$${URP_COMMIT}` reaches Bash as `${URP_COMMIT}`.
   Plain `$URP_DIR` (no braces) is left alone by Terraform.
2. **Editing this script replaces the EC2 instance.** `user_data_replace_on_change = true`, so
   `terraform plan` should show `aws_instance.wordpress` and `aws_lb_target_group_attachment.wordpress`
   being replaced, and **nothing else**. RDS (and the portal data) is not touched.
3. Validate locally first: `bash -n install_wordpress.sh`, `terraform fmt -check`, `terraform validate`.
   Then **you** review `terraform plan -out=tfplan` and decide whether to apply.
4. After the new instance boots, check `/var/log/wordpress-install.log` for the
   "plugin installed" line, then repeat **Verify on AWS**.
5. To ship a plugin update later: commit, push, change `URP_COMMIT`, and repeat steps 3–4. Bump
   `URP_DB_VERSION` in the plugin only when the table definitions change.

## After the nightly lab cleanup

- **EC2 replaced, RDS kept:** with Stage 2 the plugin reinstalls itself and all data remains.
  Without Stage 2, repeat Stage 1 (data remains).
- **RDS deleted:** the database is new, so re-import the sample CSVs, publish the term and issue new
  codes. Old codes won't work.
- Keep real student data out of this lab entirely. It has no backups, no HTTPS and is wiped nightly.

## Later (P1 roadmap items that affect the plugin)

- **HTTPS (ACM + domain):** required before any real student uses access codes. No plugin change
  needed; update the WordPress site URL.
- **Auto Scaling:** every instance runs the Stage 2 step from the Launch Template user data, so all
  instances serve the same pinned version. No session stickiness is needed, because lookups are a
  single request.
- **Object cache (e.g. ElastiCache):** if added, it must be shared by all instances, or rate-limit
  counters would become per-instance.

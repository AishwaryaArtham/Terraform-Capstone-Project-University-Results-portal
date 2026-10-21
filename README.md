# University Results Portal on AWS using Terraform

An AWS re/Start capstone project that deploys a WordPress-based University Results Portal using Terraform. Students securely sign in with their university registration number and an individual access code to view **published** examination results. Administrators manage students, marks and publishing from the WordPress dashboard.

The project demonstrates Infrastructure as Code (IaC), cloud networking, a web application connected to a managed database, secrets management, and repeatable deployment automation.

> **Status:** Deployed and functionally tested on a fresh AWS lab (21 October 2026). This is a learning/demo environment that runs over **HTTP**. Use synthetic data only.

**Quick links:** [Deploy](#deploy-the-infrastructure) · [Verify](#access-and-verify-the-application) · [Run locally](#local-development-and-testing) · [Developer guide](docs/developer-guide.md)

## Project objectives

- Provision AWS infrastructure using Terraform.
- Deploy WordPress and a custom University Results Portal plugin on Amazon EC2.
- Store student records and examination results in Amazon RDS MySQL.
- Use AWS Secrets Manager for student access-code storage and store only access-code hashes in the database.
- Display published results while keeping unpublished results hidden from students.
- Rebuild the environment from scratch using an automated installation script.

## Architecture

![AWS architecture: users reach an Application Load Balancer in two public subnets, which forwards to a WordPress EC2 instance in a private app subnet; the instance uses RDS MySQL in a private DB subnet, a NAT Gateway for outbound access, and Secrets Manager, IAM and Systems Manager. Planned items, shown with orange labels: an Auto Scaling group, an RDS read replica and Amazon CloudWatch.](docs/architecture.svg)

Resources with orange **planned** labels are not deployed yet. Security groups allow only Internet → ALB (80),
ALB → EC2 (80) and EC2 → RDS (3306). Details of all 42 resources are in the
[developer guide](docs/developer-guide.md#part-i-infrastructure). The diagram is generated from
[`docs/diagram/build_architecture.py`](docs/diagram/README.md).

**Note:** The tested lab deployment uses HTTP, not HTTPS. Use only disposable demo credentials. HTTPS with a domain and an ACM certificate is a future security improvement.

## AWS services and tools

| Service or tool | Purpose |
| --- | --- |
| Amazon VPC | Isolated network with public, private application and private database subnets |
| Internet Gateway, NAT Gateway and route tables | Inbound access to the ALB; outbound-only internet for the private EC2 instance |
| Amazon EC2 | Hosts Apache, PHP, WordPress and the custom plugin |
| Application Load Balancer (ALB) | Public entry point for the WordPress application |
| Amazon RDS for MySQL | Stores students, examination results and WordPress data |
| AWS Secrets Manager | Stores database, administrator and generated student access-code secrets |
| AWS Systems Manager | Session Manager shell access to the private instance |
| IAM | Grants the EC2 instance only the permissions it needs |
| Security groups | Restrict network traffic between components |
| Terraform | Defines and provisions infrastructure as code |
| Bash, Python, PHP and WP-CLI | Automate WordPress and application initialization |
| Docker | Local development and testing without AWS |
| Git and GitHub | Version control and distribution of deployment code |

## Application features

### Student portal (`/student-results/`)

- Sign-in with a university registration number and an access code.
- Results for **published** terms only: marks, percentage, grade, pass/fail per subject, and an overall term result.
- Protected against guessing: a generic error message, rate-limited failed attempts, and no public list of students.
  ([How it works](docs/developer-guide.md#104-student-lookup-urp_result_lookup))

### Administration (`/wp-admin/` → **Results Portal**)

| Screen | What it does |
| --- | --- |
| **Students** | Add, edit, search and delete students; programme dropdown; issue a new access code (shown once) |
| **Results** | Add or update marks. Entering a registration number shows the student and selects the latest term; subject code and name dropdowns are filtered by term and linked to each other |
| **Import CSV** | Upload students or results, review a row-by-row validation preview, then confirm |
| **Publish & codes** | Publish or unpublish a term; download access codes for students who don't have one |

Only administrators (capability `manage_urp_results`) can use these screens. The same tasks can be scripted with WP-CLI: `wp urp status | import | codes | publish | unpublish`.

### Demo data and access codes

- Demo data is imported automatically during instance initialization.
- The dataset contains **6 synthetic students** and **48 examination results** (first-year B.Tech engineering subjects).
- Term `2026-S1` is published; term `2026-S2` is unpublished.
- After the students are imported, `initialize_access_codes.php` generates their access codes, stores them in AWS Secrets Manager, and stores only **bcrypt hashes** in the database.
- Plaintext access codes must never be committed to Git, added to Terraform variables or state, or printed in logs.

## Repository components

| File or directory | Role |
| --- | --- |
| `*.tf` | Terraform definitions for AWS infrastructure (VPC, ALB, EC2, RDS, IAM, Secrets Manager) |
| `install_wordpress.sh` | EC2 bootstrap (user data): installs WordPress and the plugin, then initializes the portal |
| `initialize_wordpress.py` | Database user, WordPress configuration and administrator initialization |
| `initialize_demo_data.sh` | Imports demonstration students and results, sets publishing, creates the results page |
| `initialize_access_codes.php` | Generates student access codes (Secrets Manager) and their database hashes |
| `wordpress-plugin/university-results/` | Custom WordPress plugin source and unit tests |
| `sample-data/` | Synthetic students, results, and a file with deliberate errors for testing the import |
| `local-dev/` | Docker environment, setup script and smoke test for local development |
| `docs/developer-guide.md` | How the infrastructure and application work, and how to change them |
| `docs/plugin-deployment.md` | Runbook: check, hotfix, roll back and re-initialize the plugin on a running instance (Session Manager) |

The bootstrap script downloads application files from a **pinned Git commit**. If you change the plugin, demo data or initialization scripts, push the change and update `URP_COMMIT` in `install_wordpress.sh` before a new deployment. See [Shipping a change to AWS](docs/developer-guide.md#15-shipping-a-change-to-aws).

## Prerequisites

- An AWS account or AWS Academy lab with permissions to create the required resources.
- Terraform (≥ 1.5) and AWS CLI installed locally.
- Valid AWS credentials for the active lab or account.
- Git and a terminal (for example, the VS Code terminal).
- Optional, for local development: Docker Desktop.

**Cost warning:** EC2, RDS, the NAT Gateway, the ALB and other AWS resources incur charges while deployed. Check your account or lab limits and clean up when finished.

## Deploy the infrastructure

From the repository root:

```bash
# Confirm the AWS identity for the active lab
aws sts get-caller-identity

# Initialize Terraform providers
terraform init

# Check formatting and validate the configuration
terraform fmt -check
terraform validate

# Preview planned changes and save the plan
terraform plan -out=tfplan

# Create exactly what you reviewed
terraform apply tfplan
```

Review the plan carefully before applying. A fresh deployment creates **42 resources**. If a plan unexpectedly destroys or replaces RDS, the VPC or subnets, stop and investigate.

After a successful deployment, get the load balancer URL:

```bash
terraform output wordpress_alb_url
```

The EC2 user-data script keeps installing WordPress for several minutes after Terraform reports `Apply complete!`. Allow time for the installation and the ALB health checks.

## Access and verify the application

Replace `<ALB-DNS-NAME>` with the DNS name from the Terraform output.

| Page | URL |
| --- | --- |
| WordPress website | `http://<ALB-DNS-NAME>/` |
| Student results | `http://<ALB-DNS-NAME>/student-results/` |
| Administrator login | `http://<ALB-DNS-NAME>/wp-login.php` |
| Administrator dashboard | `http://<ALB-DNS-NAME>/wp-admin/` |

**Get demo credentials** from a trusted terminal with your lab credentials. These are secrets: don't paste them into chats, issues or screenshots.

```bash
# Synthetic student access codes (JSON: registration number -> code)
aws secretsmanager get-secret-value --secret-id terraform-wordpress-student-access-codes \
  --query SecretString --output text

# WordPress administrator login
aws secretsmanager get-secret-value --secret-id terraform-wordpress-admin-credentials \
  --query SecretString --output text
```

**Suggested functional verification:**

1. Open the WordPress website through the ALB URL.
2. Open `/student-results/` and sign in with a synthetic student's registration number and access code.
3. Confirm that published `2026-S1` results appear.
4. Confirm that unpublished `2026-S2` results do not appear.
5. Try a wrong access code and confirm that a generic error is shown.
6. Sign in to WordPress administration and confirm that **Results Portal** appears and the plugin is active.

To inspect the instance, use **Systems Manager → Session Manager**. The bootstrap log is `/var/log/wordpress-install.log`, and `sudo -u apache wp urp status --path=/var/www/html` summarises the portal data.

### Verified deployment result

During the fresh-lab rebuild on **21 October 2026**, Terraform reported:

```text
Apply complete! Resources: 42 added, 0 changed, 0 destroyed.
```

The WordPress site, student authentication, published-results display, unpublished-results restriction, administrator login, and active plugin were all checked successfully. This is **functional verification**, not a complete security or penetration test.

## Updating student and result data

- **In the running portal:** use **Results Portal** in wp-admin (or `wp urp` over Session Manager). Changes are stored in RDS. They survive an EC2 replacement, but not a lab cleanup or `terraform destroy` that deletes RDS.
- **For a reproducible demo:** update `sample-data/*.csv`, push, update `URP_COMMIT`, and redeploy.

What a new instance re-runs, and how wp-admin codes relate to Secrets Manager, is explained in the [developer guide](docs/developer-guide.md#8-boot-sequence-on-ec2-install_wordpresssh).

## Local development and testing

The whole portal can run on your computer with Docker, with no AWS needed. It includes an end-to-end smoke test and PHPUnit tests.
Step-by-step instructions, written for Docker beginners: [`local-dev/README.md`](local-dev/README.md).

## Troubleshooting

If the site isn't reachable yet after `apply`, wait for the bootstrap to finish (`/var/log/wordpress-install.log`).
For everything else (ALB errors, 404s, failed logins, lockouts, expired lab credentials, "already exists" errors),
see the [troubleshooting table in the developer guide](docs/developer-guide.md#16-troubleshooting).

## Cleanup

After capturing any screenshots or evidence needed for your presentation, review what Terraform will remove:

```bash
terraform plan -destroy
```

Then, if you are ready to delete the environment:

```bash
terraform destroy
```

**Warning:** RDS is configured with no final snapshot and the secrets with no recovery window, so student data, results and secrets are deleted immediately and permanently. AWS Academy lab resets can also remove resources outside Terraform's control. Terraform state is stored locally; never commit `terraform.tfstate`.

## Known limitations

This is a single-instance, HTTP-only lab deployment with minimal backups, not a production system.
The full list, with suggested fixes, is in the [developer guide](docs/developer-guide.md#17-known-issues-and-limitations).

## Future enhancements

- HTTPS with AWS Certificate Manager and a custom domain.
- Synchronize access codes issued in wp-admin with Secrets Manager.
- Improved monitoring, alerts and operational logging (CloudWatch).
- High availability and scalability improvements such as Auto Scaling, an RDS read replica, a second NAT Gateway and shared storage, if needed.
- Remote Terraform state (S3) for team deployments.

## Project status

**Deployed and functionally tested.** The infrastructure and WordPress application were successfully rebuilt from a cleared AWS lab using Terraform and the automated installation script.

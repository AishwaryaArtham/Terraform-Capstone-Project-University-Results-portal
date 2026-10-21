# University Results Portal on AWS using Terraform

An AWS re/Start capstone project that deploys a WordPress-based University Results Portal using Terraform. Students can securely authenticate with their university registration number and an individual access code to view **published** examination results. Administrators can access the WordPress dashboard to manage the website.

The project demonstrates Infrastructure as Code (IaC), cloud networking, a web application connected to a managed database, secrets management, and repeatable deployment automation.

## Project objectives

- Provision AWS infrastructure using Terraform.
- Deploy WordPress and a custom University Results Portal plugin on Amazon EC2.
- Store student records and examination results in Amazon RDS MySQL.
- Use AWS Secrets Manager for student access-code storage and store access-code hashes in the database.
- Display published results while keeping unpublished results hidden from students.
- Rebuild the environment from scratch using an automated installation script.

## Architecture

```text
                     Student / Administrator
                              |
                         HTTP (port 80)
                              |
                     Application Load Balancer
                              |
                    EC2 instance (WordPress)
                    + University Results Plugin
                         /             \
                        /               \
                 RDS MySQL         AWS Secrets Manager
              Students / Results    Student access codes

           AWS VPC: public/private subnets, route tables,
                  Internet Gateway and security groups
```

**Note:** The tested lab deployment uses HTTP, not HTTPS. Use only disposable demo credentials. HTTPS with a domain and ACM certificate is a future security improvement.

## AWS services and tools

| Service or tool | Purpose |
| --- | --- |
| Amazon VPC | Isolated network with public and private subnets |
| Internet Gateway and route tables | Network routing |
| Amazon EC2 | Hosts Apache, PHP, WordPress and the custom plugin |
| Application Load Balancer (ALB) | Public entry point for the WordPress application |
| Amazon RDS for MySQL | Stores students, examination results and WordPress data |
| AWS Secrets Manager | Stores generated student access codes |
| IAM | Grants the EC2 workload permissions needed for AWS service access |
| Security groups | Restrict network traffic between components |
| Terraform | Defines and provisions infrastructure as code |
| Bash, Python, PHP and WP-CLI | Automate WordPress and application initialization |
| Git and GitHub | Version control and distribution of deployment code |

## Application features

### Student portal

- Student authentication using a university registration number and an access code.
- Display of results for published semesters only.
- Unpublished examination results are not shown to students.

### Administration

- WordPress administrator login at `/wp-login.php`.
- WordPress administration dashboard at `/wp-admin/`.
- University Results Portal plugin is installed and activated automatically.

### Demo data and access codes

- Demo data is imported during instance initialization.
- The tested dataset contains **6 synthetic students** and **48 examination results**.
- Semester `2026-S1` is published; semester `2026-S2` is unpublished.
- After the student records are imported, `initialize_access_codes.php` initializes access codes using AWS Secrets Manager and stores hashes in the database.
- Plaintext access codes should never be committed to Git, added to Terraform variables/state, or printed in installation logs.

## Repository components

| File or directory | Role |
| --- | --- |
| `*.tf` | Terraform definitions for AWS infrastructure |
| `install_wordpress.sh` | EC2 bootstrap: installs WordPress and initializes the portal |
| `initialize_wordpress.py` | WordPress database/site initialization |
| `initialize_demo_data.sh` | Imports demonstration students and results |
| `initialize_access_codes.php` | Initializes student access codes and database hashes |
| `sample-data/students.csv` | Synthetic student records |
| `sample-data/results.csv` | Synthetic examination results |
| `wordpress-plugin/university-results/` | Custom WordPress plugin source |

The bootstrap script downloads application files from a **pinned Git commit**. If you change the plugin or demo data, update the `URP_COMMIT` reference in `install_wordpress.sh` before a new deployment.

## Prerequisites

- An AWS account or AWS Academy lab with permissions to create the required resources.
- Terraform and AWS CLI installed locally.
- Valid AWS credentials for the active lab or account.
- Git and a terminal (for example, VS Code terminal).

**Cost warning:** EC2, RDS, ALB and other AWS resources can incur charges while deployed. Check your account/lab limits and clean up when finished.

## Deploy the infrastructure

From the repository's Terraform project directory:

```bash
# Confirm the AWS identity for the active lab
aws sts get-caller-identity

# Initialize Terraform providers
terraform init

# Validate Terraform configuration
terraform validate

# Preview planned changes
terraform plan

# Create the infrastructure
terraform apply
```

Review the Terraform plan carefully before typing `yes` to approve the apply.

After a successful deployment, obtain the load balancer URL:

```bash
terraform output wordpress_alb_url
```

The EC2 user-data script may continue installing WordPress after Terraform reports `Apply complete!`. Allow time for the installation and ALB health checks.

## Access and verify the application

Replace `<ALB-DNS-NAME>` with the DNS name in the Terraform output.

| Page | URL |
| --- | --- |
| WordPress website | `http://<ALB-DNS-NAME>/` |
| Student results | `http://<ALB-DNS-NAME>/student-results/` |
| Administrator login | `http://<ALB-DNS-NAME>/wp-login.php` |
| Administrator dashboard | `http://<ALB-DNS-NAME>/wp-admin/` |

WordPress normally redirects unauthenticated `/wp-admin/` requests to `/wp-login.php`.

**Suggested functional verification:**

1. Open the WordPress website through the ALB URL.
2. Open `/student-results/` and sign in using a synthetic student's university number and access code.
3. Confirm that published `2026-S1` results appear.
4. Confirm that unpublished `2026-S2` results do not appear.
5. Sign in to WordPress administration and confirm that the University Results Portal plugin is active.

### Verified deployment result

During the fresh-lab rebuild on **21 October 2026**, Terraform reported:

```text
Apply complete! Resources: 42 added, 0 changed, 0 destroyed.
```

The WordPress site, student authentication, published-results display, unpublished-results restriction, administrator login, and active plugin were all successfully checked. This is **functional verification**, not a complete security or penetration test.

## Updating student and result data

The running application reads its data from RDS. Manually added records can be used by the application when they follow the expected schema and have the necessary access-code setup. However, manual RDS changes **will not automatically survive** a full lab cleanup that deletes the database.

For reproducible demo deployments, update the sample CSV files and the pinned application commit, then redeploy or re-run the appropriate import workflow. New student access codes also need initialization. An administrator-facing import workflow can be added later.

## Troubleshooting

- **ALB unavailable immediately after apply:** Wait for EC2 bootstrap and load balancer health checks to finish.
- **Student-friendly URL returns 404:** Apache must permit `.htaccess` overrides (`AllowOverride All`), and WordPress rewrite rules/permalinks must be configured.
- **Student login fails:** Check that demo data and access-code initialization completed successfully; never print or expose plaintext codes while debugging.
- **Fresh AWS Academy lab cannot deploy:** Recheck the current lab credentials using `aws sts get-caller-identity`.

## Cleanup

After capturing any screenshots or evidence needed for your presentation, review the resources that Terraform will remove:

```bash
terraform plan -destroy
```

Then, if you are ready to delete the environment:

```bash
terraform destroy
```

**Warning:** Deleting RDS may permanently remove student and result data. Back up anything you need before destroying the infrastructure. AWS Academy lab resets can also remove resources outside Terraform's control.

## Future enhancements

- HTTPS with AWS Certificate Manager and a custom domain.
- Administrator-friendly student and results import workflows.
- Automatic access-code initialization for newly imported students.
- Improved monitoring, alerts and operational logging.
- High availability and scalability improvements such as Auto Scaling and shared storage, if needed.

## Project status

**Deployed and functionally tested.** The infrastructure and WordPress application were successfully rebuilt from a cleared AWS lab using Terraform and the automated installation script.

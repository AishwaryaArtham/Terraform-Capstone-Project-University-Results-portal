resource "aws_secretsmanager_secret" "wordpress_db" {
  name                    = "terraform-wordpress-db-credentials"
  description             = "Database credentials for University Results Portal"
  recovery_window_in_days = 0

  tags = {
    Name    = "Terraform-WordPress-DB-Secret"
    Project = var.project_name
  }
}

resource "aws_secretsmanager_secret" "wordpress_admin" {
  name                    = "terraform-wordpress-admin-credentials"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret" "student_access_codes" {
  name                    = "terraform-wordpress-student-access-codes"
  description             = "Synthetic student access codes for the University Results Portal demo"
  recovery_window_in_days = 0

  tags = {
    Name    = "Terraform-Student-Access-Codes"
    Project = var.project_name
  }
}
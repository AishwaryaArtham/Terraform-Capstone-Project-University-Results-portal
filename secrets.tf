resource "aws_secretsmanager_secret" "wordpress_db" {
  name                    = "terraform-wordpress-db-credentials"
  description             = "Database credentials for University Results Portal"
  recovery_window_in_days = 0

  tags = {
    Name    = "Terraform-WordPress-DB-Secret"
    Project = var.project_name
  }
}
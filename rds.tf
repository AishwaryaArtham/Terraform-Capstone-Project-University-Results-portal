resource "aws_db_subnet_group" "wordpress" {
  name        = "terraform-wordpress-db-subnet-group"
  description = "Private database subnets for University Results Portal"

  subnet_ids = [
    aws_subnet.private_db_a.id,
    aws_subnet.private_db_b.id
  ]

  tags = {
    Name = "Terraform-WordPress-DB-Subnet-Group"
  }
}

# RDS MySQL database for University Results Portal
resource "aws_db_instance" "wordpress" {
  identifier = "terraform-university-results-db"

  engine         = "mysql"
  instance_class = "db.t3.micro"

  allocated_storage = 20
  storage_type      = "gp3"
  storage_encrypted = true

  db_name  = "university_results"
  username = "dbadmin"

  # AWS manages the master password in Secrets Manager
  manage_master_user_password = true

  db_subnet_group_name = aws_db_subnet_group.wordpress.name

  vpc_security_group_ids = [
    aws_security_group.rds.id
  ]

  publicly_accessible = false
  multi_az            = false

  # Suitable for a disposable learning environment
  deletion_protection = false
  skip_final_snapshot = true

  backup_retention_period = 1

  tags = {
    Name    = "Terraform-University-Results-DB"
    Project = var.project_name
  }
}
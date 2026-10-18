# Security group for Application Load Balancer
resource "aws_security_group" "alb" {
  name        = "Terraform-ALB-SG"
  description = "Security group for public Application Load Balancer"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "Terraform-ALB-SG"
  }
}

# Security group for WordPress EC2
resource "aws_security_group" "wordpress" {
  name        = "Terraform-WordPress-SG"
  description = "Security group for WordPress application"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "Terraform-WordPress-SG"
  }
}

# Security group for RDS MySQL
resource "aws_security_group" "rds" {
  name        = "Terraform-RDS-SG"
  description = "Security group for RDS MySQL database"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "Terraform-RDS-SG"
  }
}

# Internet -> Application Load Balancer
resource "aws_vpc_security_group_ingress_rule" "alb_http" {
  security_group_id = aws_security_group.alb.id

  cidr_ipv4   = "0.0.0.0/0"
  from_port   = 80
  to_port     = 80
  ip_protocol = "tcp"

  description = "Allow HTTP from internet"
}

# Application Load Balancer -> WordPress EC2
resource "aws_vpc_security_group_ingress_rule" "wordpress_http" {
  security_group_id = aws_security_group.wordpress.id

  referenced_security_group_id = aws_security_group.alb.id

  from_port   = 80
  to_port     = 80
  ip_protocol = "tcp"

  description = "Allow HTTP from ALB"
}

# WordPress EC2 -> RDS MySQL
resource "aws_vpc_security_group_ingress_rule" "rds_mysql" {
  security_group_id = aws_security_group.rds.id

  referenced_security_group_id = aws_security_group.wordpress.id

  from_port   = 3306
  to_port     = 3306
  ip_protocol = "tcp"

  description = "Allow MySQL from WordPress"
}

# ALB outbound traffic
resource "aws_vpc_security_group_egress_rule" "alb_outbound" {
  security_group_id = aws_security_group.alb.id

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "-1"

  description = "Allow outbound traffic"
}

# WordPress outbound traffic
resource "aws_vpc_security_group_egress_rule" "wordpress_outbound" {
  security_group_id = aws_security_group.wordpress.id

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "-1"

  description = "Allow outbound traffic"
}
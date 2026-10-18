resource "aws_vpc" "main" {
  cidr_block           = "10.10.0.0/16"
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "Terraform-WordPress-VPC"
  }
}

# Retrieve available Availability Zones
data "aws_availability_zones" "available" {
  state = "available"
}

# Public subnet in AZ A
resource "aws_subnet" "public_a" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.10.1.0/24"
  availability_zone       = data.aws_availability_zones.available.names[0]
  map_public_ip_on_launch = true

  tags = {
    Name = "Terraform-Public-A"
  }
}

# Public subnet in AZ B
resource "aws_subnet" "public_b" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.10.2.0/24"
  availability_zone       = data.aws_availability_zones.available.names[1]
  map_public_ip_on_launch = true

  tags = {
    Name = "Terraform-Public-B"
  }
}

# Private application subnet in AZ A
resource "aws_subnet" "private_app_a" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.10.11.0/24"
  availability_zone = data.aws_availability_zones.available.names[0]

  tags = {
    Name = "Terraform-Private-App-A"
  }
}

# Private application subnet in AZ B
resource "aws_subnet" "private_app_b" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.10.12.0/24"
  availability_zone = data.aws_availability_zones.available.names[1]

  tags = {
    Name = "Terraform-Private-App-B"
  }
}

# Private database subnet in AZ A
resource "aws_subnet" "private_db_a" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.10.21.0/24"
  availability_zone = data.aws_availability_zones.available.names[0]

  tags = {
    Name = "Terraform-Private-DB-A"
  }
}

# Private database subnet in AZ B
resource "aws_subnet" "private_db_b" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.10.22.0/24"
  availability_zone = data.aws_availability_zones.available.names[1]

  tags = {
    Name = "Terraform-Private-DB-B"
  }
}
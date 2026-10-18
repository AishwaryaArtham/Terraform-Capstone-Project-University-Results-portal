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

# Internet Gateway
resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "Terraform-WordPress-IGW"
  }
}

# Public Route Table
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id
  }

  tags = {
    Name = "Terraform-Public-RT"
  }
}

# Associate Public-A with Public Route Table
resource "aws_route_table_association" "public_a" {
  subnet_id      = aws_subnet.public_a.id
  route_table_id = aws_route_table.public.id
}

# Associate Public-B with Public Route Table
resource "aws_route_table_association" "public_b" {
  subnet_id      = aws_subnet.public_b.id
  route_table_id = aws_route_table.public.id
}

# Private Route Table
resource "aws_route_table" "private" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "Terraform-Private-RT"
  }
}

# Private application subnets
resource "aws_route_table_association" "private_app_a" {
  subnet_id      = aws_subnet.private_app_a.id
  route_table_id = aws_route_table.private.id
}

resource "aws_route_table_association" "private_app_b" {
  subnet_id      = aws_subnet.private_app_b.id
  route_table_id = aws_route_table.private.id
}

# Private database subnets
resource "aws_route_table_association" "private_db_a" {
  subnet_id      = aws_subnet.private_db_a.id
  route_table_id = aws_route_table.private_db.id
}

resource "aws_route_table_association" "private_db_b" {
  subnet_id      = aws_subnet.private_db_b.id
  route_table_id = aws_route_table.private_db.id
}

# Dedicated route table for private database subnets
resource "aws_route_table" "private_db" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "Terraform-Private-DB-RT"
  }
}
# Allocate a public Elastic IP for NAT Gateway
resource "aws_eip" "nat" {
  domain = "vpc"

  tags = {
    Name = "Terraform-WordPress-NAT-EIP"
  }
}

# Create NAT Gateway in Public Subnet A
resource "aws_nat_gateway" "main" {
  allocation_id = aws_eip.nat.id
  subnet_id     = aws_subnet.public_a.id

  # Ensure the Internet Gateway exists first
  depends_on = [aws_internet_gateway.main]

  tags = {
    Name = "Terraform-WordPress-NAT"
  }
}

# Outbound internet route for private application subnets
resource "aws_route" "private_nat" {
  route_table_id         = aws_route_table.private.id
  destination_cidr_block = "0.0.0.0/0"
  nat_gateway_id         = aws_nat_gateway.main.id
}
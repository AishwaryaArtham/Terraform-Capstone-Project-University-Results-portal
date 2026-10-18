# Retrieve latest Amazon Linux 2023 AMI
data "aws_ssm_parameter" "amazon_linux_2023" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}

# Private EC2 instance for WordPress
resource "aws_instance" "wordpress" {

  user_data                   = file("${path.module}/install_wordpress.sh")
  user_data_replace_on_change = true
  ami                         = data.aws_ssm_parameter.amazon_linux_2023.value
  instance_type               = "t3.micro"

  subnet_id = aws_subnet.private_app_a.id

  associate_public_ip_address = false

  vpc_security_group_ids = [
    aws_security_group.wordpress.id
  ]

  iam_instance_profile = aws_iam_instance_profile.wordpress.name

  root_block_device {
    volume_size = 20
    volume_type = "gp3"
    encrypted   = true
  }

  tags = {
    Name    = "Terraform-WordPress-EC2"
    Project = var.project_name
  }
}
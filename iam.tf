# IAM role for WordPress EC2
resource "aws_iam_role" "wordpress_ec2" {
  name = "Terraform-WordPress-EC2-Role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Principal = {
          Service = "ec2.amazonaws.com"
        }
        Action = "sts:AssumeRole"
      }
    ]
  })

  tags = {
    Name = "Terraform-WordPress-EC2-Role"
  }
}

# Allow EC2 to communicate with Systems Manager
resource "aws_iam_role_policy_attachment" "ssm" {
  role = aws_iam_role.wordpress_ec2.name

  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# Instance profile for WordPress EC2
resource "aws_iam_instance_profile" "wordpress" {
  name = "Terraform-WordPress-Instance-Profile"
  role = aws_iam_role.wordpress_ec2.name
}
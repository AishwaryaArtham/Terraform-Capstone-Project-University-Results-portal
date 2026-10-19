
# ============================================================
# IAM Role for WordPress EC2
# ============================================================

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


# ============================================================
# Allow EC2 to communicate with AWS Systems Manager
# ============================================================

resource "aws_iam_role_policy_attachment" "ssm" {
  role = aws_iam_role.wordpress_ec2.name

  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}


# ============================================================
# IAM Instance Profile for WordPress EC2
# ============================================================

resource "aws_iam_instance_profile" "wordpress" {
  name = "Terraform-WordPress-Instance-Profile"

  role = aws_iam_role.wordpress_ec2.name
}


# ============================================================
# Secrets Manager and RDS Permissions
# ============================================================

resource "aws_iam_role_policy" "wordpress_secrets" {
  name = "Terraform-WordPress-Secrets-Access"

  role = aws_iam_role.wordpress_ec2.id

  policy = jsonencode({
    Version = "2012-10-17"

    Statement = [

      # Allow EC2 to read and update WordPress secrets
      # Allow EC2 to read and update WordPress secrets
      {
        Effect = "Allow"

        Action = [
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret",
          "secretsmanager:PutSecretValue"
        ]

        Resource = [
          aws_secretsmanager_secret.wordpress_db.arn,
          aws_secretsmanager_secret.wordpress_admin.arn
        ]
      },

      # Allow EC2 to manage synthetic student access codes
      {
        Effect = "Allow"

        Action = [
          "secretsmanager:PutSecretValue",
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret"
        ]

        Resource = aws_secretsmanager_secret.student_access_codes.arn
      },

      # Allow EC2 to retrieve RDS master credentials
      {
        Effect = "Allow"

        Action = [
          "secretsmanager:GetSecretValue",
          "secretsmanager:DescribeSecret"
        ]

        Resource = aws_db_instance.wordpress.master_user_secret[0].secret_arn
      },

      # Allow password generation using Secrets Manager
      {
        Effect = "Allow"

        Action = [
          "secretsmanager:GetRandomPassword"
        ]

        Resource = "*"
      },

      # Allow EC2 to retrieve RDS instance information
      {
        Effect = "Allow"

        Action = [
          "rds:DescribeDBInstances"
        ]

        Resource = "*"
      }
    ]
  })
}

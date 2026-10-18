output "wordpress_alb_url" {
  description = "Public URL of the University Results Portal"
  value       = "http://${aws_lb.wordpress.dns_name}"
}
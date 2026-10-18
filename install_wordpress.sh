#!/bin/bash
set -euo pipefail

# Log setup progress without exposing passwords
exec > >(tee -a /var/log/wordpress-install.log) 2>&1

echo "Starting WordPress installation..."

# Update packages
dnf update -y

# Install Apache, PHP and required tools
dnf install -y \
  httpd \
  php \
  php-fpm \
  php-mysqlnd \
  php-gd \
  php-mbstring \
  php-xml \
  php-intl \
  php-opcache \
  php-zip \
  mariadb105 \
  python3 \
  wget \
  tar

# Start web services
systemctl enable --now httpd
systemctl enable --now php-fpm

# Download and install WordPress
if [ ! -f /var/www/html/wp-load.php ]; then
  cd /tmp
  wget -q -O wordpress.tar.gz https://wordpress.org/latest.tar.gz
  tar -xzf wordpress.tar.gz
  cp -a wordpress/. /var/www/html/
fi

# Set WordPress file permissions
chown -R apache:apache /var/www/html
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

systemctl restart httpd
echo "WordPress files installed successfully."

echo "Installing WP-CLI..."

curl -fsSL \
  https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  -o /tmp/wp-cli.phar

php /tmp/wp-cli.phar --info

install -m 755 /tmp/wp-cli.phar /usr/local/bin/wp

rm -f /tmp/wp-cli.phar

echo "WP-CLI installed successfully."

echo "Preparing WordPress database initialization..."

echo "Downloading WordPress initialization script..."

curl -fsSL \
  "https://raw.githubusercontent.com/AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal/main/initialize_wordpress.py" \
  -o /tmp/initialize_wordpress.py

chmod 600 /tmp/initialize_wordpress.py

WORDPRESS_URL='${wordpress_url}' python3 /tmp/initialize_wordpress.py

rm -f /tmp/initialize_wordpress.py

echo "WordPress initialization completed."

chmod 600 /tmp/initialize_wordpress.py

WORDPRESS_URL='${wordpress_url}' python3 /tmp/initialize_wordpress.py

rm -f /tmp/initialize_wordpress.py

echo "WordPress database initialization completed."
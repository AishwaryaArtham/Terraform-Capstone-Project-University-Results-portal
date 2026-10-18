#!/bin/bash
set -euxo pipefail

# Update packages
dnf update -y

# Install Apache, PHP and WordPress dependencies
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
  wget \
  tar

# Start web services
systemctl enable --now httpd
systemctl enable --now php-fpm

# Download WordPress
cd /tmp
wget -O wordpress.tar.gz https://wordpress.org/latest.tar.gz
tar -xzf wordpress.tar.gz

# Install WordPress files
cp -a wordpress/. /var/www/html/

# Set permissions
chown -R apache:apache /var/www/html
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

# Restart Apache
systemctl restart httpd
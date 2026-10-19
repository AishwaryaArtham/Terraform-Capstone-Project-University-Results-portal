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

# Verify AWS CLI is available
echo "Checking AWS CLI installation..."

if ! command -v aws >/dev/null 2>&1; then
    echo "ERROR: AWS CLI is not installed."
    exit 1
fi

aws --version

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

curl -fL \
  https://raw.githubusercontent.com/AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal/c3296bb3cd7946487c1fa53b17bbe2f6d018550e/initialize_wordpress.py \
  -o /tmp/initialize_wordpress.py

chmod 600 /tmp/initialize_wordpress.py

WORDPRESS_URL='${wordpress_url}' python3 /tmp/initialize_wordpress.py

rm -f /tmp/initialize_wordpress.py

echo "WordPress initialization completed."


echo "Installing University Results Portal plugin..."

URP_COMMIT="e00df129c8c3afecf36250ace445f9ab5bd7498d"
URP_DIR=/var/www/html/wp-content/plugins/university-results
URP_TMP=$(mktemp -d /tmp/urp-install.XXXXXX)

curl -fL \
  "https://codeload.github.com/AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal/tar.gz/$${URP_COMMIT}" \
  -o "$URP_TMP/source.tar.gz"

mkdir -p "$URP_TMP/source"
tar -xzf "$URP_TMP/source.tar.gz" \
  -C "$URP_TMP/source" \
  --strip-components=1

test -f "$URP_TMP/source/wordpress-plugin/university-results/university-results.php"

cp -a \
  "$URP_TMP/source/wordpress-plugin/university-results" \
  /var/www/html/wp-content/plugins/

rm -rf \
  "$URP_DIR/tests" \
  "$URP_DIR/composer.json" \
  "$URP_DIR/phpunit.xml.dist"

chown -R apache:apache "$URP_DIR"
find "$URP_DIR" -type d -exec chmod 755 {} \;
find "$URP_DIR" -type f -exec chmod 644 {} \;

rm -rf "$URP_TMP"

sudo -u apache wp plugin activate university-results \
  --path=/var/www/html

echo "University Results Portal plugin installed successfully."


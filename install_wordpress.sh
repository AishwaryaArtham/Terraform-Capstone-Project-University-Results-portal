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

# Allow Apache to honor WordPress rewrite rules in .htaccess.
sed -i '/<Directory "\/var\/www\/html">/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/httpd/conf/httpd.conf
apachectl configtest
systemctl restart httpd

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

# Configure friendly WordPress URLs and Apache rewrite rules.
cat > /var/www/html/.htaccess <<'HTACCESS'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %%{REQUEST_FILENAME} !-f
RewriteCond %%{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS
chown apache:apache /var/www/html/.htaccess
chmod 644 /var/www/html/.htaccess
sudo -u apache wp rewrite structure '/%postname%/' --path=/var/www/html
sudo -u apache wp rewrite flush --hard --path=/var/www/html

echo "WordPress friendly URLs configured."


echo "Installing University Results Portal plugin..."

URP_COMMIT="f807d15" # Commit containing initialize_access_codes.php
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

echo "Preparing University Results Portal demo data..."

install -d -m 755 /opt/university-results/sample-data

install -m 644 \
  "$URP_TMP/source/sample-data/students.csv" \
  /opt/university-results/sample-data/students.csv

install -m 644 \
  "$URP_TMP/source/sample-data/results.csv" \
  /opt/university-results/sample-data/results.csv

install -m 755 \
  "$URP_TMP/source/initialize_demo_data.sh" \
  /opt/university-results/initialize_demo_data.sh

# Keep access-code initialization script outside the public web root.
install -m 644 \
  "$URP_TMP/source/initialize_access_codes.php" \
  /opt/university-results/initialize_access_codes.php

echo "Demo initialization files prepared."

rm -rf "$URP_TMP"

sudo -u apache wp plugin activate university-results \
  --path=/var/www/html

echo "University Results Portal plugin installed successfully."

echo "Initializing University Results Portal demo data..."

/opt/university-results/initialize_demo_data.sh

echo "University Results Portal demo data initialized successfully."


# Initialize access codes only after student records have been imported.
# The PHP script stores codes in Secrets Manager and hashes in MySQL;
# it must never print plaintext access codes.
echo "Initializing student access codes..."
sudo -u apache wp eval-file \
  /opt/university-results/initialize_access_codes.php \
  --path=/var/www/html

echo "Student access codes initialized successfully."

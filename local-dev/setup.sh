#!/usr/bin/env bash
# One-command local setup for the University Results Portal plugin.
# Safe to run again: it skips anything already done.
#
#   1. starts WordPress + MySQL in Docker
#   2. installs WordPress (local admin from .env)
#   3. activates the plugin and creates the "Check your results" page
#   4. imports the synthetic sample data and publishes term 2026-S1
#   5. writes sample access codes to local-dev/access-codes.csv
#
# Local only. Uses no AWS resources or real credentials.
set -euo pipefail
cd "$(dirname "$0")"

if ! docker info >/dev/null 2>&1; then
  echo "Docker is not running. Open Docker Desktop, wait until it says 'running', then try again." >&2
  exit 1
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created local-dev/.env from .env.example (local-only throwaway passwords)."
fi
set -a; . ./.env; set +a
SITE_URL="http://localhost:${WORDPRESS_PORT:-8080}"

wp() { docker compose run --rm -T wpcli wp "$@"; }

echo "==> Starting containers (the first run downloads images and can take a few minutes)..."
docker compose up -d --wait

echo "==> Waiting for WordPress files..."
for _ in $(seq 1 60); do
  docker compose exec -T wordpress test -f /var/www/html/wp-config.php && break
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "==> Installing WordPress..."
  wp core install --url="$SITE_URL" --title="University Results Portal (local)" \
    --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" --skip-email
fi

echo "==> Activating plugin..."
wp plugin activate university-results

PAGE_ID=$(wp post list --post_type=page --name=results --post_status=publish --field=ID --format=ids)
if [ -z "$PAGE_ID" ]; then
  echo "==> Creating the results page..."
  PAGE_ID=$(wp post create --post_type=page --post_title="Check your results" --post_name=results \
    --post_status=publish --post_content="[urp_result_lookup]" --porcelain)
fi

echo "==> Importing sample data..."
wp urp import students /sample-data/students.csv
wp urp import results /sample-data/results.csv
wp urp publish 2026-S1

# Only students without a code get one, so re-running keeps existing codes valid.
# If the codes file was deleted, reissue all sample codes so you have working ones.
CODES_SCOPE=""
[ -f access-codes.csv ] || CODES_SCOPE="--all"
TMP_CODES=$(mktemp)
docker compose run --rm -T wpcli wp urp codes $CODES_SCOPE > "$TMP_CODES"
if [ "$(wc -l < "$TMP_CODES")" -gt 1 ]; then
  mv "$TMP_CODES" access-codes.csv
  echo "==> Wrote new sample access codes to local-dev/access-codes.csv"
else
  rm -f "$TMP_CODES"
fi

echo
wp urp status
cat <<EOF

Done! Open these in your browser:
  Website:        $SITE_URL/
  Results page:   $SITE_URL/?page_id=$PAGE_ID
  Admin login:    $SITE_URL/wp-admin/   (user: $WP_ADMIN_USER, password: see local-dev/.env)
  Sample codes:   local-dev/access-codes.csv
EOF

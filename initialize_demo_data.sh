
#!/usr/bin/env bash
set -euo pipefail

# Initialize synthetic University Results Portal demo data.
# Run only after WordPress and the plugin are installed.

WP_PATH="/var/www/html"
DATA_DIR="/opt/university-results/sample-data"

wp_urp() {
  sudo -u apache wp --path="$WP_PATH" "$@"
}

echo "Initializing University Results Portal demo data..."

# Ensure the plugin is active.
wp_urp plugin is-active university-results

# Import students before their examination results.
wp_urp urp import students "$DATA_DIR/students.csv"
wp_urp urp import results "$DATA_DIR/results.csv"

# Publish only the first semester.
wp_urp urp publish 2026-S1
wp_urp urp unpublish 2026-S2

# Create the Student Results page if it does not exist.
echo "Checking Student Results page..."

PAGE_ID=$(wp_urp post list \
  --post_type=page \
  --name=student-results \
  --post_status=any \
  --field=ID \
  --format=ids)

if [ -z "$PAGE_ID" ]; then
  echo "Creating Student Results page..."

  PAGE_ID=$(wp_urp post create \
    --post_type=page \
    --post_title="Student Results" \
    --post_name=student-results \
    --post_status=publish \
    --post_content="[urp_result_lookup]" \
    --porcelain)

  echo "Student Results page created (ID: $PAGE_ID)"
else
  echo "Student Results page already exists (ID: $PAGE_ID)"
fi

echo "Demo data initialization completed."
wp_urp urp status

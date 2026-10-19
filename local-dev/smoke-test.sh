#!/usr/bin/env bash
# End-to-end checks against the local Docker site. Run ./setup.sh first.
# Creates a throwaway student (SMOKE-0001) and removes it at the end.
set -uo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
SITE_URL="http://localhost:${WORDPRESS_PORT:-8080}"

PASS=0
FAIL=0
WORK=$(mktemp -d)
trap 'cleanup; rm -rf "$WORK"' EXIT

wp() { docker compose run --rm -T wpcli wp "$@" 2>/dev/null; }

check() { # check "description" command...
  if "${@:2}" >/dev/null 2>&1; then
    echo "  PASS  $1"; PASS=$((PASS + 1))
  else
    echo "  FAIL  $1"; FAIL=$((FAIL + 1))
  fi
}

page_has()     { grep -q -- "$1" "$WORK/page.html"; }
page_lacks()   { ! grep -q -- "$1" "$WORK/page.html"; }

# Loads the results page for a fresh nonce, then submits the lookup form.
lookup() {
  rm -f "$WORK/cookies"
  curl -s -c "$WORK/cookies" "$RESULTS_URL" > "$WORK/form.html"
  local nonce
  nonce=$(grep -o 'name="_urp_nonce" value="[^"]*"' "$WORK/form.html" | cut -d'"' -f4)
  curl -s -b "$WORK/cookies" -D "$WORK/headers.txt" -o "$WORK/page.html" \
    --data-urlencode "reg_no=$1" --data-urlencode "access_code=$2" \
    -d "urp_lookup=1&_urp_nonce=$nonce" "$RESULTS_URL"
}

cleanup() {
  wp eval '$s = URP_Repository::find_student_by_reg_no("SMOKE-0001"); if ($s) URP_Repository::delete_student((int) $s["id"]);' >/dev/null
  wp urp unpublish SMOKE-T1 >/dev/null
  wp transient delete --all >/dev/null
}

echo "University Results Portal smoke test"
cleanup

echo "Plugin"
check "plugin is active" wp plugin is-active university-results
check "both tables exist" wp eval 'global $wpdb; exit( 2 === count( $wpdb->get_col( "SHOW TABLES LIKE \"{$wpdb->prefix}urp_%\"" ) ) ? 0 : 1 );'

PAGE_ID=$(wp post list --post_type=page --name=results --post_status=publish --field=ID --format=ids)
RESULTS_URL="$SITE_URL/?page_id=$PAGE_ID"
check "results page exists" test -n "$PAGE_ID"

echo "Admin data via WP-CLI"
printf 'reg_no,full_name,programme\nSMOKE-0001,Smoke Test Student,QA\n' | docker compose run --rm -T wpcli wp urp import students - >/dev/null 2>&1
printf 'reg_no,term,subject_code,subject_name,marks,max_marks\nSMOKE-0001,SMOKE-T1,SMK101,Smoke Testing,77,100\n' \
  | docker compose run --rm -T wpcli wp urp import results - >/dev/null 2>&1
check "test student imported" wp eval 'exit( URP_Repository::find_student_by_reg_no("SMOKE-0001") ? 0 : 1 );'
check "invalid CSV is rejected" bash -c "! printf 'reg_no,full_name\n!!,\n' | docker compose run --rm -T wpcli wp urp import students - >/dev/null 2>&1"
CODE=$(wp urp codes --reg-no=SMOKE-0001 | tail -1 | cut -d, -f3)
check "access code issued" test -n "$CODE"

echo "Student lookup"
lookup SMOKE-0001 "$CODE"
check "term not visible before publishing" page_has "No results have been published for you yet"
check "lookup response is not cacheable" grep -qi "cache-control:.*no-store" "$WORK/headers.txt"

wp urp publish SMOKE-T1 >/dev/null
lookup smoke-0001 "$(echo "$CODE" | tr 'A-Z' 'a-z')"
check "correct code shows the student's results" page_has "Smoke Test Student"
check "subject, grade and pass shown" bash -c "grep -q SMK101 '$WORK/page.html' && grep -q '>B<' '$WORK/page.html' && grep -q '>Pass<' '$WORK/page.html'"
check "no other student's data on the page" page_lacks "UNI2026001"

lookup SMOKE-0001 "AAAAA-BBBBB"
check "wrong code shows the generic error" page_has "find results for that registration number"
check "wrong code reveals no student data" page_lacks "Smoke Test Student"
cp "$WORK/page.html" "$WORK/wrong-code.html"

lookup NOSUCH-0000 "$CODE"
check "unknown reg no shows the same generic error" page_has "find results for that registration number"

curl -s -o "$WORK/page.html" -d "urp_lookup=1&_urp_nonce=forged&reg_no=SMOKE-0001&access_code=$CODE" "$RESULTS_URL"
check "forged form is rejected" page_lacks "Smoke Test Student"

# 1 earlier wrong code + 4 here = 5 failures, the per-student limit.
for _ in 1 2 3 4; do lookup SMOKE-0001 "WRONG-CODE0"; done
lookup SMOKE-0001 "$CODE"
check "5 failures block further attempts" page_has "Too many unsuccessful attempts"

echo "Admin access"
curl -s -o /dev/null -w '%{http_code}' "$SITE_URL/wp-admin/admin.php?page=urp-students" > "$WORK/code"
check "admin screens require login" bash -c "grep -q 302 '$WORK/code'"

echo
echo "Result: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]

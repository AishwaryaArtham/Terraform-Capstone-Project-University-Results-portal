# Plugin operations on AWS (runbook)

> **Status:** The plugin is deployed **automatically** on every new EC2 instance by `install_wordpress.sh`,
> and that was verified on a fresh lab rebuild on 21 October 2026. This runbook covers the **manual** tasks
> you may still need on a running instance, over Session Manager.

How the automatic install works, and how to ship a change properly (pin `URP_COMMIT`, review the plan,
replace the instance), is in the developer guide: [§8 Boot sequence](developer-guide.md#8-boot-sequence-on-ec2-install_wordpresssh)
and [§15 Shipping a change to AWS](developer-guide.md#15-shipping-a-change-to-aws).

> ⚠️ **Manual changes are temporary.** Anything you change on the instance by hand is lost when the instance
> is replaced (a new `URP_COMMIT`, a new AMI, or a lab rebuild). Use these steps to test or fix quickly, then
> make the change permanent through Git and `install_wordpress.sh`.

## Open a shell on the instance

AWS console → **Systems Manager → Session Manager → Start session** → select `Terraform-WordPress-EC2`.
There is no SSH and no public IP. Then:

```bash
sudo -i
WP=/var/www/html
wpa() { sudo -u apache wp --path="$WP" "$@"; }   # run WP-CLI as the web server user, like the boot script
```

## Check the portal

```bash
tail -n 50 /var/log/wordpress-install.log   # did the boot finish? (last lines: "access codes initialized")
wpa plugin is-active university-results && echo active
wpa urp status                              # students, results, published terms
tail -n 50 /var/log/httpd/error_log         # PHP errors
```

Expected after a fresh boot: 6 students (0 without access code), 48 results, `2026-S1` PUBLISHED and
`2026-S2` not published, and the page `/student-results/`.

## Try a plugin change on the running instance (hotfix / test)

Use a pushed commit, so you can pin exactly the same commit afterwards.

```bash
COMMIT=<full-40-character-commit-sha>
REPO=AishwaryaArtham/Terraform-Capstone-Project-University-Results-portal
URP_DIR="$WP/wp-content/plugins/university-results"
TMP=$(mktemp -d)

curl -fL "https://codeload.github.com/$REPO/tar.gz/$COMMIT" -o "$TMP/src.tar.gz"
mkdir "$TMP/src" && tar -xzf "$TMP/src.tar.gz" -C "$TMP/src" --strip-components=1
test -f "$TMP/src/wordpress-plugin/university-results/university-results.php"

cp -a "$URP_DIR" "/root/university-results.backup.$(date +%Y%m%d%H%M%S)"   # for rollback
rm -rf "$URP_DIR" && cp -a "$TMP/src/wordpress-plugin/university-results" "$URP_DIR"
rm -rf "$URP_DIR/tests" "$URP_DIR/composer.json" "$URP_DIR/phpunit.xml.dist" "$TMP"
chown -R apache:apache "$URP_DIR"
find "$URP_DIR" -type d -exec chmod 755 {} \; && find "$URP_DIR" -type f -exec chmod 644 {} \;

wpa plugin activate university-results    # safe if already active; runs table upgrades when URP_DB_VERSION changed
wpa urp status
```

Then make it permanent: set `URP_COMMIT` in `install_wordpress.sh` to the same SHA and follow §15.

### Roll back

```bash
ls -d /root/university-results.backup.*                  # pick the backup to restore
rm -rf "$URP_DIR" && cp -a /root/university-results.backup.<timestamp> "$URP_DIR"
chown -R apache:apache "$URP_DIR"
```

To take the portal offline but **keep all data**: `wpa plugin deactivate university-results`.
Deleting the plugin in wp-admin also keeps the data unless `URP_DELETE_DATA_ON_UNINSTALL` is defined.

## Re-run the demo data or access-code setup

The boot script leaves both initializers on the instance:

```bash
/opt/university-results/initialize_demo_data.sh
wpa eval-file /opt/university-results/initialize_access_codes.php
```

- `initialize_demo_data.sh` re-imports the sample CSVs (upsert). **It resets the sample students' marks to the
  CSV values and unpublishes `2026-S2`.** Students and results added by an admin are kept.
- `initialize_access_codes.php` only gives codes to students **without** one, stores them in Secrets Manager
  (`terraform-wordpress-student-access-codes`) and never prints them. Codes issued in wp-admin or with
  `wp urp codes` are **not** written to Secrets Manager (developer guide §11).

## Trust the ALB's client IP (rate limiting)

Until the boot script sets it (developer guide Known issue 4), every visitor appears to come from the ALB, so the
per-IP limit of 20 failed lookups is shared by everyone. To fix it on the running instance:

```bash
wp config set URP_TRUST_PROXY_HEADER true --raw --path="$WP" --allow-root
wp config get URP_TRUST_PROXY_HEADER --path="$WP" --allow-root     # → 1
```

This is safe because the instance only accepts HTTP from the ALB security group (`security.tf`,
`wordpress_http`). It is lost when the instance is replaced; the permanent fix is adding the same command to
`install_wordpress.sh` after the plugin is activated.

## Verify after any change

- [ ] `wpa urp status` shows the expected counts and published terms.
- [ ] `http://<alb-dns>/student-results/`: a correct code shows only published terms; a wrong code and an
      unknown registration number show the same message.
- [ ] wp-admin shows **Results Portal**, and a non-admin user can't open it.
- [ ] No new PHP errors in `/var/log/httpd/error_log`.

Use synthetic data only: the ALB is HTTP, so codes and logins cross the internet unencrypted.

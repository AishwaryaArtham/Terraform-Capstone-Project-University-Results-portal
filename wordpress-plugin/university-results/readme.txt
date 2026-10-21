=== University Results Portal ===
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later

Administrators manage students and marks; students look up their own published results.

== Description ==

AWS Restart capstone plugin. Learning/demo use only — use synthetic data, and do not
use real student data until the site is served over HTTPS.

* Results Portal admin menu (capability: manage_urp_results, granted to administrators)
  - Students and Results: add, edit, search, delete
  - Import CSV: preview with per-row validation, then confirm
  - Publish & codes: publish results per term; issue access codes (stored as bcrypt hashes, shown once)
* [urp_result_lookup] shortcode: registration number + access code shows that student's
  results for published terms only. Same message for every failure; failed attempts are
  rate-limited per registration number (5 / 15 min) and per IP (20 / 15 min).
* WP-CLI: wp urp status | import | codes | publish | unpublish

Grades: A+ ≥90, A ≥80, B ≥70, C ≥60, D ≥50, E ≥40, F <40. Pass mark 40%.

== Configuration (wp-config.php) ==

* URP_TRUST_PROXY_HEADER (bool) — use the last X-Forwarded-For address as the client IP.
  Enable only behind a load balancer that the web server exclusively accepts traffic from.
* URP_DELETE_DATA_ON_UNINSTALL (bool) — drop the plugin's tables when it is deleted.
  Off by default, so deleting the plugin keeps the data.

== Development ==

See local-dev/README.md (Docker) and docs/plugin-deployment.md (AWS) in the repository.

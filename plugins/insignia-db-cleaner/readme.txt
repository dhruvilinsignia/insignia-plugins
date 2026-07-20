=== Insignia DB Cleaner ===
Contributors: insignia
Tags: database, cleaner, optimize, revisions, transients
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan and clean database clutter, then optimize your WordPress tables from one dashboard.

== Description ==

Insignia DB Cleaner scans your WordPress database for common sources of
clutter and lets you remove them in a couple of clicks:

* Post revisions
* Auto-drafts
* Trashed posts & pages
* Unapproved, spam and trashed comments
* Pingbacks and trackbacks
* Expired transients
* Orphaned post/comment/user/term meta
* Duplicate post/comment/user/term meta
* Cached oEmbed responses

It also lists every table in your database with its size and reclaimable
overhead, and lets you run `OPTIMIZE TABLE` individually or all at once.

An optional scheduled cleanup can run daily or weekly via WP-Cron for the
items you choose.

**Always take a database backup before running any bulk-delete tool.**

== Installation ==

1. Upload the `insignia-db-cleaner` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen.
3. Open *DB Cleaner* in the admin menu.

== Changelog ==

= 1.0.0 =
* Initial release.

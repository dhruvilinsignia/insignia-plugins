=== Insignia Backup ===
Contributors: insigniatechnolabs
Tags: backup, migration, restore, clone, database backup, scheduled backup, duplicator
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete site backup, migration and cloning toolkit for WordPress — full & granular backups, one-click restore, schedules, and a downloadable installer.

== Description ==

Insignia Backup is a Duplicator-style toolkit, rebuilt with a cleaner engine and a polished dashboard. Capture your entire site — database, files, and a self-contained installer — into a single portable archive, then redeploy it anywhere.

**Core features**

* **Full, Database-only, and Files-only backups** — choose exactly what to capture.
* **One-click restore** — roll a backup back onto the current site.
* **Downloadable installer package** — deploy an archive onto a brand-new/empty server with automatic, serialized-safe URL search-replace.
* **Scheduled automatic backups** — hourly, twice-daily, daily, weekly, or monthly via WP-Cron.
* **Smart retention** — keep the newest N backups; older archives auto-prune.
* **Exclude rules** — substring and wildcard patterns to skip caches, logs, node_modules, etc.
* **Adjustable compression** — trade speed for size (0–9).
* **Email notifications** — get pinged when a backup finishes.
* **Secure storage** — archives live in a hardened directory with unguessable filenames and direct-access denial.
* **Polished admin dashboard** — stat cards, tabbed workflow, progress feedback, and a guided migration flow.

Built to work on shared hosting: the database export and restore use `$wpdb` only — no shell/exec required. File packaging uses the native `ZipArchive` extension.

== Installation ==

1. Upload the `insignia-backup` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Backup** in the admin menu and build your first backup.

== Frequently Asked Questions ==

= Does this work on shared hosting? =
Yes. The database dump and import are handled entirely through WordPress' database layer, so no shell access is required. The ZipArchive PHP extension is needed for file packaging (available on nearly all hosts).

= How do I migrate to another server? =
Build a **Full** backup, download the archive, place it in an empty directory on the destination server alongside the extracted `installer.php`, then open `installer.php` in your browser and follow the three steps.

= Where are backups stored? =
Archives are stored **outside** the WordPress installation, in a unique subfolder of PHP's system temp directory (typically `/tmp/ibp-{hash}/`). They are NEVER written inside `wp-content` or anywhere under your site's `ABSPATH`. This prevents backups from being bundled into the next backup (unbounded growth) and keeps them out of any web-accessible path. The storage path is filterable via the `ibp_backup_dir` filter — but the plugin will hard-refuse any candidate path that lies inside `wp-content` or `ABSPATH`, and fall back to the system temp directory in that case. Archives are served for download through a nonce-protected AJAX endpoint, so there is no direct web URL to the files themselves.

== Changelog ==

= 1.0.0 =
* Release: version reset to 1.0.0 — single, clean baseline going forward.
* UX: all confirmation prompts now use the plugin's own modal popups instead of the browser's native `alert()` / `confirm()` dialogs. The Cancel-backup and Delete-backup prompts now match the dashboard's emerald/gold visual language, are keyboard-accessible (Esc to dismiss, Enter to confirm), and no longer trigger the browser's "prevent this page from creating additional dialogs" throttle.
* Reliability: hardened the WP-Cron chunked-backup engine. Each `ibp_run_backup_chunk` event now explicitly clears any stale sibling event for the same backup ID before scheduling the next chunk, so the WP-Cron 10-minute duplicate-prevention window can never stall a backup mid-run. Both `ibp_scheduled_backup` (recurring schedule) and `ibp_run_backup_chunk` (per-chunk) hooks are now cleaned up on uninstall.
* Confirmed working: scheduled (hourly / twice-daily / daily / weekly / monthly / custom) and manual backups, pause / resume / cancel, restore, downloadable installer with URL search-replace, exclude rules, retention, and email-on-complete.

= 2.1.2 =
* New: "Custom" automatic-backup frequency — set backups to repeat every N hours or days instead of the fixed presets.
* New: `wp-content/backup` is now always excluded from scans/archives (and always listed under Settings → Exclude Patterns), so a stray backup folder from another tool doesn't get bundled in or counted toward a backup's size.
* Removed the experimental pre-backup time estimate; the size estimate on each backup-type card remains.

= 2.1.1 =
* Tweak: collapsed the WP admin sidebar entry for "Backup" — the duplicate "Dashboard / Schedules / Settings" submenu items are no longer shown. The dashboard is a single-page app and those tabs are reachable from inside the page itself, so the sidebar noise was unnecessary.
* New: live ETA ("Estimated time remaining: ≈ 2m 30s · elapsed 45s") shown next to the progress bar whenever a backup is running. The estimate is computed from elapsed time vs. progress percent, and is hidden while the backup is paused so the timer doesn't tick down for the wrong reason.
* Hardening: the backup storage directory now has an explicit safety guard. The location is filterable via the new `ibp_backup_dir` filter, but the plugin will hard-refuse any candidate path that lies inside `wp-content` or anywhere under `ABSPATH`, falling back to the system temp directory and raising a `_doing_it_wrong()` notice. Backups are guaranteed to never land in `wp-content` (or any `backup` folder inside it).
* Docs: FAQ and uninstall routine updated to reflect that archives live in `/tmp/ibp-{hash}/`, not in `wp-content/ibp-backups`.

= 2.1.0 =
* New: each backup-type card (Full / Database / Files) now shows a live estimated size badge, calculated on page load and cached for 15 minutes.
* New: the Custom card shows the combined size of your current selection, updating as you pick files and folders.
* New: the file & folder picker shows the size of every file and folder in the tree, plus a running "Selected: N items · size" total in the popup footer.
* Improvement: folder-size scans are cached per path and skip unreadable directories gracefully.

= 1.2.0 =
* Change: the folder picker is now its own 4th backup-type card — "Custom" — sitting next to Full/Database/Files on the Backups tab, instead of a checkbox tucked under the other types.
* New: the picker now lists both files and folders (previously folders only), so you can tick an individual file (e.g. a single theme file) without grabbing its whole parent folder.

= 1.1.0 =
* New: "Only back up selected folders" option on the Backups tab. Clicking "Choose Folders…" opens your site's actual folder structure (lazily loaded, same folders your exclude patterns already respect) so you can tick just the folders you want, then confirm with OK — the resulting Full/Files backup only includes what you picked. Leave it unticked for the previous "everything" behavior.
* Confirmed/hardened: backups already continued running in the background (via a detached, ignore_user_abort() server request) if the browser tab was closed — this release carries the new folder selection through that same background path so it survives a closed tab too.

= 1.0.4 =
* Fix: backups now run in the background instead of on a single long AJAX request. Large-site backups no longer error out ("server error") when they run past the browser/proxy timeout, and completed backups appear in the list automatically instead of only after a manual page refresh.
* Fix: lowering "Keep newest backups" in Settings now prunes older archives and updates the "Stored Backups" count immediately, instead of waiting for the next backup to run.
* Fix: "Files Only" backups no longer bundle a dead-end installer.php (it always requires database.sql, which files-only backups don't produce). The installer now ships only with Full backups, as documented on the Migrate tab.
* Security fix: the standalone installer.php now locks itself after a successful deployment, so it can no longer be re-run to overwrite a live site's database credentials if it's accidentally left on the server.
* Fix: the installer's wp-config.php writer no longer corrupts database passwords that contain a "$" or backslash (a side effect of how preg_replace() reads its replacement text).
* Cleanup: removed a stray no-op call in the dashboard stats calculation.

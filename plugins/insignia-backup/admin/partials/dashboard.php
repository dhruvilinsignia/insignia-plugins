<?php
/**
 * Dashboard view (single-page, tabbed).
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

$ibp_settings  = IBP_Helpers::get_settings();
$ibp_schedule  = get_option( 'ibp_schedule', [ 'frequency' => 'off', 'type' => 'full' ] );
$ibp_scheduler = new IBP_Scheduler();
$ibp_ajax      = new IBP_Ajax( new IBP_Backup(), new IBP_Restore() );
$ibp_stats     = $ibp_ajax->collect_stats();
$ibp_rows      = $ibp_ajax->render_rows( IBP_Logger::all( 100, 0 ) );
$ibp_excludes  = implode( "\n", (array) $ibp_settings['exclude_patterns'] );
$ibp_zip_ok    = IBP_Helpers::has_zip_archive();

// Derive a friendly value+unit pair for the "Custom" frequency inputs from
// the stored seconds (defaults to "1 day" if nothing's been saved yet).
$ibp_custom_seconds = (int) ( $ibp_schedule['custom_seconds'] ?? DAY_IN_SECONDS );
if ( $ibp_custom_seconds > 0 && 0 === $ibp_custom_seconds % DAY_IN_SECONDS ) {
        $ibp_custom_value = (int) ( $ibp_custom_seconds / DAY_IN_SECONDS );
        $ibp_custom_unit  = 'days';
} else {
        $ibp_custom_value = max( 1, (int) round( $ibp_custom_seconds / HOUR_IN_SECONDS ) );
        $ibp_custom_unit  = 'hours';
}
?>
<div class="ibp-wrap">

        <!-- ===== Top bar ===== -->
        <header class="ibp-topbar">
                <div class="ibp-brand">
                        <span class="ibp-logo">
                                <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true">
                                        <path d="M16 2 4 7v8c0 7 5 12 12 15 7-3 12-8 12-15V7L16 2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                        <path d="M11 16l3.5 3.5L21 12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                        </span>
                        <div class="ibp-brand-txt">
                                <h1>Insignia Backup</h1>
                                <p>Backup &middot; Migrate &middot; Restore &middot; Clone</p>
                        </div>
                </div>
                <div class="ibp-topbar-right">
                        <?php if ( ! $ibp_zip_ok ) : ?>
                                <span class="ibp-env-warn" title="<?php esc_attr_e( 'ZipArchive missing', 'insignia-backup' ); ?>">&#9888; ZipArchive unavailable</span>
                        <?php else : ?>
                                <span class="ibp-env-ok">&#10003; Environment ready</span>
                        <?php endif; ?>
                        <span class="ibp-ver">v<?php echo esc_html( IBP_VERSION ); ?></span>
                        <span class="ibp-tz-badge" title="All times displayed in Indian Standard Time">IST</span>
                </div>
        </header>

        <!-- ===== Stat cards ===== -->
        <section class="ibp-stats">
                <div class="ibp-stat">
                        <div class="ibp-stat-ic ibp-ic-vault">&#9673;</div>
                        <div class="ibp-stat-meta">
                                <span class="ibp-stat-num" data-stat="total_backups"><?php echo esc_html( $ibp_stats['total_backups'] ); ?></span>
                                <span class="ibp-stat-lbl"><?php esc_html_e( 'Stored Backups', 'insignia-backup' ); ?></span>
                        </div>
                </div>
                <div class="ibp-stat">
                        <div class="ibp-stat-ic ibp-ic-disk">&#9783;</div>
                        <div class="ibp-stat-meta">
                                <span class="ibp-stat-num" data-stat="total_size"><?php echo esc_html( $ibp_stats['total_size'] ); ?></span>
                                <span class="ibp-stat-lbl"><?php esc_html_e( 'Archive Storage', 'insignia-backup' ); ?></span>
                        </div>
                </div>
                <div class="ibp-stat">
                        <div class="ibp-stat-ic ibp-ic-site">&#9635;</div>
                        <div class="ibp-stat-meta">
                                <span class="ibp-stat-num" data-stat="site_size"><?php echo esc_html( $ibp_stats['site_size'] ); ?></span>
                                <span class="ibp-stat-lbl"><?php esc_html_e( 'Live Site Size', 'insignia-backup' ); ?></span>
                        </div>
                </div>
                <div class="ibp-stat">
                        <div class="ibp-stat-ic ibp-ic-clock">&#9201;</div>
                        <div class="ibp-stat-meta">
                                <span class="ibp-stat-num" data-stat="last_backup"><?php echo esc_html( $ibp_stats['last_backup'] ); ?></span>
                                <span class="ibp-stat-lbl"><?php esc_html_e( 'Last Backup', 'insignia-backup' ); ?></span>
                        </div>
                </div>
        </section>

        <!-- ===== Tabs ===== -->
        <nav class="ibp-tabs" role="tablist">
                <button class="ibp-tab is-active" data-tab="backups"><?php esc_html_e( 'Backups', 'insignia-backup' ); ?></button>
                <button class="ibp-tab" data-tab="schedule"><?php esc_html_e( 'Schedule', 'insignia-backup' ); ?></button>
                <button class="ibp-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'insignia-backup' ); ?></button>
                <button class="ibp-tab" data-tab="tools"><?php esc_html_e( 'Migrate', 'insignia-backup' ); ?></button>
        </nav>

        <!-- ===== Toast ===== -->
        <div class="ibp-toast" id="ibp-toast" role="status" aria-live="polite"></div>

        <!-- ========================================================= -->
        <!--  TAB: Backups                                             -->
        <!-- ========================================================= -->
        <section class="ibp-panel is-active" data-panel="backups">

                <div class="ibp-builder">
                        <div class="ibp-builder-head">
                                <h2><?php esc_html_e( 'Create a new backup', 'insignia-backup' ); ?></h2>
                                <p><?php esc_html_e( 'Choose what to capture. A full backup produces your database + files archive plus a separate one-click installer download.', 'insignia-backup' ); ?></p>
                        </div>

                        <div class="ibp-type-grid">
                                <label class="ibp-type is-selected">
                                        <input type="radio" name="ibp_type" value="full" checked>
                                        <span class="ibp-type-card">
                                                <span class="ibp-type-ic">&#9673;</span>
                                                <strong><?php esc_html_e( 'Full Backup', 'insignia-backup' ); ?></strong>
                                                <small><?php esc_html_e( 'Database + all files, installer downloaded separately', 'insignia-backup' ); ?></small>
                                                                <span class="ibp-type-size" data-ibp-size="full"><?php esc_html_e( 'Calculating…', 'insignia-backup' ); ?></span>
                                        </span>
                                </label>
                                <label class="ibp-type">
                                        <input type="radio" name="ibp_type" value="database">
                                        <span class="ibp-type-card">
                                                <span class="ibp-type-ic">&#9636;</span>
                                                <strong><?php esc_html_e( 'Database Only', 'insignia-backup' ); ?></strong>
                                                <small><?php esc_html_e( 'Every table, fast & small', 'insignia-backup' ); ?></small>
                                                                <span class="ibp-type-size" data-ibp-size="database"><?php esc_html_e( 'Calculating…', 'insignia-backup' ); ?></span>
                                        </span>
                                </label>
                                <label class="ibp-type">
                                        <input type="radio" name="ibp_type" value="files">
                                        <span class="ibp-type-card">
                                                <span class="ibp-type-ic">&#9783;</span>
                                                <strong><?php esc_html_e( 'Files Only', 'insignia-backup' ); ?></strong>
                                                <small><?php esc_html_e( 'Themes, plugins, uploads', 'insignia-backup' ); ?></small>
                                                                <span class="ibp-type-size" data-ibp-size="files"><?php esc_html_e( 'Calculating…', 'insignia-backup' ); ?></span>
                                        </span>
                                </label>
                                <label class="ibp-type">
                                        <input type="radio" name="ibp_type" value="custom">
                                        <span class="ibp-type-card">
                                                <span class="ibp-type-ic">&#9881;</span>
                                                <strong><?php esc_html_e( 'Custom', 'insignia-backup' ); ?></strong>
                                                <small><?php esc_html_e( 'Pick exact files & folders', 'insignia-backup' ); ?></small>
                                                                <span class="ibp-type-size" data-ibp-size="custom"><?php esc_html_e( 'Nothing selected', 'insignia-backup' ); ?></span>
                                        </span>
                                </label>
                        </div>

                        <div class="ibp-folder-select" id="ibp-folder-select-row" hidden>
                                <button type="button" id="ibp-choose-folders" class="ibp-btn ibp-btn--ghost">
                                        <?php esc_html_e( 'Choose Files & Folders…', 'insignia-backup' ); ?>
                                </button>
                                <span class="ibp-folder-summary" id="ibp-folder-summary"></span>
                        </div>

                        <div class="ibp-builder-row">
                                <input type="text" id="ibp-backup-name" class="ibp-input" placeholder="<?php esc_attr_e( 'Optional label (e.g. Before plugin update)', 'insignia-backup' ); ?>">
                                <button id="ibp-create" class="ibp-btn ibp-btn--primary" <?php disabled( ! $ibp_zip_ok ); ?>>
                                        <span class="ibp-btn-ic">&#9889;</span>
                                        <?php esc_html_e( 'Build Backup', 'insignia-backup' ); ?>
                                </button>
                        </div>

                        <!-- Progress with determinate bar + pause/resume/cancel -->
                        <div class="ibp-progress" id="ibp-progress" hidden>
                                <div class="ibp-progress-header">
                                        <div class="ibp-progress-bar ibp-progress-bar--determinate"><span style="width:0%"></span></div>
                                        <span class="ibp-progress-pct" id="ibp-progress-pct">0%</span>
                                </div>
                                <p class="ibp-progress-txt" id="ibp-progress-txt"><?php esc_html_e( 'Preparing…', 'insignia-backup' ); ?></p>
                                <div class="ibp-progress-actions" id="ibp-progress-actions">
                                        <button type="button" id="ibp-pause-btn" class="ibp-btn ibp-btn--ghost ibp-btn--sm" hidden>
                                                <span>&#9208;</span> <?php esc_html_e( 'Pause', 'insignia-backup' ); ?>
                                        </button>
                                        <button type="button" id="ibp-resume-btn" class="ibp-btn ibp-btn--primary ibp-btn--sm" hidden>
                                                <span>&#9654;</span> <?php esc_html_e( 'Resume', 'insignia-backup' ); ?>
                                        </button>
                                        <button type="button" id="ibp-cancel-btn" class="ibp-btn ibp-btn--danger-ghost ibp-btn--sm" hidden>
                                                <?php esc_html_e( 'Cancel', 'insignia-backup' ); ?>
                                        </button>
                                </div>
                        </div>
                </div>

                <div class="ibp-list-head">
                        <h2><?php esc_html_e( 'Your backups', 'insignia-backup' ); ?></h2>
                        <button id="ibp-refresh" class="ibp-btn ibp-btn--ghost"><?php esc_html_e( 'Refresh', 'insignia-backup' ); ?></button>
                </div>

                <div class="ibp-table-wrap">
                        <table class="ibp-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'Backup', 'insignia-backup' ); ?></th>
                                                <th><?php esc_html_e( 'Type', 'insignia-backup' ); ?></th>
                                                <th><?php esc_html_e( 'Size', 'insignia-backup' ); ?></th>
                                                <th><?php esc_html_e( 'Status', 'insignia-backup' ); ?></th>
                                                <th><?php esc_html_e( 'Created', 'insignia-backup' ); ?></th>
                                                <th><?php esc_html_e( 'Actions', 'insignia-backup' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody id="ibp-rows">
                                        <?php echo $ibp_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </tbody>
                        </table>
                </div>
        </section>

        <!-- ========================================================= -->
        <!--  TAB: Schedule                                            -->
        <!-- ========================================================= -->
        <section class="ibp-panel" data-panel="schedule">
                <div class="ibp-card ibp-card--wide">
                        <div class="ibp-card-head">
                                <h2><?php esc_html_e( 'Automatic backups', 'insignia-backup' ); ?></h2>
                                <p class="ibp-next" id="ibp-next-run"><?php echo esc_html( $ibp_scheduler->next_run_label() ); ?></p>
                        </div>

                        <div class="ibp-field">
                                <label><?php esc_html_e( 'Frequency', 'insignia-backup' ); ?></label>
                                <div class="ibp-segment" id="ibp-freq">
                                        <?php
                                        $freqs = [
                                                'off'        => __( 'Off', 'insignia-backup' ),
                                                'hourly'     => __( 'Hourly', 'insignia-backup' ),
                                                'twicedaily' => __( 'Twice Daily', 'insignia-backup' ),
                                                'daily'      => __( 'Daily', 'insignia-backup' ),
                                                'weekly'     => __( 'Weekly', 'insignia-backup' ),
                                                'monthly'    => __( 'Monthly', 'insignia-backup' ),
                                                'custom'     => __( 'Custom', 'insignia-backup' ),
                                        ];
                                        foreach ( $freqs as $key => $label ) {
                                                $active = ( ( $ibp_schedule['frequency'] ?? 'off' ) === $key ) ? ' is-active' : '';
                                                printf(
                                                        '<button class="ibp-seg%s" data-freq="%s">%s</button>',
                                                        esc_attr( $active ),
                                                        esc_attr( $key ),
                                                        esc_html( $label )
                                                );
                                        }
                                        ?>
                                </div>
                        </div>

                        <div class="ibp-field" id="ibp-custom-freq-row" <?php echo ( 'custom' === ( $ibp_schedule['frequency'] ?? 'off' ) ) ? '' : 'hidden'; ?>>
                                <label><?php esc_html_e( 'Repeat every', 'insignia-backup' ); ?></label>
                                <div class="ibp-custom-freq">
                                        <input type="number" min="1" step="1" id="ibp-custom-value" class="ibp-input ibp-input--num" value="<?php echo esc_attr( $ibp_custom_value ); ?>">
                                        <select id="ibp-custom-unit" class="ibp-input">
                                                <option value="hours" <?php selected( $ibp_custom_unit, 'hours' ); ?>><?php esc_html_e( 'Hours', 'insignia-backup' ); ?></option>
                                                <option value="days" <?php selected( $ibp_custom_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'insignia-backup' ); ?></option>
                                        </select>
                                </div>
                        </div>

                        <div class="ibp-field">
                                <label><?php esc_html_e( 'What to back up', 'insignia-backup' ); ?></label>
                                <select id="ibp-sched-type" class="ibp-input">
                                        <option value="full" <?php selected( $ibp_schedule['type'] ?? 'full', 'full' ); ?>><?php esc_html_e( 'Full (database + files)', 'insignia-backup' ); ?></option>
                                        <option value="database" <?php selected( $ibp_schedule['type'] ?? 'full', 'database' ); ?>><?php esc_html_e( 'Database only', 'insignia-backup' ); ?></option>
                                        <option value="files" <?php selected( $ibp_schedule['type'] ?? 'full', 'files' ); ?>><?php esc_html_e( 'Files only', 'insignia-backup' ); ?></option>
                                </select>
                        </div>

                        <button id="ibp-save-schedule" class="ibp-btn ibp-btn--primary"><?php esc_html_e( 'Save Schedule', 'insignia-backup' ); ?></button>
                        <p class="ibp-hint"><?php esc_html_e( 'Automatic backups run via WP-Cron. For precise timing on low-traffic sites, wire a real server cron to wp-cron.php.', 'insignia-backup' ); ?></p>
                </div>
        </section>

        <!-- ========================================================= -->
        <!--  TAB: Settings                                            -->
        <!-- ========================================================= -->
        <section class="ibp-panel" data-panel="settings">
                <div class="ibp-card ibp-card--wide">
                        <div class="ibp-card-head"><h2><?php esc_html_e( 'Backup settings', 'insignia-backup' ); ?></h2></div>

                        <div class="ibp-grid-2">
                                <div class="ibp-field">
                                        <label><?php esc_html_e( 'Keep newest backups', 'insignia-backup' ); ?></label>
                                        <input type="number" min="0" id="ibp-set-retention" class="ibp-input" value="<?php echo esc_attr( $ibp_settings['max_local_backups'] ); ?>">
                                        <span class="ibp-hint"><?php esc_html_e( 'Older archives are auto-deleted. 0 = keep all.', 'insignia-backup' ); ?></span>
                                </div>

                                <div class="ibp-field">
                                        <label><?php esc_html_e( 'Compression level', 'insignia-backup' ); ?></label>
                                        <input type="range" min="0" max="9" id="ibp-set-compression" class="ibp-range" value="<?php echo esc_attr( $ibp_settings['compression_level'] ); ?>">
                                        <span class="ibp-hint"><?php esc_html_e( '0 = fastest, 9 = smallest. 6 is balanced.', 'insignia-backup' ); ?> <b id="ibp-comp-val"><?php echo esc_html( $ibp_settings['compression_level'] ); ?></b></span>
                                </div>

                                <div class="ibp-field">
                                        <label><?php esc_html_e( 'Notify email on completion', 'insignia-backup' ); ?></label>
                                        <input type="email" id="ibp-set-email" class="ibp-input" value="<?php echo esc_attr( $ibp_settings['email_on_complete'] ); ?>" placeholder="you@example.com">
                                </div>

                                <div class="ibp-field">
                                        <label><?php esc_html_e( 'Archive format', 'insignia-backup' ); ?></label>
                                        <select id="ibp-set-format" class="ibp-input">
                                                <option value="zip" <?php selected( $ibp_settings['archive_format'], 'zip' ); ?>>ZIP</option>
                                                <option value="gzip" <?php selected( $ibp_settings['archive_format'], 'gzip' ); ?>>GZIP (.tar.gz)</option>
                                        </select>
                                </div>
                        </div>

                        <div class="ibp-field">
                                <label><?php esc_html_e( 'Exclude patterns', 'insignia-backup' ); ?></label>
                                <textarea id="ibp-set-excludes" class="ibp-input ibp-textarea" rows="6" spellcheck="false"><?php echo esc_textarea( $ibp_excludes ); ?></textarea>
                                <span class="ibp-hint"><?php esc_html_e( 'One pattern per line. Substrings or * wildcards (e.g. *.log, wp-content/cache).', 'insignia-backup' ); ?></span>
                        </div>

                        <label class="ibp-check">
                                <input type="checkbox" id="ibp-set-charset" <?php checked( ! empty( $ibp_settings['db_charset_fix'] ) ); ?>>
                                <span><?php esc_html_e( 'Apply utf8mb4 charset fix on database export (recommended)', 'insignia-backup' ); ?></span>
                        </label>

                        <button id="ibp-save-settings" class="ibp-btn ibp-btn--primary"><?php esc_html_e( 'Save Settings', 'insignia-backup' ); ?></button>
                </div>
        </section>

        <!-- ========================================================= -->
        <!--  TAB: Migrate / Tools                                     -->
        <!-- ========================================================= -->
        <section class="ibp-panel" data-panel="tools">
                <div class="ibp-card ibp-card--wide">
                        <div class="ibp-card-head"><h2><?php esc_html_e( 'Migrate to another server', 'insignia-backup' ); ?></h2></div>
                        <ol class="ibp-steps">
                                <li><span class="ibp-step-n">1</span><div><strong><?php esc_html_e( 'Build a full backup', 'insignia-backup' ); ?></strong><p><?php esc_html_e( 'A full backup gives you two downloads: the site archive and installer.php.', 'insignia-backup' ); ?></p></div></li>
                                <li><span class="ibp-step-n">2</span><div><strong><?php esc_html_e( 'Download both files', 'insignia-backup' ); ?></strong><p><?php esc_html_e( 'From the Backups tab, download the .zip archive and the installer.php \u2014 they\u2019re two separate download buttons on the row.', 'insignia-backup' ); ?></p></div></li>
                                <li><span class="ibp-step-n">3</span><div><strong><?php esc_html_e( 'Upload to the new host', 'insignia-backup' ); ?></strong><p><?php esc_html_e( 'Place the archive and installer.php side by side in an empty directory on the destination server (don\u2019t extract the archive first).', 'insignia-backup' ); ?></p></div></li>
                                <li><span class="ibp-step-n">4</span><div><strong><?php esc_html_e( 'Run installer.php', 'insignia-backup' ); ?></strong><p><?php esc_html_e( 'Visit installer.php in your browser, enter the new database credentials, and deploy. Search-replace of URLs is handled automatically.', 'insignia-backup' ); ?></p></div></li>
                        </ol>
                        <div class="ibp-callout">
                                <strong>&#9888; <?php esc_html_e( 'Heads up', 'insignia-backup' ); ?></strong>
                                <?php esc_html_e( 'The in-place Restore button (Backups tab) rolls a backup back onto THIS site. Use the installer flow above to deploy onto a different/empty server.', 'insignia-backup' ); ?>
                        </div>
                </div>
        </section>

        <footer class="ibp-foot">
                <?php
                printf(
                        /* translators: %s: agency name. */
                        esc_html__( 'Insignia Backup — crafted by %s', 'insignia-backup' ),
                        '<a href="https://insigniatechnolabs.com" target="_blank" rel="noopener">Insignia Techno Labs</a>'
                );
                ?>
        </footer>
</div>

<!-- Restore modal -->
<div class="ibp-modal" id="ibp-restore-modal" hidden>
        <div class="ibp-modal-card">
                <h3><?php esc_html_e( 'Restore backup', 'insignia-backup' ); ?></h3>
                <p><?php esc_html_e( 'Choose what to restore onto this site. This overwrites current data.', 'insignia-backup' ); ?></p>
                <label class="ibp-check"><input type="checkbox" id="ibp-rs-db" checked><span><?php esc_html_e( 'Restore database', 'insignia-backup' ); ?></span></label>
                <label class="ibp-check"><input type="checkbox" id="ibp-rs-files"><span><?php esc_html_e( 'Restore files', 'insignia-backup' ); ?></span></label>
                <div class="ibp-modal-actions">
                        <button class="ibp-btn ibp-btn--ghost" id="ibp-rs-cancel"><?php esc_html_e( 'Cancel', 'insignia-backup' ); ?></button>
                        <button class="ibp-btn ibp-btn--danger" id="ibp-rs-confirm"><?php esc_html_e( 'Restore now', 'insignia-backup' ); ?></button>
                </div>
        </div>
</div>

<!-- Folder / file picker modal -->
<div class="ibp-modal" id="ibp-folder-modal" hidden>
        <div class="ibp-modal-card ibp-modal-card--wide">
                <h3><?php esc_html_e( 'Select files & folders to back up', 'insignia-backup' ); ?></h3>
                <p><?php esc_html_e( 'Browse your site\'s folder structure and check the files and/or folders to include. Checking a folder includes everything inside it. Anything left unchecked is skipped.', 'insignia-backup' ); ?></p>
                <div class="ibp-tree" id="ibp-folder-tree" data-root="1">
                        <p class="ibp-tree-msg"><?php esc_html_e( 'Loading…', 'insignia-backup' ); ?></p>
                </div>
                <div class="ibp-modal-actions">
                        <span class="ibp-modal-total" id="ibp-folder-total"></span>
                        <button class="ibp-btn ibp-btn--ghost" id="ibp-folder-cancel"><?php esc_html_e( 'Cancel', 'insignia-backup' ); ?></button>
                        <button class="ibp-btn ibp-btn--primary" id="ibp-folder-ok"><?php esc_html_e( 'OK', 'insignia-backup' ); ?></button>
                </div>
        </div>
</div>

<!-- Generic confirm dialog (replaces every window.confirm / window.alert) -->
<div class="ibp-modal" id="ibp-confirm-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ibp-confirm-title">
        <div class="ibp-modal-card ibp-modal-card--confirm">
                <div class="ibp-confirm-head">
                        <span class="ibp-confirm-ic" id="ibp-confirm-ic" aria-hidden="true">&#9888;</span>
                        <h3 id="ibp-confirm-title"><?php esc_html_e( 'Are you sure?', 'insignia-backup' ); ?></h3>
                </div>
                <p class="ibp-confirm-msg" id="ibp-confirm-msg"></p>
                <div class="ibp-modal-actions">
                        <button type="button" class="ibp-btn ibp-btn--ghost" id="ibp-confirm-cancel"><?php esc_html_e( 'Cancel', 'insignia-backup' ); ?></button>
                        <button type="button" class="ibp-btn ibp-btn--danger" id="ibp-confirm-ok"><?php esc_html_e( 'Confirm', 'insignia-backup' ); ?></button>
                </div>
        </div>
</div>
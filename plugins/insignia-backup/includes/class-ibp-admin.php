<?php
/**
 * Admin UI controller.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Admin {

        /**
         * Register the top-level admin menu only.
         *
         * The dashboard is a single-page app (Backups / Schedule / Settings /
         * Migrate tabs handled by in-page JS), so we intentionally do NOT
         * register any submenu pages. This keeps the WP admin sidebar clean:
         * clicking "Backup" lands you straight on the dashboard — no
         * extra "Dashboard / Schedules / Settings" children underneath.
         */
        public function register_menu() {
                $cap = 'manage_options';

                add_menu_page(
                        __( 'Insignia Backup', 'insignia-backup' ),
                        __( 'Backup', 'insignia-backup' ),
                        $cap,
                        IBP_SLUG,
                        [ $this, 'render_dashboard' ],
                        'dashicons-database-export',
                        76
                );

                /*
                 * WordPress auto-creates a duplicate first submenu whose label
                 * matches the top-level menu's title ("Insignia Backup").
                 * Rename it to "Backup" for tidiness — but it is still the
                 * SAME page as the parent, so the sidebar shows only one item.
                 */
                add_submenu_page(
                        IBP_SLUG,
                        __( 'Backup', 'insignia-backup' ),
                        __( 'Backup', 'insignia-backup' ),
                        $cap,
                        IBP_SLUG,
                        [ $this, 'render_dashboard' ]
                );

                /* Hide that auto-created first submenu so the sidebar truly
                   shows only the top-level "Backup" entry. */
                add_action( 'admin_head', [ $this, 'hide_first_submenu' ] );
        }

        /**
         * CSS-hide the duplicate first submenu item that WordPress always
         * creates under a top-level menu page. The page itself stays
         * registered (so admin.php?page=… still resolves), it just isn't
         * visible in the sidebar.
         */
        public function hide_first_submenu() {
                echo '<style>'
                        . '#adminmenu .wp-submenu li.wp-first-item { display: none !important; }'
                        . '</style>';
        }

        /**
         * Enqueue admin CSS/JS only on our page.
         *
         * @param string $hook Current admin page hook.
         */
        public function enqueue_assets( $hook ) {
                if ( false === strpos( $hook, IBP_SLUG ) ) {
                        return;
                }

                wp_enqueue_style(
                        'ibp-admin',
                        IBP_URL . 'admin/css/admin.css',
                        [],
                        IBP_VERSION
                );

                wp_enqueue_script(
                        'ibp-admin',
                        IBP_URL . 'admin/js/admin.js',
                        [ 'jquery' ],
                        IBP_VERSION,
                        true
                );

                $settings  = IBP_Helpers::get_settings();
                $schedule  = get_option( 'ibp_schedule', [ 'frequency' => 'off', 'type' => 'full' ] );
                $scheduler = new IBP_Scheduler();

                wp_localize_script(
                        'ibp-admin',
                        'IBP',
                        [
                                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                                'nonce'     => wp_create_nonce( IBP_Ajax::NONCE ),
                                'settings'  => $settings,
                                'schedule'  => $schedule,
                                'nextRun'   => $scheduler->next_run_label(),
                                'timezone'  => 'IST (Asia/Kolkata)',
                                'i18n'      => [
                                        'confirmDelete'  => __( 'Delete this backup permanently? This cannot be undone.', 'insignia-backup' ),
                                        'confirmRestore' => __( 'Restore this backup? Your current data will be overwritten.', 'insignia-backup' ),
                                        'working'        => __( 'Working…', 'insignia-backup' ),
                                        'creating'       => __( 'Building your backup…', 'insignia-backup' ),
                                ],
                        ]
                );
        }

        /**
         * Render the single-page dashboard.
         */
        public function render_dashboard() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }
                require IBP_PATH . 'admin/partials/dashboard.php';
        }

        /**
         * Add a Settings link on the Plugins screen.
         *
         * @param array $links Existing.
         * @return array
         */
        public function action_links( $links ) {
                $url  = admin_url( 'admin.php?page=' . IBP_SLUG );
                $link = '<a href="' . esc_url( $url ) . '"><strong style="color:#1a7f5a">' . esc_html__( 'Dashboard', 'insignia-backup' ) . '</strong></a>';
                array_unshift( $links, $link );
                return $links;
        }
}

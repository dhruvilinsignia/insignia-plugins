<?php
/**
 * Core orchestrator for Insignia Backup.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Core {

        /** @var IBP_Core|null Singleton instance. */
        private static $instance = null;

        /** @var IBP_Admin */
        public $admin;

        /** @var IBP_Backup */
        public $backup;

        /** @var IBP_Restore */
        public $restore;

        /** @var IBP_Scheduler */
        public $scheduler;

        /** @var IBP_Ajax */
        public $ajax;

        /**
         * Singleton accessor.
         *
         * @return IBP_Core
         */
        public static function get_instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Constructor — load deps and wire hooks.
         */
        private function __construct() {
                $this->load_dependencies();
                $this->init_components();
                $this->define_hooks();
        }

        /**
         * Require all class files.
         */
        private function load_dependencies() {
                require_once IBP_PATH . 'includes/class-ibp-helpers.php';
                require_once IBP_PATH . 'includes/class-ibp-logger.php';
                require_once IBP_PATH . 'includes/class-ibp-database.php';
                require_once IBP_PATH . 'includes/class-ibp-archiver.php';
                require_once IBP_PATH . 'includes/class-ibp-backup.php';
                require_once IBP_PATH . 'includes/class-ibp-restore.php';
                require_once IBP_PATH . 'includes/class-ibp-scheduler.php';
                require_once IBP_PATH . 'includes/class-ibp-ajax.php';

                if ( is_admin() ) {
                        require_once IBP_PATH . 'includes/class-ibp-admin.php';
                }
        }

        /**
         * Instantiate component objects.
         */
        private function init_components() {
                $this->backup    = new IBP_Backup();
                $this->restore   = new IBP_Restore();
                $this->scheduler = new IBP_Scheduler();
                $this->ajax      = new IBP_Ajax( $this->backup, $this->restore );

                if ( is_admin() ) {
                        $this->admin = new IBP_Admin();
                }
        }

        /**
         * Register WordPress hooks.
         */
        private function define_hooks() {
                add_action( 'init', [ $this, 'load_textdomain' ] );

                // Auto-migrate DB schema on every load (dbDelta is idempotent).
                add_action( 'admin_init', [ $this, 'maybe_migrate' ] );

                // Auto-cleanup old temp archives.
                add_action( 'admin_init', [ 'IBP_Helpers', 'cleanup_old_archives' ] );

                if ( is_admin() ) {
                        add_action( 'admin_menu', [ $this->admin, 'register_menu' ] );
                        add_action( 'admin_enqueue_scripts', [ $this->admin, 'enqueue_assets' ] );
                        add_filter( 'plugin_action_links_' . IBP_BASENAME, [ $this->admin, 'action_links' ] );
                }

                // AJAX endpoints.
                $this->ajax->register();

                // Cron hooks.
                $this->scheduler->register_hooks();

                // Chunked backup cron hook.
                add_action( 'ibp_run_backup_chunk', [ 'IBP_Helpers', 'run_backup_chunk' ], 10, 1 );
        }

        /**
         * Load translations.
         */
        public function load_textdomain() {
                load_plugin_textdomain( 'insignia-backup', false, dirname( IBP_BASENAME ) . '/languages' );
        }

        /**
         * Run schema migration if the DB version is outdated.
         * dbDelta handles adding new columns without data loss.
         */
        public function maybe_migrate() {
                $current = get_option( 'ibp_db_version', '0.0.0' );
                if ( version_compare( $current, IBP_VERSION, '<' ) ) {
                        IBP_Logger::create_table();
                        update_option( 'ibp_db_version', IBP_VERSION );
                }
        }

        /* --------------------------------------------------------------------
         *  Activation
         * ----------------------------------------------------------------- */

        /**
         * Activation routine.
         */
        public static function activate() {
                require_once IBP_PATH . 'includes/class-ibp-helpers.php';
                require_once IBP_PATH . 'includes/class-ibp-logger.php';

                IBP_Helpers::prepare_backup_directory();
                IBP_Logger::create_table();

                if ( false === get_option( 'ibp_settings' ) ) {
                        update_option( 'ibp_settings', self::default_settings() );
                }

                update_option( 'ibp_db_version', IBP_VERSION );
                flush_rewrite_rules();
        }

        /**
         * Default plugin settings.
         *
         * @return array
         */
        public static function default_settings() {
                return [
                        'archive_format'    => 'zip',
                        'split_size_mb'     => 0,
                        'exclude_patterns'  => [
                                'wp-content/cache',
                                'wp-content/ibp-backups',
                                'wp-content/backup',
                                'node_modules',
                                '.git',
                                '*.log',
                        ],
                        'max_local_backups' => 10,
                        'email_on_complete' => '',
                        'compression_level' => 6,
                        'db_charset_fix'    => true,
                ];
        }
}
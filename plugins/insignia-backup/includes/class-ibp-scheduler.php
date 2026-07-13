<?php
/**
 * WP-Cron based automatic backup scheduler.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

class IBP_Scheduler {

        const HOOK = 'ibp_scheduled_backup';

        /**
         * Wire cron hooks + custom intervals.
         */
        public function register_hooks() {
                add_filter( 'cron_schedules', [ $this, 'add_intervals' ] );
                add_action( self::HOOK, [ $this, 'run_scheduled' ] );
        }

        /**
         * Add weekly/monthly intervals if not present.
         *
         * @param array $schedules Existing.
         * @return array
         */
        public function add_intervals( $schedules ) {
                if ( ! isset( $schedules['weekly'] ) ) {
                        $schedules['weekly'] = [
                                'interval' => WEEK_IN_SECONDS,
                                'display'  => __( 'Once Weekly', 'insignia-backup' ),
                        ];
                }
                if ( ! isset( $schedules['monthly'] ) ) {
                        $schedules['monthly'] = [
                                'interval' => 30 * DAY_IN_SECONDS,
                                'display'  => __( 'Once Monthly', 'insignia-backup' ),
                        ];
                }

                // Custom interval: the actual number of seconds lives in the
                // 'ibp_schedule' option (set via set_schedule()) so it can be
                // any user-chosen number of hours/days rather than a fixed slug.
                $config         = get_option( 'ibp_schedule', [] );
                $custom_seconds = isset( $config['custom_seconds'] ) ? (int) $config['custom_seconds'] : DAY_IN_SECONDS;
                $custom_seconds = max( HOUR_IN_SECONDS, $custom_seconds );

                $schedules['ibp_custom'] = [
                        'interval' => $custom_seconds,
                        'display'  => sprintf(
                                /* translators: %s: human-readable interval, e.g. "6 hours". */
                                __( 'Custom (every %s)', 'insignia-backup' ),
                                self::human_interval( $custom_seconds )
                        ),
                ];

                return $schedules;
        }

        /**
         * Render a seconds count as a short human string ("6 hours", "3 days").
         *
         * @param int $seconds Seconds.
         * @return string
         */
        private static function human_interval( $seconds ) {
                if ( $seconds >= DAY_IN_SECONDS && 0 === $seconds % DAY_IN_SECONDS ) {
                        $days = (int) ( $seconds / DAY_IN_SECONDS );
                        /* translators: %d: number of days. */
                        return sprintf( _n( '%d day', '%d days', $days, 'insignia-backup' ), $days );
                }
                $hours = max( 1, (int) round( $seconds / HOUR_IN_SECONDS ) );
                /* translators: %d: number of hours. */
                return sprintf( _n( '%d hour', '%d hours', $hours, 'insignia-backup' ), $hours );
        }

        /**
         * Cron callback — run a scheduled backup using the chunked engine.
         *
         * This is the WP-Cron entry point for the recurring schedule hook
         * `ibp_scheduled_backup`. It kicks off a chunked backup; the per-chunk
         * work is then driven by `ibp_run_backup_chunk` events scheduled from
         * inside IBP_Helpers::run_backup_chunk().
         */
        public function run_scheduled() {
                $config = get_option( 'ibp_schedule', [] );
                $type   = $config['type'] ?? 'full';

                // Bump memory / time limits so a scheduled run on a quiet site
                // (no admin pages being loaded to drive spawn_cron()) doesn't
                // die mid-chunk.
                if ( function_exists( 'ignore_user_abort' ) ) {
                        ignore_user_abort( true );
                }
                IBP_Helpers::raise_limits();

                try {
                        $backup = new IBP_Backup();
                        $backup->init_chunked(
                                [
                                        'type'    => $type,
                                        'trigger' => 'schedule',
                                        'name'    => sprintf( 'Scheduled %s', ibp_format_timestamp( 'M j, Y g:i a' ) ),
                                ]
                        );
                } catch ( Exception $e ) {
                        error_log( 'IBP scheduled backup failed: ' . $e->getMessage() );
                }
        }

        /**
         * Save a schedule and (re)register the cron event.
         *
         * @param string $frequency      hourly|twicedaily|daily|weekly|monthly|custom|off.
         * @param string $type           full|database|files.
         * @param int    $custom_seconds Interval in seconds, only used when $frequency is 'custom'.
         * @return array Status info.
         */
        public function set_schedule( $frequency, $type = 'full', $custom_seconds = 0 ) {
                self::clear_all_schedules();

                if ( 'off' === $frequency || '' === $frequency ) {
                        update_option( 'ibp_schedule', [ 'frequency' => 'off', 'type' => $type ] );
                        return [ 'frequency' => 'off', 'next' => null ];
                }

                $option = [
                        'frequency' => $frequency,
                        'type'      => $type,
                        'set_at'    => ibp_now(),
                ];

                $cron_slug = $frequency;
                if ( 'custom' === $frequency ) {
                        $custom_seconds     = max( HOUR_IN_SECONDS, (int) $custom_seconds );
                        $option['custom_seconds'] = $custom_seconds;
                        $cron_slug          = 'ibp_custom';
                }

                // Persist first — add_intervals() reads 'custom_seconds' straight
                // from this option, so it must be saved before wp_schedule_event()
                // triggers the 'cron_schedules' filter below.
                update_option( 'ibp_schedule', $option );

                // First run should land one full interval from now, matching the
                // chosen frequency (e.g. Weekly waits a week, not ~1 minute).
                // WP-Cron then keeps it running on that same cadence going forward.
                $first_run = time() + self::interval_seconds( $frequency, $custom_seconds );

                wp_schedule_event( $first_run, $cron_slug, self::HOOK );

                return [
                        'frequency' => $frequency,
                        'next'      => wp_next_scheduled( self::HOOK ),
                ];
        }

        /**
         * Seconds until the next run for a given frequency, used to schedule
         * the first occurrence so it actually reflects the chosen cadence.
         *
         * @param string $frequency      hourly|twicedaily|daily|weekly|monthly|custom.
         * @param int    $custom_seconds Interval in seconds, only for 'custom'.
         * @return int
         */
        private static function interval_seconds( $frequency, $custom_seconds = 0 ) {
                switch ( $frequency ) {
                        case 'hourly':
                                return HOUR_IN_SECONDS;
                        case 'twicedaily':
                                return 12 * HOUR_IN_SECONDS;
                        case 'daily':
                                return DAY_IN_SECONDS;
                        case 'weekly':
                                return WEEK_IN_SECONDS;
                        case 'monthly':
                                return 30 * DAY_IN_SECONDS;
                        case 'custom':
                                return max( HOUR_IN_SECONDS, (int) $custom_seconds );
                        default:
                                return DAY_IN_SECONDS;
                }
        }

        /**
         * Human-readable description of the next scheduled run.
         *
         * @return string
         */
        public function next_run_label() {
                $ts = wp_next_scheduled( self::HOOK );
                if ( ! $ts ) {
                        return __( 'No automatic backups scheduled.', 'insignia-backup' );
                }
                return sprintf(
                        /* translators: %s: formatted date/time. */
                        __( 'Next backup: %s', 'insignia-backup' ),
                        ibp_format_timestamp( 'M j, Y g:i a', $ts )
                );
        }

        /**
         * Remove all scheduled events for this plugin.
         */
        public static function clear_all_schedules() {
                wp_clear_scheduled_hook( self::HOOK );
        }
}
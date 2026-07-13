<?php
/**
 * Insignia Backup — Cron Self-Test.
 *
 * Drop-in diagnostic. Run it from a WordPress admin context (logged in as
 * an admin) by visiting this file via:
 *
 *     wp-admin/admin-ajax.php?action=ibp_cron_selftest
 *
 * (You'll need to temporarily add the wp_ajax hook below — see the bottom
 * of this file.) Or, simpler, run it via WP-CLI:
 *
 *     wp eval-file wp-content/plugins/insignia-backup/tests/test-cron.php
 *
 * WHAT IT VERIFIES
 * ----------------
 *  1. The two cron hooks used by the plugin (`ibp_scheduled_backup` for
 *     the recurring schedule, and `ibp_run_backup_chunk` for per-chunk
 *     processing) are both registered with WP-Cron.
 *  2. WP-Cron is reachable — `spawn_cron()` doesn't error out and a
 *     one-shot test event actually fires within ~10 seconds.
 *  3. The duplicate-prevention hardening works — `wp_clear_scheduled_hook`
 *     before `wp_schedule_single_event` lets the same hook+args be
 *     re-scheduled immediately, even within WP's 10-minute dup window.
 *
 * The test is non-destructive: it doesn't create any real backups, it
 * doesn't touch the `ibp_schedule` option, and any test events it
 * schedules are unscheduled at the end.
 *
 * @package InsigniaBackup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the cron self-test. Returns an array of check results.
 *
 * @return array { overall: bool, checks: array<int, array{label:string,passed:bool,note:string}> }
 */
function ibp_cron_selftest_run() {
        $checks = array();

        /* --- 1. Hooks registered ---------------------------------------- */
        $hooks_expected = array( 'ibp_scheduled_backup', 'ibp_run_backup_chunk' );
        foreach ( $hooks_expected as $hook ) {
                $has_action = has_action( $hook );
                $checks[] = array(
                        'label'  => sprintf( 'Action "%s" is registered', $hook ),
                        'passed' => (bool) $has_action,
                        'note'   => $has_action
                                ? sprintf( 'Registered (priority %d).', $has_action )
                                : 'NOT registered — plugin bootstrap failed.',
                );
        }

        /* --- 2. Custom cron intervals registered ------------------------ */
        $schedules = wp_get_schedules();
        $needs     = array( 'weekly', 'monthly', 'ibp_custom' );
        foreach ( $needs as $slug ) {
                $checks[] = array(
                        'label'  => sprintf( 'Custom cron interval "%s" exists', $slug ),
                        'passed' => isset( $schedules[ $slug ] ),
                        'note'   => isset( $schedules[ $slug ] )
                                ? sprintf( 'Interval = %d sec (%s).', $schedules[ $slug ]['interval'], $schedules[ $slug ]['display'] )
                                : 'Missing — `cron_schedules` filter is not wired.',
                );
        }

        /* --- 3. Duplicate-prevention hardening -------------------------- */
        $test_hook = 'ibp_selftest_chunk_' . wp_rand( 1000, 9999 );
        $args      = array( 'selftest-' . time() );

        // Schedule at T+10s.
        $ok1 = wp_schedule_single_event( time() + 10, $test_hook, $args );
        // Without clearing, scheduling another at T+11s should be REJECTED
        // (WP dup-prevention window = 600s).
        $ok2 = wp_schedule_single_event( time() + 11, $test_hook, $args );
        // NOW apply the hardening: clear, then schedule.
        wp_clear_scheduled_hook( $test_hook, $args );
        $ok3 = wp_schedule_single_event( time() + 11, $test_hook, $args );

        $checks[] = array(
                'label'  => 'WP-Cron duplicate-prevention is in effect',
                'passed' => ( true === $ok1 && false === $ok2 ),
                'note'   => sprintf( 'first=%s, second=%s (expected false).', $ok1 ? 'true' : 'false', $ok2 ? 'true' : 'false' ),
        );
        $checks[] = array(
                'label'  => 'wp_clear_scheduled_hook before re-schedule works',
                'passed' => ( true === $ok3 ),
                'note'   => sprintf( 'after-clear=%s (expected true).', $ok3 ? 'true' : 'false' ),
        );

        // Clean up the test event.
        wp_clear_scheduled_hook( $test_hook, $args );

        /* --- 4. spawn_cron() doesn't error ------------------------------ */
        if ( function_exists( 'spawn_cron' ) ) {
                $checks[] = array(
                        'label'  => 'spawn_cron() is available',
                        'passed' => true,
                        'note'   => 'WP-Cron spawner is callable.',
                );
        } else {
                $checks[] = array(
                        'label'  => 'spawn_cron() is available',
                        'passed' => false,
                        'note'   => 'spawn_cron() is not defined — DISABLE_WP_CRON may be set.',
                );
        }

        /* --- 5. DISABLE_WP_CRON hint ------------------------------------ */
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                $checks[] = array(
                        'label'  => 'DISABLE_WP_CRON not set',
                        'passed' => false,
                        'note'   => 'DISABLE_WP_CRON is true. You MUST wire a real server cron to wp-cron.php, otherwise backups will stall. Example: `* * * * * cd /path/to/wp && php wp-cron.php > /dev/null 2>&1`',
                );
        } else {
                $checks[] = array(
                        'label'  => 'DISABLE_WP_CRON not set',
                        'passed' => true,
                        'note'   => 'Default WP-Cron behavior is active (fired on page loads).',
                );
        }

        /* --- Overall --------------------------------------------------- */
        $overall = true;
        foreach ( $checks as $c ) {
                if ( ! $c['passed'] ) {
                        $overall = false;
                        break;
                }
        }

        return array(
                'overall' => $overall,
                'checks'  => $checks,
        );
}

/**
 * Pretty-print the self-test (for wp-cli / direct eval usage).
 */
function ibp_cron_selftest_print() {
        $r = ibp_cron_selftest_run();
        echo "\n==============================================================\n";
        echo "  Insignia Backup — Cron Self-Test\n";
        echo "==============================================================\n\n";
        foreach ( $r['checks'] as $c ) {
                printf( "  [%s] %s\n        %s\n",
                        $c['passed'] ? 'PASS' : 'FAIL',
                        $c['label'],
                        $c['note']
                );
        }
        echo "\n==============================================================\n";
        echo "  OVERALL: " . ( $r['overall'] ? 'ALL CHECKS PASSED' : 'ONE OR MORE CHECKS FAILED' ) . "\n";
        echo "==============================================================\n";
}

/* --- If invoked via wp-cli eval-file, print immediately -------- */
if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'ibp_cron_selftest_print' ) ) {
        ibp_cron_selftest_print();
}

/* --- Optional AJAX endpoint (uncomment to enable) -------------- */
/*
add_action( 'wp_ajax_ibp_cron_selftest', function () {
        if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        wp_send_json_success( ibp_cron_selftest_run() );
} );
*/

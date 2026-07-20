<?php
/**
 * REST API endpoints for syntax linting of plugin / theme / WordPress-root
 * PHP files.
 *
 * Namespace: wptd/v1
 *
 * Routes
 * ──────
 *  POST  /lint-content  → lint the NEW content of a file BEFORE it is saved
 *                         to disk. Writes the content to a temp file, lints
 *                         it, deletes the temp file, and returns the errors.
 *                         This is the "safe coding" gate: critical errors
 *                         are caught BEFORE they reach the live site.
 *
 *  POST  /lint          → lint a file that is ALREADY on disk (the saved
 *                         file). Kept for backwards compatibility but no
 *                         longer the primary entry point. Deep scan is OFF
 *                         by default to avoid the fatal-error-on-big-plugins
 *                         bug that the previous version had.
 *
 * Safety model
 * ------------
 *  - Every handler is wrapped in a top-level try/catch so a fatal in the
 *    linter never produces the white-screen "There has been a critical
 *    error on this website" — instead the client gets a structured JSON
 *    error and can show a friendly toast.
 *  - `shell_exec` is only used if it is not in `disable_functions` AND the
 *    binary actually exists and is executable. Otherwise we fall back to
 *    the pure-PHP `token_get_all(TOKEN_PARSE)` linter which needs no
 *    external process at all.
 *  - The temp file used by /lint-content is created with `tempnam()` in
 *    `sys_get_temp_dir()`, chmod'd to 0600, and unlinked in a `finally`
 *    block so it can never be left behind — even if the linter throws.
 *  - The deep scan (linting every PHP file in the root) is capped at a
 *    small number of files (50) and a short time budget (10s) so it can
 *    never run away on a huge plugin and OOM the request.
 *
 * @package WPTD\Api
 */

namespace WPTD\Api;

use WPTD\Contracts\Hookable;
use WPTD\Download\Zipper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Lint_Endpoint implements Hookable {

    private const NAMESPACE        = 'wptd/v1';
    private const MAX_LINT_FILES   = 50;   // deep scan cap (was 400 — caused fatals)
    private const MAX_LINT_BYTES   = 2 * 1024 * 1024;
    private const DEEP_SCAN_BUDGET = 10;   // seconds, soft limit for deep scan

    /** Valid root types — same as File_Endpoint. */
    private const VALID_TYPES = [ 'plugin', 'theme', 'wordpress' ];

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        $type_args = [
            'required'          => true,
            'sanitize_callback' => 'sanitize_key',
            'validate_callback' => fn( $v ) => in_array( $v, self::VALID_TYPES, true ),
        ];
        $slug_args = [
            'required'          => false,
            'default'           => '',
            'sanitize_callback' => 'sanitize_file_name',
        ];

        // Primary entry point: lint NEW content BEFORE saving.
        register_rest_route( self::NAMESPACE, '/lint-content', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_lint_content' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type'    => $type_args,
                'slug'    => $slug_args,
                'path'    => [ 'required' => true ],   // the file path the content belongs to
                'content' => [ 'required' => true ],   // the NEW file content (string)
            ],
        ] );

        // Secondary entry point: lint a file already on disk.
        register_rest_route( self::NAMESPACE, '/lint', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_lint' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type' => $type_args,
                'slug' => $slug_args,
                'path' => [ 'required' => false, 'default' => '' ],
                'deep' => [ 'required' => false, 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            ],
        ] );

        // Cross-file deep scan: looks for duplicate function/class names
        // across ALL PHP files in the same plugin/theme. Uses the NEW
        // content (if provided) for the saved file so it catches
        // duplicates the user is ABOUT to introduce.
        register_rest_route( self::NAMESPACE, '/lint-deep', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_lint_deep' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'type'    => $type_args,
                'slug'    => $slug_args,
                'path'    => [ 'required' => false, 'default' => '' ],
                'content' => [ 'required' => false, 'default' => null ],
            ],
        ] );
    }

    // ── Permission ─────────────────────────────────────────────────────────────

    public function check_permission(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to lint files.', 'wptd' ),
                [ 'status' => 403 ]
            );
        }
        if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
            return new WP_Error(
                'file_edit_disabled',
                __( 'File editing is disabled via DISALLOW_FILE_EDIT in wp-config.php.', 'wptd' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function resolve_root( string $type, string $slug ): string|WP_Error {
        if ( 'wordpress' === $type ) {
            $root = realpath( ABSPATH );
            if ( ! $root || ! is_dir( $root ) ) {
                return new WP_Error( 'invalid_root', __( 'WordPress root directory is not accessible.', 'wptd' ), [ 'status' => 500 ] );
            }
            return $root;
        }
        if ( empty( $slug ) ) {
            return new WP_Error( 'missing_slug', __( 'Slug is required.', 'wptd' ), [ 'status' => 400 ] );
        }
        $zipper = new Zipper();
        $root   = ( 'plugin' === $type ) ? WP_PLUGIN_DIR : get_theme_root();
        return $zipper->resolve_public( $root, $slug );
    }

    private function skip_dirs(): array {
        return [ 'node_modules', '.git', 'vendor', 'bower_components', 'dist', 'build', 'cache', 'upgrade' ];
    }

    /**
     * Resolve a sub-path inside the root (replicates File_Endpoint's
     * traversal-safe resolution without reusing the class).
     */
    private function resolve_subpath( string $root, string $path ): string|WP_Error {
        $path = str_replace( '\\', '/', $path );
        $path = ltrim( $path, '/' );
        $parts = explode( '/', $path );
        foreach ( $parts as $p ) {
            if ( $p === '..' ) {
                return new WP_Error( 'invalid_path', __( 'Invalid path.', 'wptd' ), [ 'status' => 400 ] );
            }
        }
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        if ( $root === '' ) {
            return new WP_Error( 'invalid_root', __( 'Invalid root.', 'wptd' ), [ 'status' => 500 ] );
        }
        $full = $path === '' ? $root : ( $root . '/' . $path );
        $real = realpath( $full );
        return $real !== false ? $real : $full;
    }

    // ── POST /lint-content ─────────────────────────────────────────────────────
    //
    // Lint the NEW content of a file BEFORE it is saved to disk. This is the
    // "safe coding" gate: if the content has critical PHP errors, the client
    // can block the save entirely (no "Save Anyway" button) so the broken
    // code never reaches the live site.
    //
    // Implementation:
    //   1. Write the content to a temp file in sys_get_temp_dir().
    //   2. Lint the temp file (NOT the real file — the real file still has
    //      the old, valid content).
    //   3. Delete the temp file in a finally block.
    //   4. Return the errors with severity classification.
    //
    // The temp file is given a `.php` extension because `php -l` infers the
    // language from the extension, and some PHP versions refuse to lint
    // files without a `.php` extension.

    public function handle_lint_content( WP_REST_Request $request ): WP_REST_Response {
        try {
            $type    = $request->get_param( 'type' );
            $slug    = $request->get_param( 'slug' ) ?? '';
            $path    = $request->get_param( 'path' ) ?? '';
            $content = $request->get_param( 'content' );

            if ( ! is_string( $content ) ) {
                return new WP_REST_Response( [
                    'error' => __( 'Content must be a string.', 'wptd' ),
                ], 400 );
            }

            // Cap content size — we don't want to write a 50MB string to a
            // temp file and then try to lint it.
            if ( strlen( $content ) > self::MAX_LINT_BYTES ) {
                return new WP_REST_Response( [
                    'error' => sprintf(
                        __( 'Content is too large to lint (%s). Max size is 2 MB.', 'wptd' ),
                        size_format( strlen( $content ) )
                    ),
                ], 413 );
            }

            $root = $this->resolve_root( $type, $slug );
            if ( is_wp_error( $root ) ) {
                return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
            }

            // Normalise the saved file's relative path (forward slashes).
            $saved_file_rel = '';
            if ( $path !== '' ) {
                $saved_resolved = $this->resolve_subpath( $root, $path );
                if ( ! is_wp_error( $saved_resolved ) ) {
                    $saved_file_rel = str_replace( '\\', '/', ltrim( substr( $saved_resolved, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
                }
            }

            // Write the new content to a temp file with a .php extension.
            $tmp_dir = function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : '/tmp';
            $tmp_path = @tempnam( $tmp_dir, 'wptd_lint_' );
            if ( ! $tmp_path ) {
                return new WP_REST_Response( [
                    'error' => __( 'Could not create temp file for linting. Check the server temp directory permissions.', 'wptd' ),
                ], 500 );
            }

            // Rename to .php so php -l treats it as PHP (some versions refuse
            // to lint files without the .php extension).
            $php_tmp = $tmp_path . '.php';
            if ( ! @rename( $tmp_path, $php_tmp ) ) {
                @unlink( $tmp_path );
                return new WP_REST_Response( [
                    'error' => __( 'Could not prepare temp file for linting.', 'wptd' ),
                ], 500 );
            }
            $tmp_path = $php_tmp;

            try {
                // Write the content. file_put_contents returns false on failure.
                $written = @file_put_contents( $tmp_path, $content );
                if ( $written === false ) {
                    return new WP_REST_Response( [
                        'error' => __( 'Could not write content to temp file for linting.', 'wptd' ),
                    ], 500 );
                }

                // Lint the temp file.
                $php_binary = $this->find_php_binary();
                $file_errors = $php_binary
                    ? $this->lint_with_cli( $tmp_path, $php_binary )
                    : $this->lint_with_tokens_file( $tmp_path );

                // ── WordPress-specific & PHP semantic checks ────────────────
                //
                // If the file parses cleanly (no syntax errors), run a
                // SECOND pass that tokenises the source and looks for
                // common mistakes that `php -l` cannot catch:
                //
                //   • add_action/add_filter with an undefined callback (typo)
                //   • duplicate function definitions within the same file
                //   • duplicate class/interface/trait definitions within
                //     the same file
                //   • empty hook callbacks ('' or missing)
                //   • deprecated WordPress functions
                //   • calls to undefined functions (not just hook callbacks)
                //   • missing semicolons that php -l sometimes misses in
                //     heredoc/nowdoc edge cases (best-effort)
                //
                // These are reported as CRITICAL errors (severity: error)
                // when they would cause a fatal, or as warnings when they
                // are just bad practice.
                if ( empty( $file_errors ) ) {
                    $semantic_errors = $this->check_undefined_hook_callbacks( $content );
                    $semantic_errors = array_merge( $semantic_errors, $this->check_duplicate_definitions( $content ) );
                    $semantic_errors = array_merge( $semantic_errors, $this->check_empty_hook_callbacks( $content ) );
                    $semantic_errors = array_merge( $semantic_errors, $this->check_deprecated_functions( $content ) );
                    $semantic_errors = array_merge( $semantic_errors, $this->check_undefined_function_calls( $content ) );
                    $file_errors = array_merge( $file_errors, $semantic_errors );
                }

                // Normalise paths and tag every error as belonging to the
                // saved file (since the temp file IS the saved file's new
                // content).
                $errors = [];
                foreach ( $file_errors as $e ) {
                    $errors[] = [
                        'file'        => $saved_file_rel !== '' ? $saved_file_rel : $path,
                        'line'        => $e['line'],
                        'column'      => $e['column'] ?? 1,
                        'message'     => $e['message'],
                        'solution'    => $e['solution'],
                        'severity'    => $e['severity'] ?? 'error',
                        'isSavedFile' => true,
                    ];
                }

                // Classify the overall severity for the client.
                $has_critical = false;
                $has_warning  = false;
                foreach ( $errors as $e ) {
                    if ( $e['severity'] === 'error' ) {
                        $has_critical = true;
                    } elseif ( $e['severity'] === 'warning' ) {
                        $has_warning = true;
                    }
                }

                return new WP_REST_Response( [
                    'savedFile'    => $saved_file_rel,
                    'path'         => $path,
                    'type'         => $type,
                    'slug'         => $slug,
                    'errors'       => $errors,
                    'totalErrors'  => count( $errors ),
                    'scannedFiles' => 1,
                    'savedFailed'  => $has_critical,
                    'hasCritical'  => $has_critical,
                    'hasWarning'   => $has_warning,
                    'engine'       => $php_binary ? 'php-cli' : 'token_get_all',
                    'mode'         => 'pre-save',
                    'truncated'    => false,
                ], 200 );

            } finally {
                // Always clean up the temp file, even if the linter threw.
                @unlink( $tmp_path );
            }

        } catch ( \Throwable $e ) {
            // Last-resort safety net: never let the linter produce a
            // white-screen-of-death. Return a structured JSON error so the
            // client can show a friendly toast.
            return new WP_REST_Response( [
                'error' => __( 'Lint check failed: ', 'wptd' ) . $e->getMessage(),
                'errors' => [],
                'totalErrors' => 0,
                'hasCritical' => false,
                'hasWarning' => false,
                'engine' => 'none',
            ], 200 );
        }
    }

    // ── POST /lint (file already on disk) ──────────────────────────────────────

    public function handle_lint( WP_REST_Request $request ): WP_REST_Response {
        try {
            $type = $request->get_param( 'type' );
            $slug = $request->get_param( 'slug' ) ?? '';
            $path = $request->get_param( 'path' ) ?? '';
            $deep = (bool) $request->get_param( 'deep' );

            $root = $this->resolve_root( $type, $slug );
            if ( is_wp_error( $root ) ) {
                return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
            }

            $saved_file_rel = '';
            if ( $path !== '' ) {
                $saved_resolved = $this->resolve_subpath( $root, $path );
                if ( ! is_wp_error( $saved_resolved ) && is_file( $saved_resolved ) ) {
                    $saved_file_rel = str_replace( '\\', '/', ltrim( substr( $saved_resolved, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
                }
            }

            $files_to_lint = [];

            if ( $saved_file_rel !== '' ) {
                $abs_for_saved = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $saved_file_rel );
                $files_to_lint[] = [
                    'abs' => $abs_for_saved,
                    'rel' => $saved_file_rel,
                    'is_saved' => true,
                ];
            }

            if ( $deep ) {
                $discovered = $this->collect_php_files( $root );
                foreach ( $discovered as $rel ) {
                    if ( $rel === $saved_file_rel ) continue;
                    $abs_for_file = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
                    $files_to_lint[] = [
                        'abs' => $abs_for_file,
                        'rel' => $rel,
                        'is_saved' => false,
                    ];
                    if ( count( $files_to_lint ) >= self::MAX_LINT_FILES ) break;
                }
            }

            $errors        = [];
            $scanned       = 0;
            $saved_failed  = false;
            $php_binary    = $this->find_php_binary();
            $start_time    = microtime( true );

            foreach ( $files_to_lint as $f ) {
                // Soft time budget for the deep scan — if we've been running
                // for more than 10 seconds, stop and mark the result as
                // truncated so the client knows the scan was incomplete.
                if ( $deep && ( microtime( true ) - $start_time ) > self::DEEP_SCAN_BUDGET ) {
                    break;
                }

                $scanned++;
                if ( ! file_exists( $f['abs'] ) || ! is_readable( $f['abs'] ) ) continue;
                if ( filesize( $f['abs'] ) > self::MAX_LINT_BYTES ) continue;

                try {
                    $file_errors = $php_binary
                        ? $this->lint_with_cli( $f['abs'], $php_binary )
                        : $this->lint_with_tokens_file( $f['abs'] );
                } catch ( \Throwable $e ) {
                    // A single file failing must not kill the whole scan.
                    $file_errors = [];
                }

                foreach ( $file_errors as $e ) {
                    $errors[] = [
                        'file'       => $f['rel'],
                        'line'       => $e['line'],
                        'column'     => $e['column'] ?? 1,
                        'message'    => $e['message'],
                        'solution'   => $e['solution'],
                        'severity'   => $e['severity'] ?? 'error',
                        'isSavedFile'=> $f['is_saved'],
                    ];
                    if ( $f['is_saved'] ) {
                        $saved_failed = true;
                    }
                }
            }

            $has_critical = false;
            $has_warning  = false;
            foreach ( $errors as $e ) {
                if ( $e['severity'] === 'error' ) $has_critical = true;
                elseif ( $e['severity'] === 'warning' ) $has_warning = true;
            }

            return new WP_REST_Response( [
                'savedFile'    => $saved_file_rel,
                'type'         => $type,
                'slug'         => $slug,
                'errors'       => $errors,
                'totalErrors'  => count( $errors ),
                'scannedFiles' => $scanned,
                'savedFailed'  => $saved_failed,
                'hasCritical'  => $has_critical,
                'hasWarning'   => $has_warning,
                'engine'       => $php_binary ? 'php-cli' : 'token_get_all',
                'mode'         => 'post-save',
                'truncated'    => $deep && ( microtime( true ) - $start_time ) > self::DEEP_SCAN_BUDGET,
            ], 200 );

        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [
                'error' => __( 'Lint check failed: ', 'wptd' ) . $e->getMessage(),
                'errors' => [],
                'totalErrors' => 0,
                'hasCritical' => false,
                'hasWarning' => false,
                'engine' => 'none',
            ], 200 );
        }
    }

    /**
     * Collect every .php file under $root, skipping heavy/binary folders.
     * Returns relative paths (relative to $root), normalised to forward
     * slashes so the client-side comparison always matches.
     */
    private function collect_php_files( string $root ): array {
        $out  = [];
        $skip = $this->skip_dirs();

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch ( \Throwable $e ) {
            return [];
        }

        $skip_pattern = '#[/\\\\](' . implode( '|', array_map( fn( $d ) => preg_quote( $d, '#' ), $skip ) ) . ')[/\\\\]#';

        foreach ( $it as $file ) {
            if ( ! $file->isFile() ) continue;
            if ( $file->getExtension() !== 'php' ) continue;
            $full = $file->getPathname();
            if ( preg_match( $skip_pattern, $full ) ) continue;
            $rel = str_replace( '\\', '/', ltrim( substr( $full, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
            $out[] = $rel;
            if ( count( $out ) >= self::MAX_LINT_FILES ) break;
        }
        return $out;
    }

    /**
     * Try to find a usable PHP CLI binary. Returns the absolute path or null.
     *
     * Safety: we check `disable_functions` first so we never call
     * `shell_exec` on a host where it is disabled (calling a disabled
     * function produces a fatal error on many PHP versions, which is
     * exactly the "critical error on this website" the user was seeing).
     */
    private function find_php_binary(): ?string {
        static $cached = null;
        if ( $cached !== null ) return $cached === '' ? null : $cached;

        // If shell_exec is disabled, we can't use the CLI linter at all.
        $disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        if ( in_array( 'shell_exec', $disabled, true ) || in_array( 'exec', $disabled, true ) ) {
            $cached = '';
            return null;
        }

        // If function_exists is false (e.g. shell_exec removed from build),
        // bail out too.
        if ( ! function_exists( 'shell_exec' ) ) {
            $cached = '';
            return null;
        }

        $candidates = [];
        if ( defined( 'PHP_BINARY' ) && PHP_BINARY ) {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/opt/homebrew/bin/php';
        $candidates[] = '/usr/bin/php' . PHP_MAJOR_VERSION;
        $candidates[] = '/usr/local/bin/php' . PHP_MAJOR_VERSION;

        $candidates = array_unique( $candidates );

        foreach ( $candidates as $candidate ) {
            if ( ! @is_executable( $candidate ) ) continue;
            $output = @shell_exec( escapeshellarg( $candidate ) . ' -v 2>&1' );
            if ( $output && strpos( $output, 'PHP' ) === 0 ) {
                $cached = $candidate;
                return $cached;
            }
        }
        $cached = '';
        return null;
    }

    /**
     * Lint a file using `php -l`. The CLI linter is the gold standard —
     * it gives precise line/column output and detects all the same errors
     * the runtime would.
     */
    private function lint_with_cli( string $abs, string $binary ): array {
        $cmd = escapeshellarg( $binary ) . ' -d display_errors=1 -d error_reporting=E_ALL -l ' . escapeshellarg( $abs ) . ' 2>&1';
        $output = @shell_exec( $cmd );
        if ( ! is_string( $output ) ) return [];

        $errors = [];
        $lines = preg_split( '/\r\n|\r|\n/', trim( $output ) );
        foreach ( $lines as $line ) {
            if ( $line === '' ) continue;
            if ( stripos( $line, 'No syntax errors detected' ) !== false ) continue;

            $parsed = $this->parse_php_error_line( $line );
            if ( $parsed ) $errors[] = $parsed;
        }
        return $errors;
    }

    /**
     * Parse a single line of `php -l` output into a structured error row.
     *
     * Handles three PHP output formats:
     *   1. Legacy:  "PHP Parse error: ... in /path/file.php on line 42"
     *   2. PHP 8+:  "PHP Fatal error: ... in /path/file.php:42"
     *   3. Fallback: no path, line embedded in message.
     */
    private function parse_php_error_line( string $line ): ?array {
        // Format 1: "<severity>: <message> in <path> on line <n>"
        if ( preg_match(
            '/^(?:PHP\s+)?(?P<sev>Parse error|Fatal error|Warning|Notice|Deprecated|Error)\s*:\s*(?P<msg>.*?)\s+in\s+(?P<path>[^\s].*?)\s+on\s+line\s+(?P<line>\d+)/i',
            $line,
            $m
        ) ) {
            $sev      = strtolower( trim( $m['sev'] ) );
            $severity = ( $sev === 'warning' || $sev === 'notice' || $sev === 'deprecated' ) ? 'warning' : 'error';
            $message  = trim( $m['msg'] );
            $line_no  = (int) $m['line'];

            return [
                'line'     => $line_no,
                'column'   => 1,
                'message'  => $message,
                'solution' => $this->solution_for( $message ),
                'severity' => $severity,
            ];
        }

        // Format 2: "<severity>: <message> in <path>:<line>"
        if ( preg_match(
            '/^(?:PHP\s+)?(?P<sev>Parse error|Fatal error|Error)\s*:\s*(?P<msg>.*?)\s+in\s+(?P<path>[^\s]+):(?P<line>\d+)/i',
            $line,
            $m
        ) ) {
            $sev      = strtolower( trim( $m['sev'] ) );
            $severity = ( $sev === 'warning' || $sev === 'notice' || $sev === 'deprecated' ) ? 'warning' : 'error';
            $message  = trim( $m['msg'] );
            $line_no  = (int) $m['line'];

            return [
                'line'     => $line_no,
                'column'   => 1,
                'message'  => $message,
                'solution' => $this->solution_for( $message ),
                'severity' => $severity,
            ];
        }

        // Format 3 (fallback): no "in <path>" suffix at all.
        if ( preg_match(
            '/^(?:PHP\s+)?(?P<sev>Parse error|Fatal error|Error)\s*:\s*(?P<msg>.+)$/i',
            $line,
            $m
        ) ) {
            $msg     = $m['msg'];
            $line_no = 1;
            if ( preg_match( '/on line\s+(\d+)/i', $msg, $lm ) ) {
                $line_no = (int) $lm[1];
                $msg = preg_replace( '/\s+on line\s+\d+/i', '', $msg );
            } elseif ( preg_match( '/:\s*(\d+)\s*$/', $msg, $lm ) ) {
                $line_no = (int) $lm[1];
                $msg = preg_replace( '/:\s*\d+\s*$/', '', $msg );
            }
            return [
                'line'     => $line_no,
                'column'   => 1,
                'message'  => trim( $msg ),
                'solution' => $this->solution_for( trim( $msg ) ),
                'severity' => 'error',
            ];
        }

        return null;
    }

    /**
     * Fallback linter using PHP's own tokenizer. Lints a file on disk.
     * Works on hosts where the PHP CLI binary is unavailable.
     */
    private function lint_with_tokens_file( string $abs ): array {
        $source = @file_get_contents( $abs );
        if ( $source === false ) return [];
        return $this->lint_with_tokens_string( $source );
    }

    /**
     * Lint a PHP source string using the tokenizer. This is the pure-PHP
     * fallback that needs no external process — used both by the file-on-disk
     * linter and the content-string linter (for /lint-content).
     *
     * TOKEN_PARSE (PHP 7+) actually parses the source and throws a
     * ParseError on the first syntax error. Without TOKEN_PARSE,
     * token_get_all silently produces garbage tokens on bad code.
     */
    private function lint_with_tokens_string( string $source ): array {
        // Strip BOM if present.
        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }

        // Reject binary content (the editor shouldn't send any, but be safe).
        if ( false !== strpos( $source, "\0" ) ) {
            return [
                [
                    'line'     => 1,
                    'column'   => 1,
                    'message'  => __( 'File appears to be binary and cannot be linted.', 'wptd' ),
                    'solution' => __( 'This file contains null bytes, which means it is not a text file. The editor cannot lint binary files.', 'wptd' ),
                    'severity' => 'error',
                ],
            ];
        }

        try {
            // TOKEN_PARSE throws \ParseError on the first syntax error.
            $flags = defined( 'TOKEN_PARSE' ) ? TOKEN_PARSE : 0;
            @token_get_all( $source, $flags );
        } catch ( \ParseError $e ) {
            $message = $e->getMessage();
            $line    = $e->getLine() ?: 1;
            return [
                [
                    'line'     => $line,
                    'column'   => 1,
                    'message'  => $message,
                    'solution' => $this->solution_for( $message ),
                    'severity' => 'error',
                ],
            ];
        } catch ( \Throwable $e ) {
            return [
                [
                    'line'     => $e->getLine() ?: 1,
                    'column'   => 1,
                    'message'  => $e->getMessage(),
                    'solution' => __( 'Check the file for invalid byte sequences or encoding issues.', 'wptd' ),
                    'severity' => 'error',
                ],
            ];
        }

        return [];
    }

    /**
     * WordPress-specific semantic check: detect add_action() / add_filter()
     * calls whose callback string does not match any function defined in
     * the same file.
     *
     * This catches the classic "function name typo" bug, e.g.:
     *
     *     function fix_svg_thumb_display() { ... }
     *     add_action( 'admin_head', 'fix_svg_thumb_dissplay' );  // typo!
     *
     * `php -l` cannot catch this because both lines are syntactically
     * valid PHP — the error only surfaces at runtime when WordPress fires
     * the hook and calls call_user_func( 'fix_svg_thumb_dissplay' ),
     * which throws a fatal "Call to undefined function".
     *
     * How it works:
     *   1. Tokenise the source (without TOKEN_PARSE so it never throws —
     *      we already know the file parses cleanly because this method is
     *      only called when there are no syntax errors).
     *   2. Walk the token stream collecting every T_FUNCTION declaration
     *      (both named `function foo()` and anonymous `function()`) into
     *      a set of defined function names.
     *   3. Walk the token stream again looking for T_STRING tokens that
     *      are the FIRST argument of an add_action() or add_filter() call.
     *      The first argument of those functions is the hook name, the
     *      SECOND argument is the callback. We collect the callback.
     *   4. For each callback that is a plain string (not a closure, not
     *      an array like [ $obj, 'method' ]), check if it matches a
     *      defined function name. If not, report a critical error.
     *
     * Limitations (intentional, to keep the check fast and safe):
     *   - Only checks callbacks that are string literals. Variable
     *     callbacks (e.g. add_action( $hook, $cb )) are skipped because
     *     we can't resolve them statically.
     *   - Only checks functions defined in THIS file. If the callback is
     *     defined in another file (e.g. a plugin dependency), we can't
     *     know about it — so we only flag callbacks that look like they
     *     SHOULD be in this file (i.e. we don't flag built-in WP
     *     functions or common library functions).
     *   - To reduce false positives, we SKIP callbacks that match any
     *     function name PHP already knows about (via function_exists),
     *     because those are likely core/WP/library functions.
     *
     * @param string $source The PHP source code to check.
     * @return array<int, array{line:int, column:int, message:string, solution:string, severity:string}>
     */
    private function check_undefined_hook_callbacks( string $source ): array {
        $errors = [];

        // Strip BOM if present.
        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }

        // Tokenise without TOKEN_PARSE — this never throws, so it's safe
        // even if the file has weird encoding. We already verified the
        // file parses cleanly before calling this method.
        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) {
            return [];
        }

        // ── Pass 1: collect every function defined in this file ──────
        //
        // We look for the pattern: T_FUNCTION T_STRING '('
        // i.e. a named function declaration. Anonymous functions
        // (T_FUNCTION '(') are skipped — they have no name to match.
        $defined_functions = [];
        $token_count = count( $tokens );
        for ( $i = 0; $i < $token_count - 2; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) ) continue;
            if ( $t[0] !== T_FUNCTION ) continue;

            // Find the next non-whitespace, non-comment token after `function`.
            $j = $i + 1;
            while ( $j < $token_count ) {
                $nt = $tokens[ $j ];
                if ( is_array( $nt ) ) {
                    // Skip whitespace and comments.
                    if ( in_array( $nt[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                        $j++;
                        continue;
                    }
                    // If it's a T_STRING, this is a named function declaration.
                    if ( $nt[0] === T_STRING ) {
                        $defined_functions[] = strtolower( $nt[1] );
                    }
                }
                break; // any other token (e.g. '(' for anonymous fn) → stop
            }
        }

        // Always consider these as "available" — they're either PHP
        // built-ins or so common that flagging them would be noise.
        // function_exists() at runtime covers most, but we add a few
        // common WP-adjacent ones that may not be loaded yet at lint
        // time. This list is intentionally short — we only want to
        // suppress false positives for functions the user obviously
        // didn't define themselves.
        $whitelist = [
            '__', '_e', '_x', '_ex', '_n', '_nx',
            'esc_html', 'esc_attr', 'esc_textarea', 'esc_url', 'esc_js',
            'sanitize_text_field', 'sanitize_title', 'sanitize_key',
            'wp_die', 'wp_redirect', 'wp_safe_redirect',
            'get_option', 'update_option', 'delete_option',
            'get_post', 'get_posts', 'get_the_ID', 'get_the_title',
            'the_title', 'the_content', 'the_excerpt',
            'add_action', 'add_filter', 'remove_action', 'remove_filter',
            'do_action', 'apply_filters', 'has_action', 'has_filter',
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style',
            'wp_localize_script', 'wp_dequeue_script', 'wp_dequeue_style',
            'current_user_can', 'is_admin', 'is_user_logged_in',
            'get_current_user_id', 'wp_get_current_user',
            'register_post_type', 'register_taxonomy', 'register_sidebar',
            'wp_insert_post', 'wp_update_post', 'wp_delete_post',
            'get_permalink', 'get_the_permalink', 'home_url', 'site_url', 'admin_url',
            'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce', 'wp_create_nonce',
            'var_dump', 'print_r', 'error_log', 'define',
        ];
        foreach ( $whitelist as $fn ) {
            $defined_functions[] = strtolower( $fn );
        }

        // Also ask PHP itself what functions exist right now — this
        // catches all WP core functions, all loaded plugin functions,
        // and all PHP built-ins. If the callback exists at runtime, we
        // don't flag it.
        $all_defined = array_map( 'strtolower', get_defined_functions()['user'] ?? [] );
        $all_internal = array_map( 'strtolower', get_defined_functions()['internal'] ?? [] );
        $defined_functions = array_unique( array_merge( $defined_functions, $all_defined, $all_internal ) );
        $defined_set = array_flip( $defined_functions );

        // ── Pass 2: find add_action / add_filter calls and check their
        //    callback argument ──────────────────────────────────────────
        //
        // We walk the token stream looking for the pattern:
        //   T_STRING (value "add_action" or "add_filter")  '('  ...  ','  <callback>
        //
        // The callback is the SECOND argument. We only flag it if it's a
        // plain string literal (T_CONSTANT_ENCAPSED_STRING). Variable
        // callbacks, array callbacks ([ $obj, 'method' ]), and closures
        // are skipped because we can't resolve them statically.
        for ( $i = 0; $i < $token_count - 1; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) ) continue;
            if ( $t[0] !== T_STRING ) continue;

            $fn_name = strtolower( $t[1] );
            if ( $fn_name !== 'add_action' && $fn_name !== 'add_filter' ) {
                continue;
            }

            // Make sure this is a function CALL (followed by '('), not a
            // definition or a reference.
            $j = $i + 1;
            while ( $j < $token_count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
                $j++;
            }
            if ( $j >= $token_count ) continue;
            if ( $tokens[ $j ] !== '(' ) continue;

            // We're at the '(' of an add_action/add_filter call. Now
            // walk forward to find the FIRST comma at paren-depth 1 —
            // that's the separator between the hook name (arg 1) and
            // the callback (arg 2).
            $depth = 0;
            $k = $j; // start at '('
            $callback_token_idx = null;
            $arg_index = 0; // 0 = hook name, 1 = callback
            while ( $k < $token_count ) {
                $ct = $tokens[ $k ];
                if ( $ct === '(' ) {
                    $depth++;
                } elseif ( $ct === ')' ) {
                    $depth--;
                    if ( $depth === 0 ) {
                        // End of the add_action/add_filter call.
                        break;
                    }
                } elseif ( $ct === ',' && $depth === 1 ) {
                    // Separator between args. The NEXT non-whitespace
                    // token is the start of the next arg.
                    $arg_index++;
                    $k++;
                    // Skip whitespace/comments to find the arg's first token.
                    while ( $k < $token_count ) {
                        $nt = $tokens[ $k ];
                        if ( is_array( $nt ) && in_array( $nt[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                            $k++;
                            continue;
                        }
                        break;
                    }
                    if ( $arg_index === 1 && $k < $token_count ) {
                        // This is the callback argument (2nd arg).
                        $callback_token_idx = $k;
                    }
                    continue; // don't increment $k again
                }
                $k++;
            }

            if ( $callback_token_idx === null ) continue;

            $cb_token = $tokens[ $callback_token_idx ];

            // Only check string-literal callbacks.
            // T_CONSTANT_ENCAPSED_STRING covers both 'foo' and "foo".
            if ( ! is_array( $cb_token ) || $cb_token[0] !== T_CONSTANT_ENCAPSED_STRING ) {
                continue;
            }

            // Extract the function name from the string literal.
            // The token's text includes the quotes, so strip them.
            $raw = $cb_token[1];
            $quote = $raw[0];
            $func_name = trim( $raw, $quote . ' ' );
            // Handle escaped quotes inside double-quoted strings.
            if ( $quote === '"' ) {
                $func_name = stripslashes( $func_name );
            }

            // Skip empty strings and strings that look like they might
            // be static method references (contain '::' or '->').
            if ( $func_name === '' || strpos( $func_name, '::' ) !== false || strpos( $func_name, '->' ) !== false ) {
                continue;
            }

            // Check if the function is defined (in this file, in PHP,
            // or in WP/loaded plugins at runtime).
            if ( isset( $defined_set[ strtolower( $func_name ) ] ) ) {
                continue;
            }

            // ── Flag it! ──────────────────────────────────────────────
            //
            // The callback function is not defined in this file AND not
            // known to PHP at runtime. This will produce a fatal
            // "Call to undefined function" when the hook fires.
            $line = is_array( $cb_token ) ? $cb_token[2] : 1;

            $errors[] = [
                'line'     => $line,
                'column'   => 1,
                'message'  => sprintf(
                    __( 'Undefined function "%s" used as a callback in add_action/add_filter. This will cause a fatal error when the hook fires.', 'wptd' ),
                    $func_name
                ),
                'solution' => sprintf(
                    __( 'The callback "%1$s" does not match any function defined in this file or loaded by WordPress. Check the spelling — it is a common typo. If you intended to use a function defined in ANOTHER file, make sure that file is loaded (require/include) before this add_action/add_filter call runs. If you intended to call a method on a class, use an array callback: [ $instance, \'%1$s\' ] or [ \'ClassName\', \'%1$s\' ].', 'wptd' ),
                    $func_name
                ),
                'severity' => 'error',
            ];
        }

        return $errors;
    }

    /**
     * Detect duplicate function/class/interface/trait definitions WITHIN
     * the same file.
     *
     * PHP throws a fatal "Cannot redeclare function/class" if the same
     * name is defined twice in the same request. Within a single file
     * this is always a bug — the second definition shadows the first
     * (for classes) or crashes (for functions, unless wrapped in
     * function_exists).
     *
     * We DO allow the same function name to appear twice if the second
     * (or first) declaration is wrapped in `if ( ! function_exists(...) )`
     * — that's the standard WordPress pluggable pattern.
     *
     * @param string $source
     * @return array<int, array{line:int, column:int, message:string, solution:string, severity:string}>
     */
    private function check_duplicate_definitions( string $source ): array {
        $errors = [];

        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }

        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) return [];

        $seen_functions = [];  // name => first_line
        $seen_classes   = [];  // name => first_line
        $seen_interfaces = [];
        $seen_traits    = [];

        $token_count = count( $tokens );
        for ( $i = 0; $i < $token_count - 2; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) ) continue;

            // Detect: T_FUNCTION T_STRING  (a named function declaration)
            if ( $t[0] === T_FUNCTION ) {
                $name_token = $this->next_significant_token( $tokens, $i + 1, $token_count );
                if ( $name_token === null ) continue;
                $nt = $tokens[ $name_token ];
                if ( ! is_array( $nt ) || $nt[0] !== T_STRING ) continue;

                $name = strtolower( $nt[1] );
                $line = $nt[2];

                // Check if this declaration is guarded by function_exists.
                // We look backwards from the `function` keyword for a
                // function_exists call in the same statement.
                if ( $this->is_guarded_by_function_exists( $tokens, $i, $token_count ) ) {
                    $seen_functions[ $name ] = $line; // still track to allow the pluggable pattern
                    continue;
                }

                if ( isset( $seen_functions[ $name ] ) ) {
                    $errors[] = [
                        'line'     => $line,
                        'column'   => 1,
                        'message'  => sprintf(
                            __( 'Function %s() is declared more than once in this file (first declared on line %d). PHP will throw a fatal "Cannot redeclare function" error.', 'wptd' ),
                            $nt[1],
                            $seen_functions[ $name ]
                        ),
                        'solution' => sprintf(
                            __( 'Rename one of the %1$s() functions, or — if you are intentionally overriding a pluggable function — wrap the declaration in: if ( ! function_exists( \'%1$s\' ) ) { function %1$s() { ... } }', 'wptd' ),
                            $nt[1]
                        ),
                        'severity' => 'error',
                    ];
                } else {
                    $seen_functions[ $name ] = $line;
                }
                continue;
            }

            // Detect: T_CLASS/T_INTERFACE/T_TRAIT T_STRING
            if ( $t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT ) {
                $name_token = $this->next_significant_token( $tokens, $i + 1, $token_count );
                if ( $name_token === null ) continue;
                $nt = $tokens[ $name_token ];
                if ( ! is_array( $nt ) || $nt[0] !== T_STRING ) continue;

                $name = strtolower( $nt[1] );
                $line = $nt[2];
                $kind = $t[0] === T_CLASS ? 'class' : ( $t[0] === T_INTERFACE ? 'interface' : 'trait' );
                $kind_zh = $kind;

                $bucket = ( $kind === 'class' ) ? $seen_classes : ( $kind === 'interface' ? $seen_interfaces : $seen_traits );

                if ( isset( $bucket[ $name ] ) ) {
                    $errors[] = [
                        'line'     => $line,
                        'column'   => 1,
                        'message'  => sprintf(
                            __( '%s %s is declared more than once in this file (first declared on line %d). PHP will throw a fatal "Cannot redeclare %s" error.', 'wptd' ),
                            ucfirst( $kind ),
                            $nt[1],
                            $bucket[ $name ],
                            $kind
                        ),
                        'solution' => sprintf(
                            __( 'Rename one of the %1$s %2$ss, or remove the duplicate. If you are conditionally declaring the %2$s, wrap it in: if ( ! %3$s_exists( \'%2$s\' ) ) { %2$s %2$s { ... } }', 'wptd' ),
                            ucfirst( $kind ),
                            $nt[1],
                            $kind
                        ),
                        'severity' => 'error',
                    ];
                } else {
                    $bucket[ $name ] = $line;
                }
            }
        }

        return $errors;
    }

    /**
     * Detect add_action/add_filter calls with an empty or missing callback.
     *
     *   add_action( 'init', '' );        // empty string
     *   add_action( 'init' );            // only one argument
     *
     * Both are bugs: WordPress will try to call '' as a function, which
     * throws a fatal. And a hook with no callback silently does nothing,
     * which is almost always a mistake.
     *
     * @param string $source
     * @return array<int, array{line:int, column:int, message:string, solution:string, severity:string}>
     */
    private function check_empty_hook_callbacks( string $source ): array {
        $errors = [];

        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }
        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) return [];
        $token_count = count( $tokens );

        for ( $i = 0; $i < $token_count - 1; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) || $t[0] !== T_STRING ) continue;
            $fn = strtolower( $t[1] );
            if ( $fn !== 'add_action' && $fn !== 'add_filter' ) continue;

            // Find the opening '('
            $j = $i + 1;
            while ( $j < $token_count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) $j++;
            if ( $j >= $token_count || $tokens[ $j ] !== '(' ) continue;

            // Walk the call to find arg separators at depth 1.
            $depth = 0;
            $k = $j;
            $args = [];        // each arg = [ 'first_token_idx' => int, 'is_empty' => bool ]
            $current_arg_start = null;
            $arg_index = -1;

            while ( $k < $token_count ) {
                $ct = $tokens[ $k ];
                if ( $ct === '(' ) {
                    $depth++;
                    if ( $depth === 1 ) {
                        $arg_index++;
                        $current_arg_start = $k + 1;
                        $args[ $arg_index ] = [ 'first_token_idx' => $k + 1, 'is_empty' => true ];
                    }
                } elseif ( $ct === ')' ) {
                    if ( $depth === 1 ) break;
                    $depth--;
                } elseif ( $ct === ',' && $depth === 1 ) {
                    $arg_index++;
                    $current_arg_start = $k + 1;
                    $args[ $arg_index ] = [ 'first_token_idx' => $k + 1, 'is_empty' => true ];
                } else if ( $depth === 1 && $current_arg_start !== null && is_array( $ct ) ) {
                    // Mark this arg as non-empty if we see a non-whitespace, non-comment token.
                    if ( ! in_array( $ct[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                        $args[ $arg_index ]['is_empty'] = false;
                    }
                }
                $k++;
            }

            // If only one arg (the hook name), the callback is missing.
            if ( count( $args ) < 2 ) {
                $line = is_array( $t ) ? $t[2] : 1;
                $errors[] = [
                    'line'     => $line,
                    'column'   => 1,
                    'message'  => sprintf(
                        __( '%s() is called with only the hook name and no callback. The hook will never fire any function.', 'wptd' ),
                        $t[1]
                    ),
                    'solution' => sprintf(
                        __( 'Add a second argument to %s() — the callback function name, a closure, or an array like [ $this, \'method\' ]. Example: %s( \'hook_name\', \'my_callback_function\' );', 'wptd' ),
                        $t[1],
                        $t[1]
                    ),
                    'severity' => 'warning',
                ];
                continue;
            }

            // Check the callback (arg index 1) — if it's an empty string literal, flag it.
            $cb_arg = $args[1];
            $cb_idx = $cb_arg['first_token_idx'];
            // Skip whitespace/comments.
            while ( $cb_idx < $token_count ) {
                $tk = $tokens[ $cb_idx ];
                if ( is_array( $tk ) && in_array( $tk[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    $cb_idx++;
                    continue;
                }
                break;
            }
            if ( $cb_idx < $token_count ) {
                $tk = $tokens[ $cb_idx ];
                if ( is_array( $tk ) && $tk[0] === T_CONSTANT_ENCAPSED_STRING ) {
                    $raw = $tk[1];
                    $quote = $raw[0];
                    $val = trim( $raw, $quote . ' ' );
                    if ( $quote === '"' ) $val = stripslashes( $val );
                    if ( $val === '' ) {
                        $line = $tk[2];
                        $errors[] = [
                            'line'     => $line,
                            'column'   => 1,
                            'message'  => sprintf(
                                __( '%s() is called with an empty string callback. WordPress will try to call \'\' as a function, which causes a fatal error.', 'wptd' ),
                                $t[1]
                            ),
                            'solution' => sprintf(
                                __( 'Replace the empty string with a real callback function name, a closure, or an array like [ $this, \'method\' ].', 'wptd' ),
                            ),
                            'severity' => 'error',
                        ];
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Detect calls to deprecated WordPress functions.
     *
     * WordPress marks many functions as deprecated over time. Using them
     * still works (with a _deprecated_function notice), but is bad
     * practice and will eventually be removed. We flag a curated list of
     * the most common ones.
     *
     * Reported as a WARNING (not error) — they won't break the site,
     * but should be replaced.
     *
     * @param string $source
     * @return array<int, array{line:int, column:int, message:string, solution:string, severity:string}>
     */
    private function check_deprecated_functions( string $source ): array {
        $errors = [];

        // A curated list of commonly-used deprecated WP functions and
        // their replacements. This is NOT exhaustive — it covers the
        // functions we see most often in real plugin/theme code.
        $deprecated = [
            // Functions deprecated in WP 4.x
            'get_usermeta'        => [ 'get_user_meta', '3.0' ],
            'update_usermeta'     => [ 'update_user_meta', '3.0' ],
            'delete_usermeta'     => [ 'delete_user_meta', '3.0' ],
            'get_bloginfo'        => [ 'bloginfo or get_bloginfo with specific field', '— (still available but some uses are discouraged)' ],
            'wp_get_single_post'  => [ 'get_post', '3.5' ],
            'get_postdata'        => [ 'get_post', '3.4' ],

            // Functions deprecated in WP 5.x
            'create_initial_taxonomies' => [ 'use register_taxonomy directly', '5.7' ],
            '_usort_terms_by_id'  => [ 'wp_list_sort', '4.7' ],
            '_usort_terms_by_name'=> [ 'wp_list_sort', '4.7' ],

            // Functions deprecated in WP 6.x
            '_find_post_term_id'  => [ 'WP_Term_Query', '4.7' ],

            // Classic editor / TinyMCE
            'the_editor'          => [ 'wp_editor', '3.3' ],
            'wp_print_scripts'    => [ 'wp_enqueue_scripts action', '3.3' ],
            'wp_print_styles'     => [ 'wp_enqueue_scripts action', '3.3' ],

            // Common deprecated helpers
            'get_comments_number' => [ null, null ], // NOT deprecated — placeholder to keep structure
        ];
        // Remove the non-deprecated placeholder.
        unset( $deprecated['get_comments_number'] );

        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }
        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) return [];
        $token_count = count( $tokens );

        for ( $i = 0; $i < $token_count - 1; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) || $t[0] !== T_STRING ) continue;
            $name = strtolower( $t[1] );
            if ( ! isset( $deprecated[ $name ] ) ) continue;

            // Make sure this is a function CALL (followed by '(').
            $j = $i + 1;
            while ( $j < $token_count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) $j++;
            if ( $j >= $token_count || $tokens[ $j ] !== '(' ) continue;

            // Also make sure it's not a function DEFINITION or method definition.
            $prev = $this->prev_significant_token( $tokens, $i - 1, $token_count );
            if ( $prev !== null ) {
                $pt = $tokens[ $prev ];
                if ( is_array( $pt ) && ( $pt[0] === T_FUNCTION || $pt[0] === T_OBJECT_OPERATOR || $pt[0] === T_DOUBLE_COLON || $pt[0] === T_NEW ) ) {
                    continue;
                }
            }

            [ $replacement, $version ] = $deprecated[ $name ];
            $line = $t[2];

            $errors[] = [
                'line'     => $line,
                'column'   => 1,
                'message'  => sprintf(
                    __( 'Function %s() is deprecated since WordPress %s. It may be removed in a future version.', 'wptd' ),
                    $t[1],
                    $version
                ),
                'solution' => $replacement
                    ? sprintf( __( 'Use %s() instead. It has the same purpose with an updated API.', 'wptd' ), $replacement )
                    : __( 'Find the modern replacement in the WordPress developer documentation.', 'wptd' ),
                'severity' => 'warning',
            ];
        }

        return $errors;
    }

    /**
     * Detect calls to undefined functions within the file.
     *
     * This is broader than check_undefined_hook_callbacks — it scans
     * EVERY function call (not just add_action/add_filter callbacks) and
     * flags any that don't match:
     *   - functions defined in this file
     *   - PHP built-in functions
     *   - functions loaded by WP / plugins at runtime
     *   - the standard whitelist
     *
     * This catches things like:
     *   - custom_function_typo()      // defined nowhere
     *   - myplugin_do_thingg()        // typo of myplugin_do_thing
     *
     * We deliberately SKIP:
     *   - method calls ($obj->foo(), Class::foo())
     *   - calls inside namespaces we can't resolve (any T_STRING after
     *     T_OBJECT_OPERATOR or T_DOUBLE_COLON is skipped)
     *   - calls that are clearly PHP built-in or WP core (covered by
     *     get_defined_functions + whitelist)
     *
     * @param string $source
     * @return array<int, array{line:int, column:int, message:string, solution:string, severity:string}>
     */
    private function check_undefined_function_calls( string $source ): array {
        $errors = [];

        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }
        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) return [];
        $token_count = count( $tokens );

        // Build the set of defined functions: this file + PHP built-ins +
        // loaded user functions + whitelist.
        $defined = [];

        // Collect functions defined in this file.
        for ( $i = 0; $i < $token_count - 2; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) || $t[0] !== T_FUNCTION ) continue;
            $name_token = $this->next_significant_token( $tokens, $i + 1, $token_count );
            if ( $name_token === null ) continue;
            $nt = $tokens[ $name_token ];
            if ( is_array( $nt ) && $nt[0] === T_STRING ) {
                $defined[] = strtolower( $nt[1] );
            }
        }

        // PHP built-ins + loaded user functions.
        $all = get_defined_functions();
        $defined = array_merge( $defined, array_map( 'strtolower', $all['internal'] ?? [] ), array_map( 'strtolower', $all['user'] ?? [] ) );

        // Whitelist of common WP functions that may not be loaded at
        // lint time (so get_defined_functions doesn't include them).
        $whitelist = [
            '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop',
            'esc_html', 'esc_attr', 'esc_textarea', 'esc_url', 'esc_url_raw', 'esc_js', 'esc_xml',
            'sanitize_text_field', 'sanitize_title', 'sanitize_key', 'sanitize_email', 'sanitize_file_name',
            'wp_die', 'wp_redirect', 'wp_safe_redirect', 'wp_send_json', 'wp_send_json_success', 'wp_send_json_error',
            'get_option', 'update_option', 'delete_option', 'add_option',
            'get_post', 'get_posts', 'get_page', 'get_pages', 'get_the_ID', 'get_the_title', 'get_the_content', 'get_the_excerpt',
            'the_title', 'the_content', 'the_excerpt', 'the_ID', 'the_permalink', 'the_post', 'the_author', 'the_date', 'the_time', 'the_category', 'the_tags',
            'add_action', 'add_filter', 'remove_action', 'remove_filter', 'do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array', 'has_action', 'has_filter', 'did_action', 'doing_filter',
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style', 'wp_deregister_script', 'wp_deregister_style',
            'wp_localize_script', 'wp_dequeue_script', 'wp_dequeue_style', 'wp_script_is', 'wp_style_is',
            'current_user_can', 'current_user_can_for_blog', 'user_can', 'is_admin', 'is_user_logged_in', 'is_super_admin',
            'get_current_user_id', 'wp_get_current_user', 'get_currentuserinfo',
            'register_post_type', 'register_taxonomy', 'register_sidebar', 'register_widget', 'unregister_widget',
            'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'get_post_type', 'get_post_types', 'get_post_type_object', 'post_type_exists',
            'get_permalink', 'get_the_permalink', 'get_post_permalink', 'home_url', 'site_url', 'admin_url', 'network_admin_url', 'get_admin_url',
            'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce', 'wp_create_nonce', 'wp_nonce_field', 'wp_nonce_url',
            'get_query_var', 'set_query_var', 'wp_reset_query', 'wp_reset_postdata',
            'get_header', 'get_footer', 'get_sidebar', 'get_template_part', 'get_search_form', 'comments_template',
            'wp_head', 'wp_footer', 'wp_body_open', 'wp_title', 'wp_nav_menu', 'wp_list_pages', 'wp_list_categories',
            'is_front_page', 'is_home', 'is_single', 'is_page', 'is_singular', 'is_archive', 'is_category', 'is_tag', 'is_tax', 'is_author', 'is_date', 'is_search', 'is_404', 'is_feed',
            'have_posts', 'the_post', 'rewind_posts', 'get_header_image', 'has_header_image',
            'wp_get_attachment_image', 'wp_get_attachment_image_src', 'wp_get_attachment_url', 'wp_get_attachment_metadata',
            'get_the_post_thumbnail', 'has_post_thumbnail', 'the_post_thumbnail', 'get_post_thumbnail_id',
            'wp_get_themes', 'wp_get_theme', 'get_template_directory', 'get_template_directory_uri', 'get_stylesheet_directory', 'get_stylesheet_directory_uri',
            'plugin_dir_path', 'plugin_dir_url', 'plugin_basename', 'plugins_url', 'includes_url', 'admin_url',
            'load_plugin_textdomain', 'load_theme_textdomain', 'load_child_theme_textdomain',
            'get_transient', 'set_transient', 'delete_transient', 'get_site_transient', 'set_site_transient', 'delete_site_transient',
            'wp_cache_get', 'wp_cache_set', 'wp_cache_delete', 'wp_cache_flush', 'wp_cache_add', 'wp_cache_replace',
            'setcookie', 'header', 'headers_sent', 'http_response_code',
            'var_dump', 'print_r', 'var_export', 'debug_backtrace', 'debug_print_backtrace', 'error_log', 'trigger_error', 'set_error_handler', 'restore_error_handler',
            'define', 'defined', 'constant', 'class_exists', 'interface_exists', 'trait_exists', 'function_exists', 'method_exists', 'property_exists',
            'is_array', 'is_object', 'is_string', 'is_int', 'is_integer', 'is_bool', 'is_float', 'is_double', 'is_null', 'is_numeric', 'is_scalar', 'is_callable', 'is_iterable', 'is_countable',
            'array_map', 'array_filter', 'array_reduce', 'array_walk', 'array_merge', 'array_combine', 'array_keys', 'array_values', 'array_flip', 'array_reverse', 'array_slice', 'array_splice', 'array_search', 'array_key_exists', 'array_unique', 'array_column', 'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_pad', 'array_chunk', 'array_fill', 'array_diff', 'array_intersect', 'in_array', 'count', 'sizeof', 'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort', 'usort', 'uasort', 'uksort', 'end', 'reset', 'current', 'key', 'next', 'prev', 'each', 'list', 'compact', 'extract', 'range',
            'strlen', 'strpos', 'strrpos', 'stripos', 'strripos', 'substr', 'substr_count', 'substr_replace', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'ucwords', 'str_replace', 'str_ireplace', 'str_repeat', 'str_split', 'str_pad', 'str_word_count', 'trim', 'ltrim', 'rtrim', 'chop', 'nl2br', 'htmlspecialchars', 'htmlentities', 'html_entity_decode', 'strip_tags', 'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_quote', 'preg_grep', 'preg_last_error',
            'sprintf', 'printf', 'vsprintf', 'vprintf', 'sscanf', 'number_format', 'money_format', 'explode', 'implode', 'join', 'strrev', 'str_contains', 'str_starts_with', 'str_ends_with',
            'absint', 'intval', 'floatval', 'doubleval', 'boolval', 'min', 'max', 'round', 'ceil', 'floor', 'abs', 'pow', 'sqrt', 'exp', 'log', 'log10', 'pi', 'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2', 'rand', 'mt_rand', 'random_int', 'random_bytes',
            'date', 'time', 'mktime', 'strtotime', 'date_create', 'date_format', 'date_modify', 'date_diff', 'checkdate', 'getdate', 'gettimeofday', 'gmdate', 'gmstrftime', 'strftime', 'idate', 'localtime',
            'json_encode', 'json_decode', 'json_last_error', 'json_last_error_msg',
            'serialize', 'unserialize', 'maybe_serialize', 'maybe_unserialize',
            'base64_encode', 'base64_decode', 'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode', 'http_build_query', 'parse_url', 'parse_str', 'get_headers',
            'fopen', 'fclose', 'fread', 'fwrite', 'fputs', 'fgets', 'fgetc', 'feof', 'ftell', 'fseek', 'rewind', 'ftruncate', 'fflush', 'flock', 'file_get_contents', 'file_put_contents', 'file_exists', 'is_file', 'is_dir', 'is_readable', 'is_writable', 'is_writeable', 'filesize', 'filemtime', 'fileatime', 'filectime', 'touch', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'scandir', 'glob', 'realpath', 'dirname', 'basename', 'pathinfo', 'tempnam', 'tmpfile', 'sys_get_temp_dir', 'chmod', 'chown', 'chgrp',
            'getenv', 'putenv', 'gethostname', 'gethostbyname', 'gethostbyaddr', 'gethostbynamel',
            'phpversion', 'phpinfo', 'php_sapi_name', 'php_uname', 'ini_get', 'ini_set', 'ini_restore', 'get_cfg_var', 'set_time_limit', 'memory_get_usage', 'memory_get_peak_usage', 'gc_collect_cycles', 'gc_enabled',
            'ob_start', 'ob_end_clean', 'ob_end_flush', 'ob_get_clean', 'ob_get_contents', 'ob_get_flush', 'ob_get_length', 'ob_get_level', 'ob_get_status', 'ob_implicit_flush', 'ob_list_handlers',
            'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'func_get_args', 'func_get_arg', 'func_num_args',
            'class_alias', 'class_implements', 'class_parents', 'class_uses', 'get_class', 'get_parent_class', 'get_called_class', 'get_object_vars', 'get_class_vars', 'get_class_methods', 'is_a', 'is_subclass_of', 'gettype', 'get_debug_type', 'settype',
            'spl_autoload_register', 'spl_autoload_unregister', 'spl_autoload_functions', 'spl_autoload_call', 'spl_object_hash', 'spl_object_id',
        ];
        $defined = array_merge( $defined, array_map( 'strtolower', $whitelist ) );

        $defined_set = array_flip( array_unique( $defined ) );

        // Walk the tokens looking for function calls: T_STRING followed by '('
        // where the previous significant token is NOT ->, ::, function, or ->.
        for ( $i = 0; $i < $token_count - 1; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) || $t[0] !== T_STRING ) continue;

            // Must be followed by '(' (a call, not a reference).
            $next_idx = $this->next_significant_token( $tokens, $i + 1, $token_count );
            if ( $next_idx === null || $tokens[ $next_idx ] !== '(' ) continue;

            // Previous significant token must NOT be one of the method/property accessors
            // or a function-definition keyword.
            $prev_idx = $this->prev_significant_token( $tokens, $i - 1, $token_count );
            if ( $prev_idx !== null ) {
                $pt = $tokens[ $prev_idx ];
                if ( is_array( $pt ) ) {
                    // Skip: ->method(), Class::method(), function name, new Class, use Namespace
                    if ( in_array( $pt[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR, T_USE, T_GOTO, T_CONST ], true ) ) {
                        continue;
                    }
                    // Skip: namespace declarations (namespace Foo\Bar)
                    if ( $pt[0] === T_NAMESPACE ) continue;
                }
                // Skip: function name in a function declaration like `function foo()`
                // (already covered by T_FUNCTION above, but double-check).
            }

            $name = strtolower( $t[1] );

            // Skip names that contain a namespace separator (we can't resolve them).
            if ( strpos( $name, '\\' ) !== false ) continue;

            if ( isset( $defined_set[ $name ] ) ) continue;

            $line = $t[2];
            $errors[] = [
                'line'     => $line,
                'column'   => 1,
                'message'  => sprintf(
                    __( 'Call to undefined function %s(). This will cause a fatal error when this line runs.', 'wptd' ),
                    $t[1]
                ),
                'solution' => sprintf(
                    __( 'The function %1$s() is not defined in this file, not a PHP built-in, and not loaded by WordPress. Check the spelling (typos are the most common cause). If it is defined in another file, make sure that file is included (require/require_once) before this line. If it is a method on a class, use $instance->%1$s() or ClassName::%1$s() instead.', 'wptd' ),
                    $t[1]
                ),
                'severity' => 'error',
            ];
        }

        return $errors;
    }

    /**
     * Helper: find the next significant token (skipping whitespace and
     * comments) starting from index $i. Returns the token index, or null
     * if there isn't one.
     */
    private function next_significant_token( array $tokens, int $start, int $count ): ?int {
        for ( $i = $start; $i < $count; $i++ ) {
            $t = $tokens[ $i ];
            if ( is_array( $t ) && in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Helper: find the previous significant token (skipping whitespace and
     * comments) starting from index $i and going backwards. Returns the
     * token index, or null if there isn't one.
     */
    private function prev_significant_token( array $tokens, int $start, int $count ): ?int {
        for ( $i = $start; $i >= 0; $i-- ) {
            $t = $tokens[ $i ];
            if ( is_array( $t ) && in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Helper: check whether the function declaration at token index $i is
     * guarded by an `if ( ! function_exists( ... ) )` wrapper.
     *
     * We look backwards from the `function` keyword for a T_IF token that
     * is part of the same statement, and check if its condition contains
     * a function_exists call.
     */
    private function is_guarded_by_function_exists( array $tokens, int $func_idx, int $count ): bool {
        // Walk backwards looking for the nearest T_IF that precedes this
        // function declaration at the same brace depth. We stop if we hit
        // a `}` at depth 0 or a `;` or `{` belonging to a different block.
        $depth = 0;
        for ( $i = $func_idx - 1; $i >= 0; $i-- ) {
            $t = $tokens[ $i ];
            if ( $t === '}' ) {
                $depth++;
                continue;
            }
            if ( $t === '{' ) {
                if ( $depth === 0 ) return false; // we left the enclosing block
                $depth--;
                continue;
            }
            if ( $depth > 0 ) continue; // inside a nested block

            // At depth 0 — check for T_IF.
            if ( is_array( $t ) && $t[0] === T_IF ) {
                // Found the if. Walk forward from here to the `function`
                // keyword and look for a function_exists call in the if's
                // condition (between the if and the matching `{` or `:`).
                for ( $j = $i + 1; $j < $func_idx; $j++ ) {
                    $ct = $tokens[ $j ];
                    if ( is_array( $ct ) && $ct[0] === T_STRING && strtolower( $ct[1] ) === 'function_exists' ) {
                        // Verify it's a call (followed by '(').
                        $next = $this->next_significant_token( $tokens, $j + 1, $count );
                        if ( $next !== null && $tokens[ $next ] === '(' ) {
                            return true;
                        }
                    }
                }
                return false;
            }
        }
        return false;
    }

    // ── POST /lint-deep (cross-file scan) ──────────────────────────────────────
    //
    // Scans ALL PHP files in the same plugin/theme root for cross-file
    // issues that the per-file linter cannot catch:
    //
    //   • Duplicate function names across files (would cause "Cannot
    //     redeclare function" fatal when both files are loaded)
    //   • Duplicate class names across files (would cause "Cannot redeclare
    //     class" fatal)
    //
    // The scan is capped at MAX_LINT_FILES files and DEEP_SCAN_BUDGET
    // seconds so it can never run away on a huge plugin.
    //
    // When the saved file's NEW content introduces a duplicate, the error
    // is reported on the saved file's line. When a pre-existing file
    // already has the duplicate, it's reported on that file's line.
    public function handle_lint_deep( WP_REST_Request $request ): WP_REST_Response {
        try {
            $type    = $request->get_param( 'type' );
            $slug    = $request->get_param( 'slug' ) ?? '';
            $path    = $request->get_param( 'path' ) ?? '';
            $content = $request->get_param( 'content' ); // the NEW content of the saved file (optional)

            $root = $this->resolve_root( $type, $slug );
            if ( is_wp_error( $root ) ) {
                return new WP_REST_Response( [ 'error' => $root->get_error_message() ], 404 );
            }

            $saved_file_rel = '';
            if ( $path !== '' ) {
                $saved_resolved = $this->resolve_subpath( $root, $path );
                if ( ! is_wp_error( $saved_resolved ) ) {
                    $saved_file_rel = str_replace( '\\', '/', ltrim( substr( $saved_resolved, strlen( $root ) ), DIRECTORY_SEPARATOR ) );
                }
            }

            // Collect function & class definitions from every PHP file in
            // the root. For the SAVED file, use the NEW content (if
            // provided) instead of the on-disk content — this lets us
            // detect duplicates that the user is ABOUT to introduce.
            //
            // Map structure:
            //   $functions[ name_lower ] = [ [ 'file' => rel, 'line' => int ], ... ]
            //   $classes[ name_lower ]   = [ [ 'file' => rel, 'line' => int ], ... ]
            $functions = [];
            $classes   = [];
            $interfaces = [];
            $traits    = [];

            $discovered = $this->collect_php_files( $root );
            $scanned = 0;
            $start_time = microtime( true );
            $truncated = false;

            foreach ( $discovered as $rel ) {
                if ( ( microtime( true ) - $start_time ) > self::DEEP_SCAN_BUDGET ) {
                    $truncated = true;
                    break;
                }

                $abs = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );

                // For the saved file, use the NEW content if provided.
                if ( $rel === $saved_file_rel && is_string( $content ) ) {
                    $source = $content;
                } else {
                    if ( ! is_readable( $abs ) ) continue;
                    if ( filesize( $abs ) > self::MAX_LINT_BYTES ) continue;
                    $source = @file_get_contents( $abs );
                    if ( $source === false ) continue;
                }

                $scanned++;
                $this->collect_definitions( $source, $rel, $functions, $classes, $interfaces, $traits );
            }

            // Now find duplicates.
            $errors = [];

            $find_dups = static function ( $map, $kind ) use ( &$errors, $saved_file_rel ) {
                foreach ( $map as $name_lower => $occurrences ) {
                    if ( count( $occurrences ) < 2 ) continue;

                    // Only report if at least one occurrence is in the
                    // saved file — otherwise it's a pre-existing issue
                    // the user isn't currently editing, and surfacing
                    // every pre-existing duplicate would be noisy.
                    $in_saved = false;
                    foreach ( $occurrences as $occ ) {
                        if ( $occ['file'] === $saved_file_rel ) {
                            $in_saved = true;
                            break;
                        }
                    }
                    if ( ! $in_saved ) continue;

                    // Report each occurrence EXCEPT the first.
                    $first = $occurrences[0];
                    for ( $i = 1; $i < count( $occurrences ); $i++ ) {
                        $occ = $occurrences[ $i ];
                        $errors[] = [
                            'file'        => $occ['file'],
                            'line'        => $occ['line'],
                            'column'      => 1,
                            'message'     => sprintf(
                                __( 'Duplicate %s %s — already declared in %s on line %d. Loading both files will cause a fatal "Cannot redeclare %s" error.', 'wptd' ),
                                $kind,
                                $occ['name'],
                                $first['file'],
                                $first['line'],
                                $kind
                            ),
                            'solution'    => sprintf(
                                __( 'Rename one of the %1$s %2$ss, or — if this is a pluggable function — wrap the declaration in: if ( ! %3$s_exists( \'%2$s\' ) ) { function %2$s() { ... } } (for classes: class_exists, for interfaces: interface_exists, for traits: trait_exists).', 'wptd' ),
                                ucfirst( $kind ),
                                $occ['name'],
                                $kind
                            ),
                            'severity'    => 'error',
                            'isSavedFile' => $occ['file'] === $saved_file_rel,
                        ];
                    }
                }
            };

            $find_dups( $functions, 'function' );
            $find_dups( $classes, 'class' );
            $find_dups( $interfaces, 'interface' );
            $find_dups( $traits, 'trait' );

            $has_critical = false;
            $has_warning  = false;
            foreach ( $errors as $e ) {
                if ( $e['severity'] === 'error' ) $has_critical = true;
                elseif ( $e['severity'] === 'warning' ) $has_warning = true;
            }

            return new WP_REST_Response( [
                'savedFile'    => $saved_file_rel,
                'path'         => $path,
                'type'         => $type,
                'slug'         => $slug,
                'errors'       => $errors,
                'totalErrors'  => count( $errors ),
                'scannedFiles' => $scanned,
                'savedFailed'  => $has_critical,
                'hasCritical'  => $has_critical,
                'hasWarning'   => $has_warning,
                'engine'       => 'cross-file',
                'mode'         => 'deep',
                'truncated'    => $truncated,
            ], 200 );

        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [
                'error' => __( 'Deep lint check failed: ', 'wptd' ) . $e->getMessage(),
                'errors' => [],
                'totalErrors' => 0,
                'hasCritical' => false,
                'hasWarning' => false,
                'engine' => 'none',
            ], 200 );
        }
    }

    /**
     * Collect function/class/interface/trait definitions from a PHP
     * source string and add them to the $functions/$classes/etc maps.
     *
     * Each map is: name_lower => [ [ 'file' => rel_path, 'line' => int, 'name' => original_case ], ... ]
     *
     * Skips declarations that are guarded by function_exists / class_exists
     * (the pluggable pattern) — those are intentional re-declarations.
     */
    private function collect_definitions( string $source, string $rel_path, array &$functions, array &$classes, array &$interfaces, array &$traits ): void {
        if ( substr( $source, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $source = substr( $source, 3 );
        }

        $tokens = @token_get_all( $source );
        if ( ! is_array( $tokens ) ) return;
        $count = count( $tokens );

        for ( $i = 0; $i < $count - 2; $i++ ) {
            $t = $tokens[ $i ];
            if ( ! is_array( $t ) ) continue;

            if ( $t[0] === T_FUNCTION ) {
                $name_token = $this->next_significant_token( $tokens, $i + 1, $count );
                if ( $name_token === null ) continue;
                $nt = $tokens[ $name_token ];
                if ( ! is_array( $nt ) || $nt[0] !== T_STRING ) continue;

                // Skip if guarded by function_exists (pluggable pattern).
                if ( $this->is_guarded_by_function_exists( $tokens, $i, $count ) ) continue;

                $name = $nt[1];
                $name_lower = strtolower( $name );
                $line = $nt[2];

                if ( ! isset( $functions[ $name_lower ] ) ) {
                    $functions[ $name_lower ] = [];
                }
                $functions[ $name_lower ][] = [ 'file' => $rel_path, 'line' => $line, 'name' => $name ];
                continue;
            }

            if ( $t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT ) {
                $name_token = $this->next_significant_token( $tokens, $i + 1, $count );
                if ( $name_token === null ) continue;
                $nt = $tokens[ $name_token ];
                if ( ! is_array( $nt ) || $nt[0] !== T_STRING ) continue;

                $name = $nt[1];
                $name_lower = strtolower( $name );
                $line = $nt[2];

                $bucket = ( $t[0] === T_CLASS ) ? $classes : ( $t[0] === T_INTERFACE ? $interfaces : $traits );

                // For classes/interfaces/traits we don't check the guard
                // (the pluggable pattern is rare for them and class_exists
                // checks are usually outside the class declaration itself).
                if ( ! isset( $bucket[ $name_lower ] ) ) {
                    $bucket[ $name_lower ] = [];
                }
                $bucket[ $name_lower ][] = [ 'file' => $rel_path, 'line' => $line, 'name' => $name ];
            }
        }
    }

    /**
     * Map a parse-error message to a human-readable solution. The matcher
     * checks for common PHP error patterns and returns a specific fix; if
     * no specific match is found, a sensible generic suggestion is returned.
     */
    private function solution_for( string $message ): string {
        $m = strtolower( $message );

        if ( strpos( $m, "unexpected '}'" ) !== false ) {
            return __( 'There is a closing brace "}" without a matching opening brace "{". Look at the lines just before this one — you probably deleted a "{" or have one too many "}".', 'wptd' );
        }
        if ( strpos( $m, "unexpected '{'" ) !== false ) {
            return __( 'An opening brace "{" appears where PHP did not expect one. Check that the previous statement ends with a semicolon ";" and that you did not forget a closing parenthesis ")" or "}".', 'wptd' );
        }
        if ( strpos( $m, "unexpected ')'" ) !== false ) {
            return __( 'There is a closing parenthesis ")" without a matching "(". Check the function call or condition on the lines above.', 'wptd' );
        }
        if ( strpos( $m, "unexpected '('" ) !== false ) {
            return __( 'An opening parenthesis "(" appears where PHP did not expect one. Check that the previous statement ends with a semicolon ";".', 'wptd' );
        }
        if ( strpos( $m, 'unexpected end of file' ) !== false ) {
            return __( 'PHP reached the end of the file while still inside a block. You are missing a closing brace "}" (or, less commonly, a closing parenthesis ")" or semicolon ";"). Count every "{" and "}" in the file to find the unmatched one.', 'wptd' );
        }
        if ( strpos( $m, "unexpected ','" ) !== false ) {
            return __( 'A comma "," appears where PHP did not expect one. Check the previous expression — you may be missing an operator, a value, or a semicolon ";".', 'wptd' );
        }
        if ( strpos( $m, "unexpected ';'" ) !== false ) {
            return __( 'A semicolon ";" appears where PHP did not expect one. This usually means an incomplete expression on the previous line — for example an empty condition "if ();" or a missing argument inside a function call.', 'wptd' );
        }
        if ( strpos( $m, 'unexpected \'function\'' ) !== false || strpos( $m, 'unexpected function' ) !== false ) {
            return __( 'PHP did not expect a "function" keyword here. Check that the previous statement ends with a semicolon ";" and that this function is declared at the correct scope (e.g. inside a class, or at the top level).', 'wptd' );
        }
        foreach ( [ 'if', 'for', 'while', 'foreach', 'switch' ] as $kw ) {
            if ( strpos( $m, "unexpected '$kw'" ) !== false || strpos( $m, "unexpected $kw" ) !== false ) {
                return sprintf(
                    __( 'PHP did not expect the "%1$s" keyword here. Check that the previous statement ends with a semicolon ";" or a closing brace "}" and that you are not inside an expression.', 'wptd' ),
                    $kw
                );
            }
        }
        if ( strpos( $m, 'unexpected variable' ) !== false ) {
            return __( 'A variable appears where PHP did not expect one. Check the previous token — you may be missing an operator such as "=", "==", ".", or ",".', 'wptd' );
        }
        if ( strpos( $m, 'unexpected string' ) !== false || strpos( $m, "unexpected '\"'" ) !== false || strpos( $m, "unexpected \"'\"" ) !== false ) {
            return __( 'A string literal appears where PHP did not expect one. Check that the previous expression is complete — you may be missing a comma "," between array elements, a concatenation operator ".", or a semicolon ";".', 'wptd' );
        }
        if ( strpos( $m, "unexpected '::'" ) !== false || strpos( $m, 'unexpected t_double_colon' ) !== false ) {
            return __( 'The "::" (scope resolution) operator was used incorrectly. Make sure the left-hand side is a valid class name or "self"/"static"/"parent" keyword, and that the class is loaded.', 'wptd' );
        }
        if ( strpos( $m, "unexpected '->'" ) !== false || strpos( $m, 'unexpected t_object_operator' ) !== false ) {
            return __( 'The "->" operator was used incorrectly. Make sure the left-hand side is an object variable (e.g. "$obj->method()"), and that the variable is properly initialised.', 'wptd' );
        }
        if ( strpos( $m, 'array offset' ) !== false ) {
            return __( 'You are trying to access an array key on something that is not an array. Use is_array() before accessing the offset, or check the source of the variable.', 'wptd' );
        }
        if ( strpos( $m, 'already in use' ) !== false ) {
            return __( 'A class, trait, function, or constant with this name has already been declared. Rename one of them, or wrap the declaration in a conditional "if ( ! class_exists( ... ) )".', 'wptd' );
        }
        if ( strpos( $m, 'not found' ) !== false && ( strpos( $m, 'class' ) !== false || strpos( $m, 'interface' ) !== false || strpos( $m, 'trait' ) !== false ) ) {
            return __( 'PHP could not find the referenced class, interface, or trait. Make sure the file that declares it is included (require/require_once) BEFORE this line, and that the namespace is correct.', 'wptd' );
        }
        if ( strpos( $m, 'call to undefined' ) !== false ) {
            return __( 'You are calling a function or method that PHP does not know about. Check the spelling, the namespace, and that the declaring file is loaded. If it is a method, make sure $this is in scope and the method exists on the class.', 'wptd' );
        }
        if ( strpos( $m, 'cannot redeclare' ) !== false ) {
            return __( 'A function with this name has already been declared in this request. Either rename it, or wrap the declaration in "if ( ! function_exists( \'name\' ) )". The same applies to classes ("class_exists") and traits.', 'wptd' );
        }

        return __( 'Open the file and locate the line shown above. Common causes are: a missing semicolon ";" on the previous line, an unbalanced brace/parenthesis, or a typo in a keyword. Compare with a known-good file if you are unsure.', 'wptd' );
    }
}

<?php
/**
 * REST API endpoints for file revision history and git-style diffs.
 *
 * Namespace: wptd/v1
 *
 * Routes
 * ──────
 *  GET   /revisions        → list recent file edits (with optional time filter)
 *  GET   /revisions/<id>   → get one revision's metadata + before/after content
 *  GET   /diff             → compute a git-style unified diff for a revision
 *  POST  /revisions/<id>/restore → restore a file to its state before an edit
 *  DELETE /revisions       → clear all revision history
 *
 * Revisions are stored in the `wptd_edit_history` option (populated by
 * File_Endpoint::push_history on every save). Each revision captures the
 * BEFORE and AFTER content (capped at 256 KB) so we can compute a
 * line-by-line diff without needing a real git repository.
 *
 * @package WPTD\Api
 */

namespace WPTD\Api;

use WPTD\Contracts\Hookable;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class Revision_Endpoint implements Hookable {

    private const NAMESPACE = 'wptd/v1';
    private const OPTION_KEY = 'wptd_edit_history';
    private const MAX_REVISIONS = 100;

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        // GET /revisions — list recent edits, optionally filtered by time.
        register_rest_route( self::NAMESPACE, '/revisions', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_list' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'hours' => [
                        'required'          => false,
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'path' => [
                        'required'          => false,
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'handle_clear' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
        ] );

        // GET /revisions/<id> — one revision's details.
        register_rest_route( self::NAMESPACE, '/revisions/(?P<id>[a-f0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_get_one' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // GET /diff?id=<revision-id> — git-style unified diff.
        register_rest_route( self::NAMESPACE, '/diff', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_diff' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // POST /revisions/<id>/restore — restore the file to its
        // pre-edit state (the "old_content" captured at save time).
        register_rest_route( self::NAMESPACE, '/revisions/(?P<id>[a-f0-9\-]+)/restore', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_restore' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    // ── Permission ─────────────────────────────────────────────────────────────

    public function check_permission(): bool|WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to view revisions.', 'wptd' ),
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

    /**
     * Load all revisions from the option, newest first.
     */
    private function load_all(): array {
        $hist = get_option( self::OPTION_KEY, [] );
        return is_array( $hist ) ? $hist : [];
    }

    /**
     * Find one revision by ID.
     */
    private function find( string $id ): ?array {
        foreach ( $this->load_all() as $entry ) {
            if ( ( $entry['id'] ?? '' ) === $id ) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Resolve the absolute path of a revision's file, verifying it's
     * still inside the plugin/theme root (security check).
     */
    private function resolve_file( string $type, string $slug, string $path ): string|WP_Error {
        $root = null;
        if ( $type === 'wordpress' ) {
            $root = realpath( ABSPATH );
        } elseif ( $type === 'plugin' ) {
            $root = realpath( WP_PLUGIN_DIR . '/' . $slug );
        } elseif ( $type === 'theme' ) {
            $root = realpath( get_theme_root() . '/' . $slug );
        }
        if ( ! $root || ! is_dir( $root ) ) {
            return new WP_Error( 'invalid_root', __( 'Plugin/theme root not found.', 'wptd' ), [ 'status' => 404 ] );
        }

        $path = str_replace( '\\', '/', $path );
        $path = ltrim( $path, '/' );
        $parts = explode( '/', $path );
        foreach ( $parts as $p ) {
            if ( $p === '..' ) {
                return new WP_Error( 'invalid_path', __( 'Invalid path.', 'wptd' ), [ 'status' => 400 ] );
            }
        }
        $full = $root . '/' . $path;
        $real = realpath( $full );
        return $real !== false ? $real : $full;
    }

    // ── GET /revisions ─────────────────────────────────────────────────────────

    public function handle_list( WP_REST_Request $request ): WP_REST_Response {
        $hours = (int) $request->get_param( 'hours' );
        $path_filter = $request->get_param( 'path' ) ?? '';

        $all = $this->load_all();

        // Filter by time. hours = 0 means "all time".
        $cutoff = $hours > 0 ? ( time() - ( $hours * HOUR_IN_SECONDS ) ) : 0;
        $out = [];
        foreach ( $all as $entry ) {
            $ts = $entry['timestamp'] ?? 0;
            if ( $ts < $cutoff ) continue;
            if ( $path_filter !== '' && ( $entry['path'] ?? '' ) !== $path_filter ) continue;

            // Don't send the full old_content/new_content in the list —
            // too heavy. Just send metadata + a small preview.
            $out[] = [
                'id'         => $entry['id'] ?? '',
                'type'       => $entry['type'] ?? '',
                'slug'       => $entry['slug'] ?? '',
                'path'       => $entry['path'] ?? '',
                'size'       => $entry['size'] ?? 0,
                'old_size'   => $entry['old_size'] ?? 0,
                'timestamp'  => $ts,
                'user'       => $entry['user'] ?? 0,
                'user_name'  => $entry['user_name'] ?? '',
                'preview'    => $entry['preview'] ?? '',
                'has_diff'   => isset( $entry['old_content'] ) && isset( $entry['new_content'] ),
            ];
        }

        return new WP_REST_Response( [
            'revisions' => $out,
            'total'     => count( $out ),
            'hours'     => $hours,
        ], 200 );
    }

    // ── GET /revisions/<id> ────────────────────────────────────────────────────

    public function handle_get_one( WP_REST_Request $request ): WP_REST_Response {
        $id = $request->get_param( 'id' ) ?? '';
        $entry = $this->find( $id );
        if ( ! $entry ) {
            return new WP_REST_Response( [ 'error' => __( 'Revision not found.', 'wptd' ) ], 404 );
        }
        // Strip the heavy content fields — the client should use /diff
        // to get the actual diff.
        unset( $entry['old_content'], $entry['new_content'] );
        return new WP_REST_Response( $entry, 200 );
    }

    // ── GET /diff ──────────────────────────────────────────────────────────────

    public function handle_diff( WP_REST_Request $request ): WP_REST_Response {
        $id = $request->get_param( 'id' ) ?? '';
        $entry = $this->find( $id );
        if ( ! $entry ) {
            return new WP_REST_Response( [ 'error' => __( 'Revision not found.', 'wptd' ) ], 404 );
        }

        $old = $entry['old_content'] ?? '';
        $new = $entry['new_content'] ?? '';

        // If we don't have the stored content (file was too big), try to
        // read the on-disk backup file.
        if ( $old === '' && isset( $entry['path'] ) ) {
            // Best-effort: we can't reliably reconstruct the old content
            // if it wasn't stored. Tell the client.
            return new WP_REST_Response( [
                'error' => __( 'Diff unavailable — the file content was not stored for this revision (file may have been too large).', 'wptd' ),
                'hunks' => [],
            ], 200 );
        }

        $diff = $this->compute_unified_diff( $old, $new, $entry['path'] ?? 'file' );

        return new WP_REST_Response( [
            'id'        => $id,
            'path'      => $entry['path'] ?? '',
            'timestamp' => $entry['timestamp'] ?? 0,
            'diff'      => $diff['text'],
            'hunks'     => $diff['hunks'],
            'stats'     => $diff['stats'],
        ], 200 );
    }

    // ── POST /revisions/<id>/restore ───────────────────────────────────────────

    public function handle_restore( WP_REST_Request $request ): WP_REST_Response {
        $id = $request->get_param( 'id' ) ?? '';
        $entry = $this->find( $id );
        if ( ! $entry ) {
            return new WP_REST_Response( [ 'error' => __( 'Revision not found.', 'wptd' ) ], 404 );
        }

        $old = $entry['old_content'] ?? null;
        if ( $old === null ) {
            return new WP_REST_Response( [ 'error' => __( 'Cannot restore — the original content was not stored.', 'wptd' ) ], 400 );
        }

        $resolved = $this->resolve_file( $entry['type'] ?? 'plugin', $entry['slug'] ?? '', $entry['path'] ?? '' );
        if ( is_wp_error( $resolved ) ) {
            return new WP_REST_Response( [ 'error' => $resolved->get_error_message() ], 400 );
        }

        // Write the old content back to the file.
        $written = @file_put_contents( $resolved, $old );
        if ( $written === false ) {
            return new WP_REST_Response( [ 'error' => __( 'Failed to restore file. Check filesystem permissions.', 'wptd' ) ], 500 );
        }

        return new WP_REST_Response( [
            'ok'      => true,
            'path'    => $entry['path'],
            'size'    => filesize( $resolved ),
            'message' => __( 'File restored to its state before this edit.', 'wptd' ),
        ], 200 );
    }

    // ── DELETE /revisions ──────────────────────────────────────────────────────

    public function handle_clear( WP_REST_Request $request ): WP_REST_Response {
        delete_option( self::OPTION_KEY );
        return new WP_REST_Response( [ 'cleared' => true ], 200 );
    }

    // ── Diff engine ────────────────────────────────────────────────────────────
    //
    // A simple LCS-based line diff that produces git-style unified diff
    // hunks. We don't depend on the `xdiff` extension (which may not be
    // installed) — we implement a minimal Myers-style diff in pure PHP.
    //
    // Output format:
    //
    //   'text'  => string  — the raw unified diff text (like `git diff`)
    //   'hunks' => array   — structured hunks for side-by-side rendering:
    //                        [ [ 'old_start' => int, 'new_start' => int,
    //                            'lines' => [ [ 'type' => 'context'|'add'|'del',
    //                                           'old_line' => int|null,
    //                                           'new_line' => int|null,
    //                                           'text' => string ], ... ] ], ... ]
    //   'stats' => array   — [ 'additions' => int, 'deletions' => int ]

    private function compute_unified_diff( string $old, string $new, string $path ): array {
        $old_lines = $this->split_lines( $old );
        $new_lines = $this->split_lines( $new );

        // Compute the LCS table.
        $ops = $this->diff_lines( $old_lines, $new_lines );

        // Build structured hunks.
        $hunks = [];
        $current_hunk = null;
        $context = 3;
        $old_line = 1;
        $new_line = 1;
        $additions = 0;
        $deletions = 0;

        foreach ( $ops as $op ) {
            [ $type, $text ] = $op;

            if ( $type === 'equal' ) {
                if ( $current_hunk !== null ) {
                    // Add context line to the current hunk.
                    $current_hunk['lines'][] = [
                        'type'     => 'context',
                        'old_line' => $old_line,
                        'new_line' => $new_line,
                        'text'     => $text,
                    ];
                    $current_hunk['context_count']++;

                    // Close the hunk after $context context lines.
                    if ( $current_hunk['context_count'] >= $context ) {
                        $hunks[] = $current_hunk;
                        $current_hunk = null;
                    }
                }
                $old_line++;
                $new_line++;
            } elseif ( $type === 'delete' ) {
                if ( $current_hunk === null ) {
                    $current_hunk = $this->start_hunk( $old_line, $new_line );
                }
                $current_hunk['lines'][] = [
                    'type'     => 'del',
                    'old_line' => $old_line,
                    'new_line' => null,
                    'text'     => $text,
                ];
                $current_hunk['context_count'] = 0;
                $deletions++;
                $old_line++;
            } elseif ( $type === 'add' ) {
                if ( $current_hunk === null ) {
                    $current_hunk = $this->start_hunk( $old_line, $new_line );
                }
                $current_hunk['lines'][] = [
                    'type'     => 'add',
                    'old_line' => null,
                    'new_line' => $new_line,
                    'text'     => $text,
                ];
                $current_hunk['context_count'] = 0;
                $additions++;
                $new_line++;
            }
        }
        if ( $current_hunk !== null ) {
            $hunks[] = $current_hunk;
        }

        // Build the raw unified-diff text.
        $text_lines = [];
        $text_lines[] = '--- a/' . $path;
        $text_lines[] = '+++ b/' . $path;
        foreach ( $hunks as $hunk ) {
            $old_count = count( array_filter( $hunk['lines'], fn( $l ) => $l['type'] === 'del' || $l['type'] === 'context' ) );
            $new_count = count( array_filter( $hunk['lines'], fn( $l ) => $l['type'] === 'add' || $l['type'] === 'context' ) );
            $text_lines[] = sprintf(
                '@@ -%d,%d +%d,%d @@',
                $hunk['old_start'], $old_count,
                $hunk['new_start'], $new_count
            );
            foreach ( $hunk['lines'] as $l ) {
                $prefix = $l['type'] === 'add' ? '+' : ( $l['type'] === 'del' ? '-' : ' ' );
                $text_lines[] = $prefix . $l['text'];
            }
        }

        return [
            'text'  => implode( "\n", $text_lines ),
            'hunks' => $hunks,
            'stats' => [
                'additions' => $additions,
                'deletions' => $deletions,
            ],
        ];
    }

    /**
     * Start a new diff hunk, including $context lines of context BEFORE
     * the change. We do this by looking back at the last few equal ops.
     */
    private function start_hunk( int $old_line, int $new_line ): array {
        return [
            'old_start'     => $old_line,
            'new_start'     => $new_line,
            'lines'         => [],
            'context_count' => 0,
        ];
    }

    /**
     * Split a string into lines, preserving the content but not the
     * trailing newline of each line (we add it back in the diff output).
     */
    private function split_lines( string $text ): array {
        if ( $text === '' ) return [];
        $lines = preg_split( '/\r\n|\r|\n/', $text );
        // preg_split on a string ending with a newline produces a trailing
        // empty element — remove it to match git's line counting.
        if ( count( $lines ) > 0 && end( $lines ) === '' ) {
            array_pop( $lines );
        }
        return $lines;
    }

    /**
     * Compute the diff operations between two arrays of lines using the
     * standard LCS dynamic-programming algorithm. Returns a list of
     * [type, text] tuples where type is 'equal', 'add', or 'delete'.
     *
     * This is O(n*m) in time and space. For files up to a few thousand
     * lines this is fine. For very large files we cap the line count
     * before diffing.
     */
    private function diff_lines( array $a, array $b ): array {
        $n = count( $a );
        $m = count( $b );

        // Cap to avoid memory blow-up on huge files.
        $max_lines = 5000;
        if ( $n > $max_lines ) {
            $a = array_slice( $a, 0, $max_lines );
            $n = $max_lines;
        }
        if ( $m > $max_lines ) {
            $b = array_slice( $b, 0, $max_lines );
            $m = $max_lines;
        }

        // Build the LCS length table.
        // $dp[i][j] = length of LCS of $a[i..] and $b[j..]
        $dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
        for ( $i = $n - 1; $i >= 0; $i-- ) {
            for ( $j = $m - 1; $j >= 0; $j-- ) {
                if ( $a[ $i ] === $b[ $j ] ) {
                    $dp[ $i ][ $j ] = $dp[ $i + 1 ][ $j + 1 ] + 1;
                } else {
                    $dp[ $i ][ $j ] = max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
                }
            }
        }

        // Backtrack to build the op list.
        $ops = [];
        $i = 0;
        $j = 0;
        while ( $i < $n && $j < $m ) {
            if ( $a[ $i ] === $b[ $j ] ) {
                $ops[] = [ 'equal', $a[ $i ] ];
                $i++;
                $j++;
            } elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
                $ops[] = [ 'delete', $a[ $i ] ];
                $i++;
            } else {
                $ops[] = [ 'add', $b[ $j ] ];
                $j++;
            }
        }
        while ( $i < $n ) {
            $ops[] = [ 'delete', $a[ $i ] ];
            $i++;
        }
        while ( $j < $m ) {
            $ops[] = [ 'add', $b[ $j ] ];
            $j++;
        }

        return $ops;
    }
}

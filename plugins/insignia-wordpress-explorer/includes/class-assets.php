<?php
/**
 * Enqueues the React admin app + CodeMirror (loaded from CDN to keep
 * the plugin zip small).
 *
 * @package WPTD
 */

namespace WPTD;

defined( 'ABSPATH' ) || exit;

class Assets implements Contracts\Hookable {

    private const PAGE_SLUG = 'insignia-explorer';

    public function register_hooks(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'admin_head', [ $this, 'admin_head' ] );
    }

    /**
     * Inject a small <style> + <title> tweak on our admin page so the
     * browser tab and the WP admin header reflect the new product name.
     */
    public function admin_head(): void {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
            return;
        }
        // Hide WP's default "wrap" h1 — our React header already shows the brand.
        echo '<style>#wpbody-content .wrap > h1{display:none!important;}</style>';
    }

    public function enqueue_admin( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
            return;
        }

        $asset_file = WPTD_DIR . 'build/index.asset.php';
        if ( ! file_exists( $asset_file ) ) {
            return;
        }

        $asset = require $asset_file;

        // ── Google Fonts: Inter (UI) + JetBrains Mono (code) ──
        wp_enqueue_style(
            'wptd-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap',
            [],
            null
        );

        // ── CodeMirror 5 from CDN ──
        // Used by the in-plugin code editor. Loaded on every WPTD admin page so
        // the editor can mount instantly without a second round-trip.
        wp_enqueue_style(
            'wptd-codemirror-css',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css',
            [],
            '5.65.16'
        );
        wp_enqueue_style(
            'wptd-codemirror-theme',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css',
            [ 'wptd-codemirror-css' ],
            '5.65.16'
        );

        wp_enqueue_script(
            'wptd-codemirror',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js',
            [],
            '5.65.16',
            true
        );

        // CodeMirror modes — only the ones we actually use.
        //
        // IMPORTANT: CodeMirror's "php" mode is a composite mode — it tokenizes
        // PHP with the same engine as C-like languages ("clike") and falls back
        // to "htmlmixed" (which itself layers "xml", "javascript" and "css") for
        // any markup outside the PHP open/close tags. If "clike" isn't registered
        // *before* "php" loads, CodeMirror silently mounts PHP files with no
        // tokenizer at all, so the editor renders plain, uncoloured text. The
        // $modes list below is order-sensitive and each entry declares its real
        // dependency so wp_enqueue_script loads scripts in the right sequence
        // regardless of registration order elsewhere.
        $modes = [
            'clike'      => [],
            'xml'        => [],
            'javascript' => [],
            'css'        => [],
            'htmlmixed'  => [ 'xml', 'javascript', 'css' ],
            'php'        => [ 'clike', 'htmlmixed' ],
            'json'       => [],
            'markdown'   => [],
            'sql'        => [],
            'yaml'       => [],
        ];
        foreach ( $modes as $mode => $requires ) {
            $deps = [ 'wptd-codemirror' ];
            foreach ( $requires as $required_mode ) {
                $deps[] = 'wptd-codemirror-mode-' . $required_mode;
            }
            wp_enqueue_script(
                'wptd-codemirror-mode-' . $mode,
                'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/' . $mode . '/' . $mode . '.min.js',
                $deps,
                '5.65.16',
                true
            );
        }

        // CodeMirror addons we use: search, edit/matchbrackets, edit/closebrackets, fold/foldcode, fold/*, dialog
        $addons = [
            'search/searchcursor',
            'search/search',
            'edit/matchbrackets',
            'edit/closebrackets',
            'edit/closetag',
            'fold/foldcode',
            'fold/foldgutter',
            'fold/brace-fold',
            'fold/xml-fold',
            'fold/indent-fold',
            'fold/comment-fold',
            'dialog/dialog',
            'scroll/annotatescrollbar',
            'search/matchesonscrollbar',
        ];
        foreach ( $addons as $addon ) {
            $handle = 'wptd-codemirror-addon-' . str_replace( '/', '-', $addon );
            wp_enqueue_script(
                $handle,
                'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/' . $addon . '.min.js',
                [ 'wptd-codemirror' ],
                '5.65.16',
                true
            );
        }
        // Dialog CSS (used by CodeMirror search).
        wp_enqueue_style(
            'wptd-codemirror-dialog',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.css',
            [ 'wptd-codemirror-css' ],
            '5.65.16'
        );
        // Fold gutter CSS.
        wp_enqueue_style(
            'wptd-codemirror-fold',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.css',
            [ 'wptd-codemirror-css' ],
            '5.65.16'
        );

        // ── Main React script ──
        wp_enqueue_script(
            'wptd-app',
            WPTD_URL . 'build/index.js',
            array_merge( $asset['dependencies'], [ 'wptd-codemirror' ] ),
            $asset['version'],
            true
        );

//         if ( file_exists( WPTD_DIR . 'build/index.css' ) ) {
//             wp_enqueue_style(
//                 'wptd-app',
//                 WPTD_URL . 'build/index.css',
//                 [ 'wp-components', 'wptd-codemirror-css', 'wptd-codemirror-theme', 'wptd-fonts' ],
//                 $asset['version']
//             );
//         }
		$css_file = WPTD_DIR . 'build/index.css';

		$version = defined( 'WP_DEBUG' ) && WP_DEBUG
			? filemtime( $css_file )
			: $asset['version'];

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wptd-app',
				WPTD_URL . 'build/index.css',
				[ 'wp-components', 'wptd-codemirror-css', 'wptd-codemirror-theme', 'wptd-fonts' ],
				$version
			);
		}

        $settings = get_option( 'wptd_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];

        wp_localize_script(
            'wptd-app',
            'WPTDData',
            [
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'restUrl'      => esc_url_raw( rest_url( 'wptd/v1' ) ),
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'version'      => WPTD_VERSION,
                'adminUrl'     => admin_url(),
                'siteUrl'      => home_url(),
                'currentUser'  => wp_get_current_user()->display_name ?? '',
                'settings'     => $settings,
                'pluginUrl'    => WPTD_URL,
                'pluginPath'   => WPTD_DIR,
                'i18n'         => [
                    'plugins' => __( 'Plugins', 'wptd' ),
                    'themes'  => __( 'Themes', 'wptd' ),
                ],
            ]
        );
    }
}

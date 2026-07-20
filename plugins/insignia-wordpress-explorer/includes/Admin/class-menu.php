<?php
/**
 * Registers the admin menu page.
 *
 * @package WPTD\Admin
 */

namespace WPTD\Admin;

use WPTD\Contracts\Hookable;

defined( 'ABSPATH' ) || exit;

class Menu implements Hookable {

    public const PAGE_SLUG = 'insignia-explorer';

    public function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_filter( 'plugin_action_links_' . WPTD_BASENAME, [ $this, 'add_action_links' ] );
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'Insignia WordPress Explorer', 'wptd' ),
            __( 'Insignia Explorer', 'wptd' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            // Inline SVG: a shield/compass mark so the admin menu icon
            // matches the in-app branding (instead of a generic dashicon).
            'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
                . '<path fill="none" stroke="#a7a7a7" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" '
                . 'd="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5l-8-3z"/>'
                . '<circle cx="12" cy="10" r="3" fill="none" stroke="#a7a7a7" stroke-width="1.6"/>'
                . '<line x1="14" y1="12" x2="16.5" y2="14.5" stroke="#a7a7a7" stroke-width="1.6" stroke-linecap="round"/>'
                . '</svg>'
            ),
            75
        );
    }

    public function add_action_links( array $links ): array {
        $url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Explorer', 'wptd' ) . '</a>'
        );
        return $links;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'wptd' ) );
        }

        if ( file_exists( WPTD_DIR . 'build/index.asset.php' ) ) {
            // Use .wrap so the React app inherits standard WordPress admin
            // padding (top margin + side gutters). Our CSS then adds NO
            // negative margins — the layout simply fits inside .wrap.
            echo '<div class="wrap wptd-wrap"><div id="wptd-root"></div></div>';
        } else {
            echo '<div class="wrap">';
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__( 'React build not found. Run `npm run build` inside the plugin folder, or use the PHP fallback below.', 'wptd' );
            echo '</p></div>';
            $this->render_php_fallback();
            echo '</div>';
        }
    }

    private function render_php_fallback(): void {
        require_once WPTD_DIR . 'admin/views/fallback-table.php';
    }
}

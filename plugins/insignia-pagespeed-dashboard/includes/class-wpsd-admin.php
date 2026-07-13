<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSD_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer', [$this, 'add_footer_notification']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'PageSpeed Dashboard',
            'PageSpeed',
            'manage_options',
            'wp-pagespeed-dashboard',
            [$this, 'render_page'],
            'dashicons-performance',
            81
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'wp-pagespeed-dashboard') === false) {
            return;
        }
        wp_enqueue_style('wpsd-style', WPSD_URL . 'assets/css/style.css', [], WPSD_VERSION);
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('wpsd-admin', WPSD_URL . 'assets/js/admin.js', ['jquery', 'chart-js'], WPSD_VERSION, true);
        wp_localize_script('wpsd-admin', 'wpsd_data', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('wpsd_nonce'),
            'site_url'   => get_site_url(),
            'has_api_key' => !empty(get_option('wpsd_api_key', '')),
            'wptoolbox_modules' => $this->get_wptoolbox_disabled_modules(),
        ]);
    }

    public function render_page() {
        require_once WPSD_PATH . 'templates/main.php';
    }

    public static function save_api_key() {
        check_ajax_referer('wpsd_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        update_option('wpsd_api_key', $api_key);
        wp_send_json_success(['message' => 'API Key saved successfully!']);
    }

    public static function clear_cache() {
        check_ajax_referer('wpsd_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }
        $url = sanitize_url($_POST['url'] ?? '');
        if ($url) {
            $cache_key = 'wpsd_cache_' . md5($url);
            delete_transient($cache_key . '_mobile');
            delete_transient($cache_key . '_desktop');
        }
        wp_send_json_success(['message' => 'Cache cleared!']);
    }

    /**
     * Get disabled WP Tool Box modules for suggestions
     */
    public function get_wptoolbox_disabled_modules() {
        if (!class_exists('WP_Tool_Box_Modules')) {
            return [];
        }
        $all_modules     = WP_Tool_Box_Modules::get_all_modules();
        $enabled_modules = WP_Tool_Box_Modules::get_modules();
        $disabled        = [];
        foreach ($all_modules as $slug => $module) {
            if (!in_array($slug, $enabled_modules)) {
                $disabled[] = $slug;
            }
        }
        return $disabled;
    }

    public function add_footer_notification() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wp-pagespeed-dashboard') === false) {
            return;
        }
        echo '<div id="wpsd-notification" style="display:none;"></div>';
    }
}

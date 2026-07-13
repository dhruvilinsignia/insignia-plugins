<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSD_Suggestions {

    /**
     * Map of WP Tool Box module slugs to speed-improvement suggestions
     */
    public static function get_module_suggestions() {
        return [
            'minify-html' => [
                'title'       => 'Enable HTML Minification',
                'description' => 'Minifying HTML removes unnecessary whitespace and comments, reducing page size and improving load time.',
                'impact'      => 'high',
                'savings'     => '~5-15% reduction in HTML transfer size',
                'icon'        => '⚡',
                'audit_ids'   => ['render-blocking-resources', 'uses-optimized-images'],
            ],
            'minify-css' => [
                'title'       => 'Enable CSS Minification',
                'description' => 'Minifying CSS files reduces their size by removing whitespace, comments and redundant code.',
                'impact'      => 'high',
                'savings'     => '~10-30% reduction in CSS size',
                'icon'        => '🎨',
                'audit_ids'   => ['render-blocking-resources', 'unused-css-rules'],
            ],
            'minify-js' => [
                'title'       => 'Enable JavaScript Minification',
                'description' => 'Minifying JavaScript reduces file size and speeds up parsing by browsers.',
                'impact'      => 'high',
                'savings'     => '~10-40% reduction in JS size',
                'icon'        => '📜',
                'audit_ids'   => ['render-blocking-resources', 'bootup-time'],
            ],
            'lazy-load' => [
                'title'       => 'Enable Lazy Loading for Images',
                'description' => 'Lazy loading defers loading of off-screen images, reducing initial page load time and bandwidth.',
                'impact'      => 'high',
                'savings'     => 'Significant LCP improvement',
                'icon'        => '🖼️',
                'audit_ids'   => ['offscreen-images', 'uses-lazy-loading'],
            ],
            'cache' => [
                'title'       => 'Enable Browser Caching',
                'description' => 'Setting proper cache headers allows browsers to reuse resources, drastically improving repeat visit speeds.',
                'impact'      => 'high',
                'savings'     => 'Near-instant repeat visits',
                'icon'        => '💾',
                'audit_ids'   => ['uses-long-cache-ttl'],
            ],
            'compress-images' => [
                'title'       => 'Enable Image Compression',
                'description' => 'Compressing images without quality loss can significantly reduce page weight.',
                'impact'      => 'high',
                'savings'     => '~20-80% reduction in image size',
                'icon'        => '📸',
                'audit_ids'   => ['uses-optimized-images', 'uses-webp-images'],
            ],
            'defer-js' => [
                'title'       => 'Enable JavaScript Deferral',
                'description' => 'Deferring non-critical JavaScript prevents render blocking, improving FCP and LCP.',
                'impact'      => 'medium',
                'savings'     => 'Improved FCP & TBT',
                'icon'        => '⏩',
                'audit_ids'   => ['render-blocking-resources', 'total-blocking-time'],
            ],
            'remove-query-strings' => [
                'title'       => 'Remove Query Strings from Static Resources',
                'description' => 'Query strings on static files prevent proper caching by some proxy servers.',
                'impact'      => 'low',
                'savings'     => 'Improved cache efficiency',
                'icon'        => '🔗',
                'audit_ids'   => ['uses-long-cache-ttl'],
            ],
            'disable-emojis' => [
                'title'       => 'Disable WordPress Emoji Scripts',
                'description' => 'WordPress loads emoji scripts on every page even if emojis aren\'t used. Disabling saves an extra HTTP request.',
                'impact'      => 'low',
                'savings'     => 'Saves 1 HTTP request',
                'icon'        => '😀',
                'audit_ids'   => ['bootup-time'],
            ],
            'security' => [
                'title'       => 'Security Headers (Minor Performance Impact)',
                'description' => 'Some security headers like HSTS can improve connection speed through preloading.',
                'impact'      => 'low',
                'savings'     => 'Slightly faster HTTPS connections',
                'icon'        => '🔒',
                'audit_ids'   => [],
            ],
        ];
    }

    /**
     * Get general speed suggestions based on audit failures
     */
    public static function get_general_suggestions($opportunities) {
        $suggestions = [
            [
                'id'          => 'use-cdn',
                'title'       => 'Use a Content Delivery Network (CDN)',
                'description' => 'A CDN serves your static assets from servers closest to your visitors, dramatically reducing latency.',
                'impact'      => 'high',
                'savings'     => 'Up to 50% faster for global audiences',
                'icon'        => '🌐',
                'plugin_hint' => 'Consider Cloudflare or BunnyCDN integration.',
            ],
            [
                'id'          => 'use-webp',
                'title'       => 'Convert Images to WebP Format',
                'description' => 'WebP images are 25-35% smaller than JPEG/PNG at the same quality level.',
                'impact'      => 'high',
                'savings'     => '~25-35% image size reduction',
                'icon'        => '🖼️',
                'plugin_hint' => 'Use image conversion tools or modern image CDNs.',
            ],
            [
                'id'          => 'preconnect',
                'title'       => 'Add Preconnect for Third-Party Domains',
                'description' => 'Preconnecting to third-party origins (Google Fonts, analytics) reduces DNS + TCP overhead.',
                'impact'      => 'medium',
                'savings'     => '~100-200ms improvement',
                'icon'        => '🔌',
                'plugin_hint' => 'Add <link rel="preconnect"> in your theme header.',
            ],
            [
                'id'          => 'reduce-plugins',
                'title'       => 'Reduce Number of Active Plugins',
                'description' => 'Each plugin can add database queries, scripts and styles. Audit and deactivate unused plugins.',
                'impact'      => 'medium',
                'savings'     => 'Variable — depends on plugins',
                'icon'        => '🔌',
                'plugin_hint' => 'Deactivate plugins that duplicate functionality.',
            ],
            [
                'id'          => 'php-version',
                'title'       => 'Upgrade to PHP 8.x',
                'description' => 'PHP 8.x is significantly faster than PHP 7.x, offering better performance for WordPress.',
                'impact'      => 'medium',
                'savings'     => '~10-30% faster PHP execution',
                'icon'        => '🐘',
                'plugin_hint' => 'Contact your hosting provider to upgrade PHP.',
            ],
            [
                'id'          => 'object-cache',
                'title'       => 'Enable Object Caching (Redis/Memcached)',
                'description' => 'Object caching stores database query results in memory, dramatically reducing database load.',
                'impact'      => 'high',
                'savings'     => 'Significant TTFB reduction',
                'icon'        => '🗄️',
                'plugin_hint' => 'Ask your host about Redis or Memcached support.',
            ],
        ];
        return $suggestions;
    }
}

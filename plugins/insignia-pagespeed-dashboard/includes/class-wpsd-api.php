<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPSD_API {

    const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    const CACHE_DURATION = 3600; // 1 hour

    public static function handle_analyze_ajax() {
        check_ajax_referer('wpsd_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        $url      = sanitize_url($_POST['url'] ?? '');
        $strategy = sanitize_text_field($_POST['strategy'] ?? 'mobile');
        $force    = !empty($_POST['force']);

        if (empty($url)) {
            wp_send_json_error(['message' => 'URL is required.']);
        }

        $api_key = get_option('wpsd_api_key', '');

        // Try cache first
        $cache_key = 'wpsd_cache_' . md5($url) . '_' . $strategy;
        if (!$force) {
            $cached = get_transient($cache_key);
            if ($cached) {
                wp_send_json_success(['data' => $cached, 'cached' => true]);
                return;
            }
        }

        // Build API URL
        $api_url = add_query_arg([
            'url'      => urlencode($url),
            'strategy' => $strategy,
            'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
        ], self::API_ENDPOINT);

        if (!empty($api_key)) {
            $api_url = add_query_arg('key', $api_key, $api_url);
        }

        // Handle array params for category
        $api_url = str_replace(
            ['category%5B0%5D=', 'category%5B1%5D=', 'category%5B2%5D=', 'category%5B3%5D='],
            ['category=', 'category=', 'category=', 'category='],
            $api_url
        );

        $response = wp_remote_get($api_url, [
            'timeout' => 60,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $error_msg = $data['error']['message'] ?? 'API request failed. Please check your API key.';
            wp_send_json_error(['message' => $error_msg]);
        }

        $parsed = self::parse_response($data);
        set_transient($cache_key, $parsed, self::CACHE_DURATION);
        wp_send_json_success(['data' => $parsed, 'cached' => false]);
    }

    public static function parse_response($data) {
        $cats   = $data['lighthouseResult']['categories'] ?? [];
        $audits = $data['lighthouseResult']['audits'] ?? [];
        $meta   = $data['lighthouseResult']['configSettings'] ?? [];

        // Core scores
        $scores = [
            'performance'    => isset($cats['performance']) ? round($cats['performance']['score'] * 100) : null,
            'accessibility'  => isset($cats['accessibility']) ? round($cats['accessibility']['score'] * 100) : null,
            'best_practices' => isset($cats['best-practices']) ? round($cats['best-practices']['score'] * 100) : null,
            'seo'            => isset($cats['seo']) ? round($cats['seo']['score'] * 100) : null,
        ];

        // Core Web Vitals
        $cwv = [
            'fcp'  => self::get_metric($audits, 'first-contentful-paint'),
            'lcp'  => self::get_metric($audits, 'largest-contentful-paint'),
            'tbt'  => self::get_metric($audits, 'total-blocking-time'),
            'cls'  => self::get_metric($audits, 'cumulative-layout-shift'),
            'si'   => self::get_metric($audits, 'speed-index'),
            'tti'  => self::get_metric($audits, 'interactive'),
            'ttfb' => self::get_metric($audits, 'server-response-time'),
        ];

        // Opportunities
        $opportunities = [];
        $diagnostics   = [];
        $passed        = [];

        foreach ($audits as $id => $audit) {
            if (!isset($audit['score'])) continue;
            $score = $audit['score'];
            $type  = $audit['details']['type'] ?? '';

            $item = [
                'id'          => $id,
                'title'       => $audit['title'] ?? '',
                'description' => $audit['description'] ?? '',
                'score'       => $score,
                'display_value' => $audit['displayValue'] ?? '',
                'savings'     => isset($audit['details']['overallSavingsMs']) ? round($audit['details']['overallSavingsMs']) : null,
            ];

            if ($score === null) continue;

            if ($score < 0.9 && $type === 'opportunity') {
                $opportunities[] = $item;
            } elseif ($score < 0.9 && in_array($type, ['table', 'list', 'criticalrequestchain'])) {
                $diagnostics[] = $item;
            } elseif ($score >= 0.9) {
                $passed[] = $item;
            }
        }

        // Sort by savings desc
        usort($opportunities, function($a, $b) { return ($b['savings'] ?? 0) <=> ($a['savings'] ?? 0); });

        // Resource summary
        $resources = self::get_resource_summary($audits);

        // Screenshot
        $screenshot = $audits['final-screenshot']['details']['data'] ?? null;
        $filmstrip  = [];
        if (isset($audits['screenshot-thumbnails']['details']['items'])) {
            foreach (array_slice($audits['screenshot-thumbnails']['details']['items'], 0, 8) as $frame) {
                $filmstrip[] = [
                    'timestamp' => $frame['timing'] ?? 0,
                    'data'      => $frame['data'] ?? '',
                ];
            }
        }

        $suggestions = self::build_suggestions($scores, $cwv, $opportunities, $diagnostics, $resources);

        return [
            'scores'        => $scores,
            'cwv'           => $cwv,
            'opportunities' => $opportunities,
            'diagnostics'   => $diagnostics,
            'passed'        => $passed,
            'resources'     => $resources,
            'screenshot'    => $screenshot,
            'filmstrip'     => $filmstrip,
            'strategy'      => $meta['emulatedFormFactor'] ?? 'mobile',
            'fetch_time'    => $data['lighthouseResult']['fetchTime'] ?? '',
            'suggestions'   => $suggestions,
        ];
    }

    private static function build_suggestions($scores, $cwv, $opportunities, $diagnostics, $resources) {
        $perf_score = isset($scores['performance']) ? (int) $scores['performance'] : 0;
        $all_items   = array_merge($opportunities, $diagnostics);

        $resource_by_name = function($name) use ($resources) {
            foreach ($resources as $item) {
                if (strtolower((string) ($item['type'] ?? '')) === strtolower($name)) {
                    return $item;
                }
            }
            return null;
        };

        $find_audit = function($ids) use ($all_items) {
            foreach ($ids as $id) {
                foreach ($all_items as $item) {
                    if (($item['id'] ?? '') === $id) {
                        return $item;
                    }
                }
            }
            return null;
        };

        $has_issue = function($issues, $key) {
            foreach ($issues as $issue) {
                if (($issue['key'] ?? '') === $key) {
                    return true;
                }
            }
            return false;
        };

        $metric_is_poor = function($metric, $numeric_threshold = null, $score_threshold = 50) {
            if (!is_array($metric)) {
                return false;
            }
            if (isset($metric['score']) && $metric['score'] !== null && (int) $metric['score'] <= $score_threshold) {
                return true;
            }
            if ($numeric_threshold !== null && isset($metric['numeric']) && is_numeric($metric['numeric']) && (float) $metric['numeric'] >= $numeric_threshold) {
                return true;
            }
            return false;
        };

        $issues = [];

        $image_audit = $find_audit(['uses-optimized-images', 'uses-webp-images', 'offscreen-images', 'uses-responsive-images']);
        $image_resource = $resource_by_name('image');
        if ($image_audit || ($image_resource && !empty($image_resource['size']) && (int) $image_resource['size'] > 700 * 1024)) {
            $reason = 'Large or unoptimized images are increasing total page weight and slowing visual loading.';
            if ($image_audit && !empty($image_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $image_audit['title'] . ' (' . $image_audit['display_value'] . ').';
            }
            if ($image_audit && !empty($image_audit['savings'])) {
                $reason .= ' Estimated savings: about ' . (int) $image_audit['savings'] . 'ms.';
            }
            if ($image_resource && !empty($image_resource['size_display'])) {
                $reason .= ' Current image transfer: ' . $image_resource['size_display'] . ' across ' . (int) ($image_resource['count'] ?? 0) . ' requests.';
            }
            $issues[] = [
                'key'      => 'images',
                'title'    => 'Images are one of the main reasons this page is slow',
                'reason'   => $reason,
                'action'   => 'Compress images, resize oversized banners, serve WebP/AVIF where possible, and lazy-load below-the-fold images.',
                'priority' => 95,
            ];
        }

        $render_audit = $find_audit(['render-blocking-resources']);
        if ($render_audit) {
            $reason = 'CSS or JavaScript files are blocking the browser from painting visible content quickly.';
            if (!empty($render_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $render_audit['title'] . ' (' . $render_audit['display_value'] . ').';
            }
            if (!empty($render_audit['savings'])) {
                $reason .= ' Estimated savings: about ' . (int) $render_audit['savings'] . 'ms.';
            }
            $issues[] = [
                'key'      => 'render-blocking',
                'title'    => 'Render-blocking CSS or JavaScript is delaying first paint',
                'reason'   => $reason,
                'action'   => 'Inline critical CSS, defer non-critical JavaScript, and delay or unload scripts/styles that are not needed above the fold.',
                'priority' => 94,
            ];
        }

        $server_audit = $find_audit(['server-response-time']);
        if ($server_audit || $metric_is_poor($cwv['ttfb'] ?? null, 800, 49)) {
            $reason = 'The initial server response is slower than it should be, so the page starts late.';
            if ($server_audit && !empty($server_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $server_audit['title'] . ' (' . $server_audit['display_value'] . ').';
            } elseif (!empty($cwv['ttfb']['display'])) {
                $reason .= ' Current TTFB: ' . $cwv['ttfb']['display'] . '.';
            }
            $issues[] = [
                'key'      => 'server-response',
                'title'    => 'Server response time is hurting the initial load',
                'reason'   => $reason,
                'action'   => 'Use full-page caching, reduce heavy backend work, review hosting performance, and consider object caching such as Redis.',
                'priority' => 93,
            ];
        }

        $unused_js_audit = $find_audit(['unused-javascript', 'bootup-time', 'mainthread-work-breakdown']);
        $script_resource = $resource_by_name('script');
        if ($unused_js_audit || ($script_resource && !empty($script_resource['size']) && (int) $script_resource['size'] > 500 * 1024) || $metric_is_poor($cwv['tbt'] ?? null, 300, 49)) {
            $reason = 'JavaScript download or execution is keeping the main thread busy.';
            if ($unused_js_audit && !empty($unused_js_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $unused_js_audit['title'] . ' (' . $unused_js_audit['display_value'] . ').';
            }
            if ($unused_js_audit && !empty($unused_js_audit['savings'])) {
                $reason .= ' Estimated savings: about ' . (int) $unused_js_audit['savings'] . 'ms.';
            }
            if ($script_resource && !empty($script_resource['size_display'])) {
                $reason .= ' Current JS transfer: ' . $script_resource['size_display'] . ' across ' . (int) ($script_resource['count'] ?? 0) . ' requests.';
            }
            $issues[] = [
                'key'      => 'unused-js',
                'title'    => 'JavaScript is contributing heavily to the slowdown',
                'reason'   => $reason,
                'action'   => 'Delay non-essential JS, unload plugin scripts where not needed, reduce third-party widgets, and trim large bundles.',
                'priority' => 92,
            ];
        }

        $unused_css_audit = $find_audit(['unused-css-rules']);
        if ($unused_css_audit) {
            $reason = 'Unused CSS is being delivered even though the current page does not need all of it.';
            if (!empty($unused_css_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $unused_css_audit['title'] . ' (' . $unused_css_audit['display_value'] . ').';
            }
            if (!empty($unused_css_audit['savings'])) {
                $reason .= ' Estimated savings: about ' . (int) $unused_css_audit['savings'] . 'ms.';
            }
            $issues[] = [
                'key'      => 'unused-css',
                'title'    => 'Unused CSS is increasing page weight',
                'reason'   => $reason,
                'action'   => 'Remove unused CSS from themes/plugins, load page-specific styles only where needed, and reduce global builder CSS.',
                'priority' => 86,
            ];
        }

        $cache_audit = $find_audit(['uses-long-cache-ttl']);
        if ($cache_audit) {
            $reason = 'Static files are not cached aggressively enough, so browsers are downloading them more often than necessary.';
            if (!empty($cache_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $cache_audit['title'] . ' (' . $cache_audit['display_value'] . ').';
            }
            $issues[] = [
                'key'      => 'long-cache',
                'title'    => 'Caching headers for static assets need improvement',
                'reason'   => $reason,
                'action'   => 'Set long cache TTL for versioned CSS, JS, images, and fonts, and make sure CDN/cache headers are active.',
                'priority' => 84,
            ];
        }

        $third_party_audit = $find_audit(['third-party-summary']);
        if ($third_party_audit) {
            $reason = 'External tools are adding extra network and execution cost.';
            if (!empty($third_party_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $third_party_audit['title'] . ' (' . $third_party_audit['display_value'] . ').';
            }
            $issues[] = [
                'key'      => 'third-party',
                'title'    => 'Third-party requests are slowing the page',
                'reason'   => $reason,
                'action'   => 'Remove non-essential third-party tools, self-host fonts when practical, and delay chat/widgets/trackers until needed.',
                'priority' => 80,
            ];
        }

        $dom_audit = $find_audit(['dom-size']);
        if ($dom_audit) {
            $reason = 'The page DOM is larger or deeper than ideal, which can slow rendering and scripting.';
            if (!empty($dom_audit['display_value'])) {
                $reason .= ' Lighthouse flag: ' . $dom_audit['title'] . ' (' . $dom_audit['display_value'] . ').';
            }
            $issues[] = [
                'key'      => 'dom-size',
                'title'    => 'Page structure is heavier than ideal',
                'reason'   => $reason,
                'action'   => 'Reduce excessive wrappers, repeated sections, hidden blocks, and deeply nested builder markup.',
                'priority' => 72,
            ];
        }

        if ($metric_is_poor($cwv['cls'] ?? null, 0.1, 49) && !$has_issue($issues, 'layout-shift')) {
            $reason = 'Elements are shifting while the page loads.';
            if (!empty($cwv['cls']['display'])) {
                $reason .= ' Current CLS: ' . $cwv['cls']['display'] . '.';
            }
            $issues[] = [
                'key'      => 'layout-shift',
                'title'    => 'Layout shift is affecting visual stability',
                'reason'   => $reason,
                'action'   => 'Set image/embed dimensions, reserve space for dynamic content, and optimize font loading to reduce visual jumps.',
                'priority' => 79,
            ];
        }

        $font_resource = $resource_by_name('font');
        if ($font_resource && !empty($font_resource['size']) && (int) $font_resource['size'] > 200 * 1024) {
            $issues[] = [
                'key'      => 'fonts',
                'title'    => 'Font files are adding noticeable transfer weight',
                'reason'   => 'Fonts currently use ' . ($font_resource['size_display'] ?? self::format_bytes((int) $font_resource['size'])) . ' across ' . (int) ($font_resource['count'] ?? 0) . ' requests.',
                'action'   => 'Reduce font families and weights, preload only critical files, and self-host fonts when practical.',
                'priority' => 58,
            ];
        }

        usort($issues, function($a, $b) {
            return ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        });

        $issues = array_slice($issues, 0, 6);
        $summary = [];
        $actions = [];

        if (empty($issues)) {
            $summary[] = [
                'title'  => 'No strong bottleneck was isolated from this report',
                'reason' => 'This specific page did not surface a single dominant issue. Review your biggest assets, third-party scripts, and caching setup for incremental gains.',
            ];
        } else {
            foreach (array_slice($issues, 0, 3) as $index => $issue) {
                $summary[] = [
                    'title'  => ($index + 1) . '. ' . $issue['title'],
                    'reason' => $issue['reason'],
                ];
            }
        }

        $actions[] = [
            'title'  => 'Overall diagnosis for this URL',
            'reason' => 'Performance score: ' . $perf_score . '. The recommendations below are built from the failed Lighthouse audits and actual resource weight for this specific page.',
            'action' => '',
        ];

        foreach ($issues as $index => $issue) {
            $actions[] = [
                'title'  => 'Priority ' . ($index + 1) . ': ' . $issue['title'],
                'reason' => $issue['reason'],
                'action' => $issue['action'],
            ];
        }

        if (empty($issues)) {
            $actions[] = [
                'title'  => 'Suggested next step',
                'reason' => 'Check the Opportunities section and Resource Breakdown chart to see where most transfer size or estimated savings are concentrated for this page.',
                'action' => '',
            ];
        }

        return [
            'summary' => $summary,
            'actions' => $actions,
            'issues'  => $issues,
        ];
    }

    private static function get_metric($audits, $key) {
        if (!isset($audits[$key])) return null;
        $a = $audits[$key];
        return [
            'display' => $a['displayValue'] ?? '',
            'numeric' => $a['numericValue'] ?? 0,
            'score'   => isset($a['score']) ? round($a['score'] * 100) : null,
            'title'   => $a['title'] ?? '',
        ];
    }

    private static function get_resource_summary($audits) {
        if (!isset($audits['resource-summary']['details']['items'])) return [];
        $result = [];
        foreach ($audits['resource-summary']['details']['items'] as $item) {
            $result[] = [
                'type'         => $item['label'] ?? '',
                'size'         => $item['transferSize'] ?? 0,
                'count'        => $item['requestCount'] ?? 0,
                'size_display' => self::format_bytes($item['transferSize'] ?? 0),
            ];
        }
        return $result;
    }

    public static function format_bytes($bytes) {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}

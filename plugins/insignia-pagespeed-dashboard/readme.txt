=== Insignia PageSpeed Dashboard ===
Contributors:      insigniatechnolabs
Tags:              pagespeed, performance, lighthouse, core web vitals, seo
Requires at least: 5.6
Tested up to:      6.5
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A powerful PageSpeed analyzer dashboard with Google PageSpeed Insights integration — Core Web Vitals, opportunities, and actionable recommendations right inside WordPress.

== Description ==

**Insignia PageSpeed Dashboard** brings Google PageSpeed Insights directly into your WordPress admin area. Analyze any URL on your site (or any URL), see Core Web Vitals, opportunities, diagnostics, and get actionable recommendations — all without leaving WordPress.

= Key Features =

* **One-click PageSpeed analysis** — analyze any URL using the Google PageSpeed Insights API.
* **Mobile & Desktop modes** — switch between strategies with a single click.
* **Core Web Vitals (CWV)** — FCP, LCP, TBT, CLS, Speed Index, TTI, and TTFB at a glance.
* **Score gauges** — animated circular score cards for Performance, Accessibility, Best Practices, and SEO.
* **Interactive charts** — Score Overview bar chart and Resource Breakdown doughnut chart powered by Chart.js.
* **Page Load Filmstrip** — visual timeline of how the page renders.
* **Opportunities & Diagnostics** — prioritized list of improvements with estimated time savings.
* **Passed Audits** — collapsible list of what is already working well.
* **Analysis History** — stored locally in the browser so you can track changes over time.
* **Speed Improvement Suggestions** — general recommendations for WordPress performance.
* **1-hour result caching** — avoids burning through API quota; force-refresh available.
* **Optional Google API Key** — works without a key (rate-limited) or with your own key for unlimited requests.
* **Secure** — all AJAX requests use nonces; all inputs are sanitized; only administrators can access the dashboard.
* **Clean uninstall** — deletes all plugin data (options + transients) when removed.

= WP Tool Box Integration =

If you have the WP Tool Box plugin installed, the Suggestions section automatically highlights which WP Tool Box modules could help improve your score.

== Installation ==

1. Upload the `insignia-pagespeed-dashboard` folder to the `/wp-content/plugins/` directory, **or** install directly from the WordPress Plugins screen (Plugins > Add New > Upload Plugin).
2. Activate the plugin from the **Plugins** screen.
3. Navigate to **PageSpeed** in the WordPress admin menu.
4. *(Optional but recommended)* Add your free Google PageSpeed API key under **Settings** to avoid API rate limits.

= Getting a Free Google PageSpeed API Key =

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project (or select an existing one).
3. Enable the **PageSpeed Insights API**.
4. Create an **API key** under Credentials.
5. Paste the key in **PageSpeed > Settings > Google PageSpeed API Key**.

== Frequently Asked Questions ==

= Does the plugin work without an API key? =

Yes. Without an API key the plugin uses the public Google PageSpeed Insights endpoint, which is rate-limited to a few requests per 100 seconds per IP. For regular use we recommend adding your own free API key.

= What data does the plugin store? =

* **`wpsd_api_key`** — your Google API key, stored in `wp_options`.
* **`_transient_wpsd_cache_*`** — cached PageSpeed results (expire after 1 hour).
* **Analysis history** — stored in browser `localStorage` only; never sent to the server.

All data is removed automatically when you delete the plugin.

= Is this plugin multisite compatible? =

The plugin works on standard WordPress installations. Multisite cleanup is handled on uninstall (options and transients are removed from all tables).

= Can I analyze pages on other websites? =

Yes — just type any public URL into the Analyzer input.

= How do I clear the cache for a specific URL? =

Use the **Refresh** button (circular arrows) that appears after the first analysis. It bypasses the cache and fetches fresh data.

== Screenshots ==

1. Dashboard — Score gauges for Performance, Accessibility, Best Practices, and SEO.
2. Core Web Vitals — FCP, LCP, TBT, CLS, Speed Index, TTI, TTFB.
3. Charts — Score overview bar chart and resource breakdown doughnut chart.
4. Opportunities — prioritized list of improvements with estimated savings.
5. Suggestions — general WordPress performance recommendations.
6. Settings — API key input with show/hide toggle.

== Changelog ==

= 1.0.0 =
* Initial release.
* Google PageSpeed Insights API integration (v5 / Lighthouse).
* Mobile and Desktop analysis strategies.
* Core Web Vitals display.
* Score gauges with animated SVG circles.
* Interactive Chart.js bar and doughnut charts.
* Page Load Filmstrip.
* Opportunities, Diagnostics, and Passed Audits sections.
* Browser-based Analysis History.
* Speed Improvement Suggestions panel.
* 1-hour transient caching with force-refresh.
* Optional Google PageSpeed API key support.
* WP Tool Box integration for module suggestions.
* Secure AJAX handlers with nonce verification.
* Full uninstall cleanup (options + transients).

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.

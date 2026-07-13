<?php
if (!defined('ABSPATH')) exit;
$api_key  = get_option('wpsd_api_key', '');
$site_url = get_site_url();
?>

<!-- Header matching WP Tool Box style -->
<section class="wpsd-header-section">
    <header class="wpsd-header">
        <div class="wpsd-header-inner">
            <div class="wpsd-logo-wrap">
                <div class="wpsd-logo-img">
                    <svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#0f62cc" stroke-width="1.5"/>
                        <path d="M12 6v6l4 2" stroke="#0f62cc" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <h1 class="wpsd-h1">PageSpeed Dashboard</h1>
                </div>
                <div class="wpsd-header-actions">
                    <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" class="wpsd-btn-outline">Get API Key</a>
                    <a href="https://pagespeed.web.dev/" target="_blank" class="wpsd-btn-primary">PageSpeed Docs</a>
                </div>
            </div>
        </div>
    </header>
</section>

<div class="wrap wpsd-main">
    <div class="wpsd-layout">

        <!-- ===== LEFT SIDEBAR — single Dashboard tab ===== -->
        <div class="wpsd-sidebar">
            <div class="wpsd-tab active">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                </svg>
                Dashboard
            </div>
        </div>

        <!-- ===== MAIN CONTENT ===== -->
        <div class="wpsd-content">

            <!-- STICKY NAV BAR -->
            <div class="wpsd-sticky-nav" id="wpsd-sticky-nav">
                <div class="wpsd-sticky-nav-inner">
                    <a class="wpsd-nav-item active" href="#section-analyzer" data-target="section-analyzer">Analyzer</a>
                    <span class="wpsd-nav-separator"></span>
                    <a class="wpsd-nav-item" href="#section-suggestions" data-target="section-suggestions">Suggestions</a>
                    <span class="wpsd-nav-separator"></span>
                    <a class="wpsd-nav-item" href="#section-history" data-target="section-history">History</a>
                    <span class="wpsd-nav-separator"></span>
                    <a class="wpsd-nav-item" href="#section-settings" data-target="section-settings">Settings</a>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SECTION 1 — ANALYZER                                          -->
            <!-- ============================================================ -->
            <div class="wpsd-scroll-section" id="section-analyzer">
                <div class="wpsd-section-header">
                    <div class="wpsd-section-title">Page Speed Analyzer</div>
                    <div class="wpsd-device-tabs">
                        <button class="wpsd-device-tab active" data-strategy="mobile">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                            Mobile
                        </button>
                        <button class="wpsd-device-tab" data-strategy="desktop">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            Desktop
                        </button>
                    </div>
                </div>

                <!-- URL Bar -->
                <div class="wpsd-url-bar">
                    <div class="wpsd-url-input-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <input type="url" id="wpsd-url-input" placeholder="Enter URL to analyze (e.g. https://yoursite.com/page)" value="<?php echo esc_attr($site_url); ?>">
                    </div>
                    <button id="wpsd-analyze-btn" class="wpsd-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Analyze
                    </button>
                    <button id="wpsd-refresh-btn" class="wpsd-btn-outline" style="display:none;" title="Force refresh (bypass cache)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                        Refresh
                    </button>
                </div>

                <?php if (empty($api_key)): ?>
                <div class="wpsd-notice wpsd-notice-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <div><strong>No API Key set.</strong> Analysis works with rate limits. <a class="wpsd-scroll-link" data-target="section-settings" href="#section-settings">Add your Google PageSpeed API key →</a></div>
                </div>
                <?php endif; ?>

                <!-- Loading -->
                <div id="wpsd-loading" style="display:none;">
                    <div class="wpsd-loading-wrap">
                        <div class="wpsd-spinner"></div>
                        <div class="wpsd-loading-text">
                            <h3>Analyzing page speed...</h3>
                            <p>Running Lighthouse audit via Google PageSpeed Insights API</p>
                        </div>
                        <div class="wpsd-loading-steps">
                            <div class="wpsd-step active" id="step-1">🔍 Fetching page</div>
                            <div class="wpsd-step" id="step-2">⚡ Running performance audit</div>
                            <div class="wpsd-step" id="step-3">🎯 Analyzing opportunities</div>
                            <div class="wpsd-step" id="step-4">📊 Generating report</div>
                        </div>
                    </div>
                </div>

                <!-- Results -->
                <div id="wpsd-results" style="display:none;">
                    <div class="wpsd-scores-row">
                        <?php foreach (['performance'=>'Performance','accessibility'=>'Accessibility','best_practices'=>'Best Practices','seo'=>'SEO'] as $k => $label): ?>
                        <div class="wpsd-score-card" id="score-<?php echo $k; ?>">
                            <div class="wpsd-score-circle">
                                <svg viewBox="0 0 120 120" class="wpsd-circle-svg">
                                    <circle cx="60" cy="60" r="54" class="wpsd-circle-bg"/>
                                    <circle cx="60" cy="60" r="54" class="wpsd-circle-fill" id="circle-<?php echo $k; ?>"/>
                                </svg>
                                <span class="wpsd-score-num" id="num-<?php echo $k; ?>">–</span>
                            </div>
                            <div class="wpsd-score-label"><?php echo $label; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="wpsd-score-legend">
                        <span class="wpsd-legend-item poor">0–49 Poor</span>
                        <span class="wpsd-legend-item needs">50–89 Needs Improvement</span>
                        <span class="wpsd-legend-item good">90–100 Good</span>
                    </div>
                    <div class="wpsd-section-card">
                        <h3 class="wpsd-card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            Core Web Vitals
                        </h3>
                        <div class="wpsd-cwv-grid" id="wpsd-cwv-grid"></div>
                    </div>
                    <div class="wpsd-charts-row">
                        <div class="wpsd-section-card wpsd-chart-card">
                            <h3 class="wpsd-card-title"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Score Overview</h3>
                            <div class="wpsd-chart-wrap"><canvas id="chart-scores" height="260"></canvas></div>
                        </div>
                        <div class="wpsd-section-card wpsd-chart-card">
                            <h3 class="wpsd-card-title"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Resource Breakdown</h3>
                            <div class="wpsd-chart-wrap"><canvas id="chart-resources" height="260"></canvas></div>
                        </div>
                    </div>
                    <div class="wpsd-section-card" id="wpsd-filmstrip-card" style="display:none;">
                        <h3 class="wpsd-card-title"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><rect x="2" y="7" width="20" height="15" rx="2"/><polyline points="17 2 12 7 7 2"/></svg> Page Load Filmstrip</h3>
                        <div class="wpsd-filmstrip" id="wpsd-filmstrip"></div>
                    </div>
                    <div class="wpsd-section-card">
                        <h3 class="wpsd-card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e8a020" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            Opportunities <span class="wpsd-badge wpsd-badge-warning" id="badge-opportunities">0</span>
                        </h3>
                        <p class="wpsd-card-subtitle">These suggestions can help your page load faster.</p>
                        <div id="wpsd-opportunities-list" class="wpsd-audit-list"></div>
                    </div>
                    <div class="wpsd-section-card">
                        <h3 class="wpsd-card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Diagnostics <span class="wpsd-badge wpsd-badge-info" id="badge-diagnostics">0</span>
                        </h3>
                        <p class="wpsd-card-subtitle">Additional information about your page performance.</p>
                        <div id="wpsd-diagnostics-list" class="wpsd-audit-list"></div>
                    </div>
                    <div class="wpsd-section-card">
                        <h3 class="wpsd-card-title wpsd-collapsible" id="toggle-passed">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Passed Audits <span class="wpsd-badge wpsd-badge-success" id="badge-passed">0</span>
                            <svg class="wpsd-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </h3>
                        <div id="wpsd-passed-list" class="wpsd-audit-list" style="display:none;"></div>
                    </div>
                    <div class="wpsd-section-card" id="wpsd-screenshot-card" style="display:none;">
                        <h3 class="wpsd-card-title"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f62cc" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg> Final Screenshot</h3>
                        <div class="wpsd-screenshot-wrap"><img id="wpsd-screenshot-img" src="" alt="Page screenshot"></div>
                    </div>
                    <div class="wpsd-meta-bar" id="wpsd-meta-bar">
                        <span id="wpsd-meta-strategy"></span>
                        <span id="wpsd-meta-time"></span>
                        <span id="wpsd-meta-cached"></span>
                    </div>
                </div>

                <!-- Empty state -->
                <div id="wpsd-empty" class="wpsd-empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <h3>Enter a URL to analyze</h3>
                    <p>Get detailed PageSpeed Insights including Core Web Vitals, opportunities, and actionable recommendations.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SECTION 2 — SUGGESTIONS                                       -->
            <!-- ============================================================ -->
            <div class="wpsd-scroll-section" id="section-suggestions">
                <div class="wpsd-section-header">
                    <div class="wpsd-section-title">Speed Improvement Suggestions</div>
                </div>
                <div class="wpsd-section-card">
                    <h3 class="wpsd-card-title">Problem Summary</h3>
                    <p class="wpsd-card-subtitle">The plugin will first detect the main reasons your site is slow, then suggest the most useful fixes.</p>
                    <div id="wpsd-problem-summary" class="wpsd-suggestions-scroller">
                        <div class="wpsd-suggestion-row">
                            <h4>Run an analysis to see what is slowing the site down</h4>
                            <p>This section will explain the likely reasons for the low score and show focused actions based on the PageSpeed report.</p>
                        </div>
                    </div>
                </div>
                <div class="wpsd-section-card">
                    <h3 class="wpsd-card-title">Recommended Actions</h3>
                    <div id="wpsd-dynamic-suggestions" class="wpsd-suggestions-scroller">
                        <div class="wpsd-suggestion-row">
                            <h4>Targeted fixes will appear here</h4>
                            <p>Suggestions will be generated from your actual Lighthouse opportunities, diagnostics, Core Web Vitals, and resource breakdown.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SECTION 3 — HISTORY                                           -->
            <!-- ============================================================ -->
            <div class="wpsd-scroll-section" id="section-history">
                <div class="wpsd-section-header">
                    <div class="wpsd-section-title">Analysis History</div>
                    <button id="wpsd-clear-history" class="wpsd-btn-outline wpsd-btn-danger">Clear History</button>
                </div>
                <div class="wpsd-section-card">
                    <div id="wpsd-history-list">
                        <div class="wpsd-empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
                            <h3>No analyses yet</h3>
                            <p>Your analysis history will appear here after running your first analysis.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SECTION 4 — SETTINGS                                          -->
            <!-- ============================================================ -->
            <div class="wpsd-scroll-section" id="section-settings">
                <div class="wpsd-section-header">
                    <div class="wpsd-section-title">Settings</div>
                </div>
                <div class="wpsd-section-card">
                    <h3 class="wpsd-card-title">Google PageSpeed API Key</h3>
                    <p class="wpsd-card-subtitle">Add your API key to get unlimited analysis requests.</p>
                    <div class="wpsd-settings-form">
                        <div class="wpsd-form-group">
                            <label>API Key</label>
                            <div class="wpsd-api-key-wrap">
                                <input type="password" id="wpsd-api-key-input" value="<?php echo esc_attr($api_key); ?>" placeholder="AIzaSy...">
                                <button id="wpsd-toggle-key" class="wpsd-btn-outline wpsd-btn-sm" type="button">Show</button>
                            </div>
                            <p class="wpsd-help-text"><a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">How to get a free API key →</a></p>
                        </div>
                        <button id="wpsd-save-api-key" class="wpsd-btn-primary">Save API Key</button>
                    </div>
                </div>
            </div>

        </div><!-- /.wpsd-content -->
    </div><!-- /.wpsd-layout -->
</div><!-- /.wrap -->

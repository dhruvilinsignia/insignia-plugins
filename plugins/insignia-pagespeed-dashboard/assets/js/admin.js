/* global wpsd_data, Chart */
(function($) {
    'use strict';

    let chartScores    = null;
    let chartResources = null;
    let history        = JSON.parse(localStorage.getItem('wpsd_history') || '[]');
    let currentStrategy = 'mobile';
    let stepTimer       = null;
    let scrollSpyActive = true;
    let parallaxOrbs    = [];
    let ticking         = false;

    // ==================== INIT ====================
    $(document).ready(function() {
        initStickyNav();
        initScrollSpy();
        initDeviceTabs();
        initURLBar();
        initButtons();
        initPassedToggle();
        renderHistory();
        initSettings();
        initHeaderOrbs();
        initScrollReveal();
        initRippleButtons();
        initParallaxOnScroll();
    });

    // ==================== HEADER ANIMATED ORBS ====================
    function initHeaderOrbs() {
        const header = document.querySelector('.wpsd-header');
        if (!header) return;

        // Inject orb wrapper if not present
        let wrap = header.querySelector('.ipsd-orbs-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'ipsd-orbs-wrap';
            header.insertBefore(wrap, header.firstChild);
        }

        // Create 4 floating orbs
        const orbDefs = [
            { cls: 'ipsd-orb ipsd-orb-1 ipsd-parallax', depth: 0.06 },
            { cls: 'ipsd-orb ipsd-orb-2 ipsd-parallax', depth: 0.10 },
            { cls: 'ipsd-orb ipsd-orb-3 ipsd-parallax', depth: 0.15 },
            { cls: 'ipsd-orb ipsd-orb-4 ipsd-parallax', depth: 0.04 },
        ];
        orbDefs.forEach(function(def) {
            const orb = document.createElement('div');
            orb.className = def.cls;
            orb.dataset.depth = def.depth;
            wrap.appendChild(orb);
            parallaxOrbs.push(orb);
        });

        // Mouse-based parallax on the header
        header.addEventListener('mousemove', function(e) {
            const rect   = header.getBoundingClientRect();
            const cx     = rect.left + rect.width  / 2;
            const cy     = rect.top  + rect.height / 2;
            const dx     = (e.clientX - cx);
            const dy     = (e.clientY - cy);
            parallaxOrbs.forEach(function(orb) {
                const depth = parseFloat(orb.dataset.depth);
                orb.style.transform = `translate(${dx * depth}px, ${dy * depth}px)`;
            });
        });
        header.addEventListener('mouseleave', function() {
            parallaxOrbs.forEach(function(orb) {
                orb.style.transform = '';
            });
        });
    }

    // ==================== SCROLL PARALLAX ====================
    function initParallaxOnScroll() {
        // Subtle vertical parallax for orbs and cards on page scroll
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    const scrollY = window.pageYOffset;
                    // Sidebar glides slightly
                    const sidebar = document.querySelector('.wpsd-sidebar');
                    if (sidebar) {
                        sidebar.style.transform = `translateY(${scrollY * 0.018}px)`;
                    }
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    // ==================== SCROLL REVEAL ====================
    function initScrollReveal() {
        // Assign reveal classes to major elements
        const revealSelectors = [
            { sel: '.wpsd-score-card',    cls: 'ipsd-reveal ipsd-reveal-scale' },
            { sel: '.wpsd-cwv-item',      cls: 'ipsd-reveal' },
            { sel: '.wpsd-section-card',  cls: 'ipsd-reveal' },
            { sel: '.wpsd-history-item',  cls: 'ipsd-reveal-left' },
            { sel: '.wpsd-suggestion-row',cls: 'ipsd-reveal-left' },
            { sel: '.wpsd-audit-item',    cls: 'ipsd-reveal' },
        ];

        // We use MutationObserver so dynamically added nodes also get revealed
        function tagForReveal(container) {
            revealSelectors.forEach(function(item) {
                container.querySelectorAll(item.sel).forEach(function(el, i) {
                    if (!el.classList.contains('ipsd-reveal') &&
                        !el.classList.contains('ipsd-reveal-left') &&
                        !el.classList.contains('ipsd-reveal-scale')) {
                        item.cls.split(' ').forEach(function(c) { el.classList.add(c); });
                    }
                    // Add stagger delay (cycle through 7 delays)
                    const delayClass = 'ipsd-delay-' + ((i % 7) + 1);
                    if (!el.classList.contains(delayClass)) {
                        el.classList.add(delayClass);
                    }
                });
            });
            observeRevealElements(container);
        }

        tagForReveal(document);

        // Watch for DOM changes (audit items, cwv items, etc.)
        const mo = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) tagForReveal(node);
                });
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
    }

    function observeRevealElements(container) {
        if (!('IntersectionObserver' in window)) {
            // Fallback: reveal immediately
            container.querySelectorAll('.ipsd-reveal, .ipsd-reveal-left, .ipsd-reveal-scale').forEach(function(el) {
                el.classList.add('ipsd-revealed');
            });
            return;
        }
        const io = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('ipsd-revealed');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

        container.querySelectorAll('.ipsd-reveal, .ipsd-reveal-left, .ipsd-reveal-scale').forEach(function(el) {
            if (!el.classList.contains('ipsd-revealed')) io.observe(el);
        });
    }

    // ==================== RIPPLE BUTTONS ====================
    function initRippleButtons() {
        $(document).on('click', '.wpsd-btn-primary, .wpsd-btn-outline', function(e) {
            const btn  = this;
            const rect = btn.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height) * 1.6;
            const x    = e.clientX - rect.left - size / 2;
            const y    = e.clientY - rect.top  - size / 2;

            const ripple = document.createElement('span');
            ripple.className = 'ipsd-ripple';
            ripple.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px`;
            btn.appendChild(ripple);
            setTimeout(function() { ripple.remove(); }, 600);
        });
    }

    // ==================== STICKY NAV ====================
    function initStickyNav() {
        const nav      = document.getElementById('wpsd-sticky-nav');
        const adminBar = document.getElementById('wpadminbar');
        if (!nav) return;

        $('.wpsd-nav-item').on('click', function(e) {
            e.preventDefault();
            scrollToSection($(this).data('target'));
        });

        $(document).on('click', '.wpsd-scroll-link', function(e) {
            e.preventDefault();
            const id = $(this).data('target') || ($(this).attr('href') || '').replace('#', '');
            if (id) scrollToSection(id);
        });

        $(window).on('scroll.wpsdNav', function() {
            const adminBarH = adminBar ? adminBar.offsetHeight : 0;
            const navRect   = nav.getBoundingClientRect();
            nav.classList.toggle('is-stuck', navRect.top <= adminBarH + 1);
        });
    }

    function scrollToSection(sectionId) {
        const el = document.getElementById(sectionId);
        if (!el) return;
        scrollSpyActive = false;
        setActiveNavItem(sectionId);
        const adminBarH = (document.getElementById('wpadminbar') || { offsetHeight: 32 }).offsetHeight;
        const navH      = (document.getElementById('wpsd-sticky-nav') || { offsetHeight: 54 }).offsetHeight;
        const top       = el.getBoundingClientRect().top + window.pageYOffset - adminBarH - navH - 8;
        window.scrollTo({ top, behavior: 'smooth' });
        setTimeout(function() { scrollSpyActive = true; }, 900);
    }

    function setActiveNavItem(sectionId) {
        $('.wpsd-nav-item').removeClass('active');
        $(`.wpsd-nav-item[data-target="${sectionId}"]`).addClass('active');
    }

    // ==================== SCROLL SPY ====================
    function initScrollSpy() {
        const sections  = document.querySelectorAll('.wpsd-scroll-section');
        const adminBarH = (document.getElementById('wpadminbar') || { offsetHeight: 32 }).offsetHeight;
        const navH      = 54;

        if (!('IntersectionObserver' in window)) {
            $(window).on('scroll.wpsdSpy', function() {
                if (!scrollSpyActive) return;
                let current = null;
                sections.forEach(function(sec) {
                    if (sec.getBoundingClientRect().top <= adminBarH + navH + 40) current = sec.id;
                });
                if (current) setActiveNavItem(current);
            });
            return;
        }

        const observer = new IntersectionObserver(function(entries) {
            if (!scrollSpyActive) return;
            entries.forEach(function(entry) {
                if (entry.isIntersecting) setActiveNavItem(entry.target.id);
            });
        }, {
            rootMargin: `${-(adminBarH + navH + 10)}px 0px -60% 0px`,
            threshold: 0
        });
        sections.forEach(function(s) { observer.observe(s); });
    }

    // ==================== DEVICE TABS ====================
    function initDeviceTabs() {
        $('.wpsd-device-tab').on('click', function() {
            currentStrategy = $(this).data('strategy');
            $('.wpsd-device-tab').removeClass('active');
            $(this).addClass('active');
        });
    }

    // ==================== URL BAR ====================
    function initURLBar() {
        $('#wpsd-analyze-btn').on('click', function() {
            const url = $('#wpsd-url-input').val().trim();
            if (!url) { showNotification('Please enter a URL to analyze.', 'error'); return; }
            runAnalysis(url, currentStrategy, false);
        });
        $('#wpsd-refresh-btn').on('click', function() {
            runAnalysis($('#wpsd-url-input').val().trim(), currentStrategy, true);
        });
        $('#wpsd-url-input').on('keypress', function(e) {
            if (e.which === 13) $('#wpsd-analyze-btn').trigger('click');
        });
    }

    // ==================== ANALYSIS ====================
    function runAnalysis(url, strategy, force) {
        $('#wpsd-empty').hide();
        $('#wpsd-results').hide();
        $('#wpsd-loading').show();
        $('#wpsd-analyze-btn').prop('disabled', true)
            .html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg> Analyzing...');

        animateLoadingSteps();

        $.ajax({
            url:     wpsd_data.ajax_url,
            method:  'POST',
            timeout: 90000,
            data: {
                action:   'wpsd_analyze',
                nonce:    wpsd_data.nonce,
                url:      url,
                strategy: strategy,
                force:    force ? 1 : 0,
            },
            success: function(response) {
                stopLoadingSteps();
                $('#wpsd-loading').hide();
                resetAnalyzeBtn();
                if (response.success) {
                    renderResults(response.data.data, url, response.data.cached);
                    saveToHistory(url, response.data.data, strategy);
                    renderHistory();
                    $('#wpsd-refresh-btn').show();
                } else {
                    showNotification('Error: ' + (response.data.message || 'Analysis failed.'), 'error');
                    $('#wpsd-empty').show();
                }
            },
            error: function(xhr, status) {
                stopLoadingSteps();
                $('#wpsd-loading').hide();
                resetAnalyzeBtn();
                showNotification(status === 'timeout' ? 'Request timed out.' : 'Analysis failed.', 'error');
                $('#wpsd-empty').show();
            }
        });
    }

    function resetAnalyzeBtn() {
        $('#wpsd-analyze-btn').prop('disabled', false)
            .html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg> Analyze');
    }

    function animateLoadingSteps() {
        let step = 1;
        $('.wpsd-step').removeClass('active done');
        $('#step-1').addClass('active');
        stepTimer = setInterval(function() {
            $(`#step-${step}`).removeClass('active').addClass('done');
            step++;
            if (step <= 4) $(`#step-${step}`).addClass('active');
            if (step > 4) clearInterval(stepTimer);
        }, 5000);
    }

    function stopLoadingSteps() {
        if (stepTimer) { clearInterval(stepTimer); stepTimer = null; }
        $('.wpsd-step').addClass('done').removeClass('active');
    }

    // ==================== RENDER ====================
    function renderResults(data, url, cached) {
        renderScores(data.scores);
        renderCWV(data.cwv);
        renderCharts(data.scores, data.resources);
        renderAudits('opportunities', data.opportunities);
        renderAudits('diagnostics', data.diagnostics);
        renderAudits('passed', data.passed);
        renderSuggestions(data);
        renderFilmstrip(data.filmstrip);
        renderScreenshot(data.screenshot);
        renderMeta(data, url, cached);
        $('#wpsd-results').show();
        // Re-observe newly added elements
        observeRevealElements(document.getElementById('wpsd-results'));
    }

    // ==================== SCORES + COUNTER ANIMATION ====================
    function renderScores(scores) {
        const circumference = 339.3;
        ['performance', 'accessibility', 'best_practices', 'seo'].forEach(function(key) {
            const score = scores[key];
            const card  = $(`#score-${key}`);
            card.removeClass('score-good score-needs score-poor ipsd-score-glow');
            if (score === null) { $(`#num-${key}`).text('N/A'); return; }
            const cls = score >= 90 ? 'good' : score >= 50 ? 'needs' : 'poor';
            card.addClass(`score-${cls}`);

            // Animated counter from 0 → score
            animateCounter($(`#num-${key}`)[0], 0, score, 1400);

            // Animate SVG ring
            setTimeout(function() {
                $(`#circle-${key}`).css('stroke-dashoffset', circumference * (1 - score / 100));
                // Add glow after ring fills
                setTimeout(function() { card.addClass('ipsd-score-glow'); }, 1500);
            }, 100);
        });
    }

    function animateCounter(el, from, to, duration) {
        if (!el) return;
        const start     = performance.now();
        const diff      = to - from;
        const ease      = function(t) { return t < .5 ? 2*t*t : -1+(4-2*t)*t; }; // ease-in-out quad

        function step(now) {
            const elapsed  = now - start;
            const progress = Math.min(elapsed / duration, 1);
            el.textContent = Math.round(from + diff * ease(progress));
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    // ==================== CWV ====================
    function renderCWV(cwv) {
        const metrics = [
            { key: 'fcp',  label: 'FCP'  },
            { key: 'lcp',  label: 'LCP'  },
            { key: 'tbt',  label: 'TBT'  },
            { key: 'cls',  label: 'CLS'  },
            { key: 'si',   label: 'SI'   },
            { key: 'tti',  label: 'TTI'  },
            { key: 'ttfb', label: 'TTFB' },
        ];
        const grid = $('#wpsd-cwv-grid');
        grid.empty();
        metrics.forEach(function(m) {
            const d = cwv[m.key];
            if (!d) return;
            const cls    = d.score >= 90 ? 'good' : d.score >= 50 ? 'needs' : 'poor';
            const barPct = Math.min(100, d.score || 0);
            grid.append(`
                <div class="wpsd-cwv-item wpsd-cwv-${cls}">
                    <div class="wpsd-cwv-label">${m.label}</div>
                    <div class="wpsd-cwv-value">${d.display || 'N/A'}</div>
                    <div class="wpsd-cwv-desc">${d.title || ''}</div>
                    <div class="wpsd-cwv-bar"><div class="wpsd-cwv-bar-fill" data-pct="${barPct}" style="width:0"></div></div>
                </div>
            `);
        });
        // Animate bars in after a brief delay
        setTimeout(function() {
            grid.find('.wpsd-cwv-bar-fill').each(function() {
                $(this).css('width', $(this).data('pct') + '%');
            });
        }, 300);
    }

    // ==================== CHARTS ====================
    function renderCharts(scores, resources) {
        if (chartScores)    { chartScores.destroy();    chartScores    = null; }
        if (chartResources) { chartResources.destroy(); chartResources = null; }

        const ctxS = document.getElementById('chart-scores');
        if (ctxS) {
            const vals   = [scores.performance ?? 0, scores.accessibility ?? 0, scores.best_practices ?? 0, scores.seo ?? 0];
            const colors = vals.map(v => v >= 90 ? '#0cce6b' : v >= 50 ? '#ffa400' : '#ff4e42');
            chartScores = new Chart(ctxS, {
                type: 'bar',
                data: {
                    labels: ['Performance', 'Accessibility', 'Best Practices', 'SEO'],
                    datasets: [{
                        data: vals, backgroundColor: colors,
                        borderRadius: 8, borderSkipped: false,
                        borderWidth: 0,
                        hoverBackgroundColor: colors.map(c => c + 'cc'),
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: { duration: 1000, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: c => ` Score: ${c.raw}/100` } }
                    },
                    scales: {
                        y: { min: 0, max: 100, grid: { color: '#f0f4f8' }, ticks: { font: { family: 'Poppins', size: 11 } } },
                        x: { grid: { display: false }, ticks: { font: { family: 'Poppins', size: 11 } } }
                    }
                }
            });
        }

        const ctxR = document.getElementById('chart-resources');
        if (ctxR && resources && resources.length > 0) {
            const filtered = resources.filter(r => r.size > 0);
            if (filtered.length > 0) {
                const palette = ['#0f62cc','#0cce6b','#ffa400','#ff4e42','#8b5cf6','#06b6d4','#f97316','#84cc16'];
                chartResources = new Chart(ctxR, {
                    type: 'doughnut',
                    data: {
                        labels: filtered.map(r => r.type),
                        datasets: [{
                            data: filtered.map(r => r.size),
                            backgroundColor: palette.slice(0, filtered.length),
                            borderWidth: 3, borderColor: '#fff',
                            hoverOffset: 10,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        animation: { duration: 900, animateRotate: true, animateScale: true },
                        plugins: {
                            legend: { position: 'right', labels: { font: { family: 'Poppins', size: 11 }, padding: 14 } },
                            tooltip: { callbacks: { label: c => ` ${filtered[c.dataIndex].type}: ${filtered[c.dataIndex].size_display} (${filtered[c.dataIndex].count} req)` } }
                        }
                    }
                });
            }
        }
    }

    // ==================== AUDITS ====================
    function renderAudits(type, items) {
        const list  = $(`#wpsd-${type}-list`);
        const badge = $(`#badge-${type}`);
        list.empty();
        badge.text(items ? items.length : 0);
        if (!items || items.length === 0) {
            if (type !== 'passed') list.append('<div style="color:#aaa;font-size:13px;padding:8px 0;">No items in this category.</div>');
            return;
        }
        items.forEach(function(item) {
            const score   = item.score;
            const cls     = score === null ? 'na' : score >= 0.9 ? 'good' : score >= 0.5 ? 'needs' : 'poor';
            const savings = item.savings ? `<span class="wpsd-audit-savings">Save ~${item.savings}ms</span>` : '';
            list.append(`
                <div class="wpsd-audit-item">
                    <div class="wpsd-audit-header">
                        <div class="wpsd-audit-dot wpsd-dot-${cls}"></div>
                        <div class="wpsd-audit-title">${escHtml(item.title)}</div>
                        <div class="wpsd-audit-value">${escHtml(item.display_value)}</div>
                        ${savings}
                        <svg class="wpsd-audit-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <div class="wpsd-audit-body">${escHtml(item.description)}</div>
                </div>
            `);
        });
        list.off('click', '.wpsd-audit-header').on('click', '.wpsd-audit-header', function() {
            $(this).closest('.wpsd-audit-item').toggleClass('open');
        });
    }

    // ==================== FILMSTRIP ====================
    function renderFilmstrip(frames) {
        const strip = $('#wpsd-filmstrip');
        const card  = $('#wpsd-filmstrip-card');
        strip.empty();
        if (!frames || frames.length === 0) { card.hide(); return; }
        frames.forEach(function(frame) {
            if (!frame.data) return;
            const ms    = frame.timestamp;
            const label = ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : ms + 'ms';
            strip.append(`<div class="wpsd-filmstrip-frame"><img src="${frame.data}" alt="At ${label}"><div class="wpsd-filmstrip-time">${label}</div></div>`);
        });
        card.show();
    }

    function renderScreenshot(screenshot) {
        const card = $('#wpsd-screenshot-card');
        if (!screenshot) { card.hide(); return; }
        $('#wpsd-screenshot-img').attr('src', screenshot);
        card.show();
    }

    function renderMeta(data, url, cached) {
        $('#wpsd-meta-strategy').text(data.strategy === 'desktop' ? 'Desktop' : 'Mobile');
        const t = data.fetch_time ? new Date(data.fetch_time).toLocaleString() : '';
        $('#wpsd-meta-time').text(t);
        $('#wpsd-meta-cached').text(cached ? 'Cached result' : 'Fresh result');
    }


    function renderSuggestions(data) {
        const summaryWrap = $('#wpsd-problem-summary');
        const actionWrap  = $('#wpsd-dynamic-suggestions');
        summaryWrap.empty();
        actionWrap.empty();

        const serverSuggestions = data && data.suggestions ? data.suggestions : null;
        if (serverSuggestions && Array.isArray(serverSuggestions.summary) && Array.isArray(serverSuggestions.actions)) {
            if (serverSuggestions.summary.length) {
                serverSuggestions.summary.forEach(function(item) {
                    summaryWrap.append(`
                        <div class="wpsd-suggestion-row">
                            <h4>${escHtml(item.title || '')}</h4>
                            <p>${escHtml(item.reason || '')}</p>
                        </div>
                    `);
                });
            }

            if (serverSuggestions.actions.length) {
                serverSuggestions.actions.forEach(function(item) {
                    const actionHtml = item.action ? `<p><strong>What to do:</strong> ${escHtml(item.action)}</p>` : '';
                    actionWrap.append(`
                        <div class="wpsd-suggestion-row">
                            <h4>${escHtml(item.title || '')}</h4>
                            <p><strong>Why this matters:</strong> ${escHtml(item.reason || '')}</p>
                            ${actionHtml}
                        </div>
                    `);
                });
                return;
            }
        }

        const opportunityIds = (data.opportunities || []).map(function(item) { return item.id; });
        const diagnosticIds  = (data.diagnostics || []).map(function(item) { return item.id; });
        const allIds         = opportunityIds.concat(diagnosticIds);
        const perfScore      = Number((data && data.scores && data.scores.performance) ? data.scores.performance : 0);
        const cwv            = data.cwv || {};
        const resources      = data.resources || [];

        const resourceByName = function(name) {
            return resources.find(function(item) {
                return (item.type || '').toLowerCase() === name.toLowerCase();
            }) || null;
        };

        const issueDefinitions = [
            {
                key: 'server-response',
                title: 'Slow server response is hurting the first load',
                matches: ['server-response-time'],
                metric: cwv.ttfb,
                reason: 'The server is taking too long to deliver the first HTML response, which delays everything that loads after it.',
                action: 'Improve hosting/server stack, reduce heavy PHP work, use full-page caching, and consider object caching such as Redis for repeated database queries.',
                priority: 100,
            },
            {
                key: 'render-blocking',
                title: 'Render-blocking CSS or JavaScript is delaying visible content',
                matches: ['render-blocking-resources'],
                metric: cwv.fcp,
                reason: 'Important content cannot paint quickly because CSS or JS files are blocking the browser from rendering the page.',
                action: 'Minify CSS/JS, defer non-critical JavaScript, inline critical CSS where possible, and delay non-essential scripts such as trackers or widgets.',
                priority: 95,
            },
            {
                key: 'images',
                title: 'Images are heavier than they should be',
                matches: ['uses-optimized-images', 'uses-webp-images', 'offscreen-images', 'uses-responsive-images'],
                metric: cwv.lcp,
                reason: 'Large or unoptimized images increase transfer size and often slow down the Largest Contentful Paint.',
                action: 'Compress images, convert supported assets to WebP/AVIF, lazy-load below-the-fold images, and properly size hero/banner images for the viewport.',
                priority: 92,
            },
            {
                key: 'unused-css',
                title: 'Too much unused CSS is being loaded',
                matches: ['unused-css-rules'],
                metric: cwv.fcp,
                reason: 'Stylesheets include code that is not needed for the current page, which adds unnecessary download and parse time.',
                action: 'Remove unused CSS from themes/plugins, split page-specific CSS, and avoid loading builder/plugin styles globally on every page.',
                priority: 84,
            },
            {
                key: 'unused-js',
                title: 'Too much JavaScript is running or downloading',
                matches: ['unused-javascript', 'bootup-time', 'mainthread-work-breakdown'],
                metric: cwv.tbt,
                reason: 'Excess JavaScript adds parse and execution time, which increases main-thread blocking and slows user interaction.',
                action: 'Delay non-essential JS, remove unused plugin scripts, reduce third-party widgets, and break large bundles into smaller page-specific assets.',
                priority: 90,
            },
            {
                key: 'long-cache',
                title: 'Static files are not cached aggressively enough',
                matches: ['uses-long-cache-ttl'],
                metric: cwv.ttfb,
                reason: 'Browsers are re-downloading assets too often instead of reusing cached CSS, JS, fonts, and images.',
                action: 'Set long cache TTL for versioned static assets and make sure CDN/cache headers are enabled for images, CSS, JS, and fonts.',
                priority: 76,
            },
            {
                key: 'layout-shift',
                title: 'Layout shift is making the page unstable while loading',
                matches: ['cumulative-layout-shift'],
                metric: cwv.cls,
                reason: 'Elements are moving after the page starts rendering, often due to missing image dimensions, banners, fonts, or injected content.',
                action: 'Set explicit width/height for images and embeds, reserve space for banners/popups, and optimize font loading to reduce layout jumps.',
                priority: 82,
            },
            {
                key: 'third-party',
                title: 'Third-party requests are adding extra delay',
                matches: ['third-party-summary'],
                metric: cwv.tbt,
                reason: 'External tools like analytics, chat, fonts, ads, or embeds can create additional network and execution cost.',
                action: 'Audit third-party tools, remove non-essential ones, self-host fonts when possible, and use preconnect only for required external domains.',
                priority: 78,
            },
            {
                key: 'dom-size',
                title: 'The page structure may be too heavy',
                matches: ['dom-size'],
                metric: cwv.tbt,
                reason: 'Very large DOM trees often come from complex builders, repeated sections, sliders, and deeply nested markup.',
                action: 'Reduce excessive wrappers/sections, simplify repeated builder structures, and avoid loading hidden or duplicate layout blocks.',
                priority: 68,
            }
        ];

        const issues = [];
        issueDefinitions.forEach(function(def) {
            const matched = def.matches.some(function(id) { return allIds.indexOf(id) !== -1; });
            let metricFail = false;
            if (def.key === 'server-response' && cwv.ttfb && Number(cwv.ttfb.score) < 90) metricFail = true;
            if (def.key === 'render-blocking' && cwv.fcp && Number(cwv.fcp.score) < 90) metricFail = true;
            if (def.key === 'images' && cwv.lcp && Number(cwv.lcp.score) < 90) metricFail = true;
            if (def.key === 'unused-css' && cwv.fcp && Number(cwv.fcp.score) < 90) metricFail = true;
            if (def.key === 'unused-js' && cwv.tbt && Number(cwv.tbt.score) < 90) metricFail = true;
            if (def.key === 'layout-shift' && cwv.cls && Number(cwv.cls.score) < 90) metricFail = true;
            if (matched || metricFail) {
                const metricText = def.metric && def.metric.display ? ` Current signal: ${def.metric.title || ''} ${def.metric.display}.` : '';
                issues.push({
                    title: def.title,
                    reason: def.reason + metricText,
                    action: def.action,
                    priority: def.priority,
                });
            }
        });

        const imageResource = resourceByName('image');
        const scriptResource = resourceByName('script');
        const fontResource = resourceByName('font');

        if (imageResource && imageResource.size > 700 * 1024 && !issues.some(function(i) { return i.title === 'Images are heavier than they should be'; })) {
            issues.push({
                title: 'Images are taking a large share of total page weight',
                reason: `Images currently account for about ${imageResource.size_display} across ${imageResource.count} requests, which is often a major reason for lower speed scores.`,
                action: 'Compress large images, resize oversized banners, use next-gen formats, and lazy-load non-critical visuals.',
                priority: 88,
            });
        }

        if (scriptResource && scriptResource.size > 500 * 1024 && !issues.some(function(i) { return i.title.indexOf('JavaScript') !== -1; })) {
            issues.push({
                title: 'JavaScript payload is quite heavy',
                reason: `Scripts currently account for about ${scriptResource.size_display} across ${scriptResource.count} requests, which can increase parsing and blocking time.`,
                action: 'Unload scripts that are not needed on this page, defer non-critical JS, and trim plugin/widget assets.',
                priority: 86,
            });
        }

        if (fontResource && fontResource.size > 200 * 1024) {
            issues.push({
                title: 'Font files may be adding extra load',
                reason: `Fonts are using about ${fontResource.size_display}, which can delay text rendering if too many variants are loaded.`,
                action: 'Reduce font families and weights, preload only critical font files, and self-host fonts when practical.',
                priority: 52,
            });
        }

        issues.sort(function(a, b) { return b.priority - a.priority; });

        if (!issues.length) {
            summaryWrap.append(`
                <div class="wpsd-suggestion-row">
                    <h4>No major root cause detected from the current report</h4>
                    <p>The page does not show a strong single bottleneck. You can still review caching, CDN usage, image optimization, and script loading for incremental gains.</p>
                </div>
            `);
        } else {
            const topSummary = issues.slice(0, 3);
            topSummary.forEach(function(issue, index) {
                summaryWrap.append(`
                    <div class="wpsd-suggestion-row">
                        <h4>${index + 1}. ${escHtml(issue.title)}</h4>
                        <p>${escHtml(issue.reason)}</p>
                    </div>
                `);
            });
        }

        const actionCards = [];
        issues.forEach(function(issue, index) {
            actionCards.push(`
                <div class="wpsd-suggestion-row">
                    <h4>Priority ${index + 1}: ${escHtml(issue.title)}</h4>
                    <p><strong>Why this matters:</strong> ${escHtml(issue.reason)}</p>
                    <p><strong>What to do:</strong> ${escHtml(issue.action)}</p>
                </div>
            `);
        });

        if (perfScore < 50) {
            actionCards.unshift(`
                <div class="wpsd-suggestion-row">
                    <h4>Overall diagnosis: the page has multiple heavy bottlenecks</h4>
                    <p>Your current performance score is ${perfScore}. This usually means the page is affected by a mix of server delay, large assets, render-blocking resources, or too much JavaScript. Start with the first 2–3 priorities below for the biggest impact.</p>
                </div>
            `);
        } else if (perfScore < 90) {
            actionCards.unshift(`
                <div class="wpsd-suggestion-row">
                    <h4>Overall diagnosis: the page is close, but key bottlenecks remain</h4>
                    <p>Your current performance score is ${perfScore}. The site is already partway optimized, but a few issues are still dragging the score down. Fixing the top priorities below should create the most visible gain.</p>
                </div>
            `);
        } else {
            actionCards.unshift(`
                <div class="wpsd-suggestion-row">
                    <h4>Overall diagnosis: strong score with room for refinement</h4>
                    <p>Your current performance score is ${perfScore}. The site is already performing well, so focus on polish items like caching, image cleanup, font tuning, and reducing unnecessary third-party assets.</p>
                </div>
            `);
        }

        if (!issues.length) {
            actionCards.push(`
                <div class="wpsd-suggestion-row">
                    <h4>Suggested next step</h4>
                    <p>Review your largest images, highest-request asset groups, and any third-party scripts. Even without a single obvious bottleneck, these areas usually produce the easiest gains.</p>
                </div>
            `);
        }

        actionWrap.append(actionCards.join(''));
    }

    // ==================== PASSED TOGGLE ====================
    function initPassedToggle() {
        $(document).on('click', '#toggle-passed', function() {
            $('#wpsd-passed-list').slideToggle(250);
            $(this).find('.wpsd-chevron').toggleClass('open');
        });
    }

    // ==================== HISTORY ====================
    function saveToHistory(url, data, strategy) {
        const entry = { url, strategy, scores: data.scores, timestamp: Date.now() };
        history = history.filter(h => !(h.url === url && h.strategy === strategy));
        history.unshift(entry);
        history = history.slice(0, 20);
        localStorage.setItem('wpsd_history', JSON.stringify(history));
    }

    function renderHistory() {
        const list = $('#wpsd-history-list');
        list.empty();
        if (!history.length) {
            list.html(`<div class="wpsd-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
                <h3>No analyses yet</h3><p>Your analysis history will appear here after running your first analysis.</p>
            </div>`);
            return;
        }
        history.forEach(function(entry) {
            const perf = entry.scores.performance;
            const cls  = perf >= 90 ? 'good' : perf >= 50 ? 'needs' : 'poor';
            const date = new Date(entry.timestamp).toLocaleString();
            const dev  = entry.strategy === 'desktop' ? '🖥️' : '📱';
            list.append(`
                <div class="wpsd-history-item">
                    <div>
                        <div class="wpsd-history-url">${dev} ${escHtml(entry.url)}</div>
                        <div class="wpsd-history-meta">${date}</div>
                    </div>
                    <div class="wpsd-history-scores">
                        <span class="wpsd-history-score ${cls}" title="Performance">${perf ?? 'N/A'}</span>
                        <span class="wpsd-history-score" title="Accessibility">${entry.scores.accessibility ?? 'N/A'}</span>
                        <span class="wpsd-history-score" title="Best Practices">${entry.scores.best_practices ?? 'N/A'}</span>
                        <span class="wpsd-history-score" title="SEO">${entry.scores.seo ?? 'N/A'}</span>
                    </div>
                    <div>
                        <button class="wpsd-btn-primary wpsd-btn-sm wpsd-rerun-btn"
                            data-url="${escHtml(entry.url)}"
                            data-strategy="${escHtml(entry.strategy)}">Re-run</button>
                    </div>
                </div>
            `);
        });
        list.off('click', '.wpsd-rerun-btn').on('click', '.wpsd-rerun-btn', function() {
            const url      = $(this).data('url');
            const strategy = $(this).data('strategy');
            $('#wpsd-url-input').val(url);
            currentStrategy = strategy;
            $('.wpsd-device-tab').removeClass('active');
            $(`.wpsd-device-tab[data-strategy="${strategy}"]`).addClass('active');
            scrollToSection('section-analyzer');
            setTimeout(function() { runAnalysis(url, strategy, false); }, 450);
        });
    }

    // ==================== BUTTONS ====================
    function initButtons() {
        $('#wpsd-clear-history').on('click', function() {
            if (confirm('Clear all analysis history?')) {
                history = [];
                localStorage.removeItem('wpsd_history');
                renderHistory();
                showNotification('History cleared!', 'success');
            }
        });
    }

    // ==================== SETTINGS ====================
    function initSettings() {
        $('#wpsd-toggle-key').on('click', function() {
            const input = $('#wpsd-api-key-input');
            const isPass = input.attr('type') === 'password';
            input.attr('type', isPass ? 'text' : 'password');
            $(this).text(isPass ? 'Hide' : 'Show');
        });

        $('#wpsd-save-api-key').on('click', function() {
            $(this).prop('disabled', true).text('Saving...');
            $.ajax({
                url: wpsd_data.ajax_url, method: 'POST',
                data: { action: 'wpsd_save_api_key', nonce: wpsd_data.nonce, api_key: $('#wpsd-api-key-input').val().trim() },
                success: function(r) {
                    $('#wpsd-save-api-key').prop('disabled', false).text('Save API Key');
                    showNotification(r.success ? r.data.message : 'Failed to save.', r.success ? 'success' : 'error');
                }
            });
        });

        $('#wpsd-clear-all-cache').on('click', function() {
            $(this).prop('disabled', true).text('Clearing...');
            $.ajax({
                url: wpsd_data.ajax_url, method: 'POST',
                data: { action: 'wpsd_clear_cache', nonce: wpsd_data.nonce, url: '' },
                success: function() {
                    $('#wpsd-clear-all-cache').prop('disabled', false).text('Clear All Caches');
                    showNotification('Cache cleared!', 'success');
                }
            });
        });
    }

    // ==================== NOTIFICATION ====================
    function showNotification(msg, type) {
        $('#wpsd-notification').removeClass('success error info').addClass(type).text(msg).show();
        setTimeout(function() { $('#wpsd-notification').fadeOut(400); }, 3500);
    }

    // ==================== HELPERS ====================
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})(jQuery);

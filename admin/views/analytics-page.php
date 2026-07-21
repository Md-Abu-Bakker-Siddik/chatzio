<?php
if (!defined('ABSPATH')) exit;
$is_pro = isset($is_pro) ? (bool) $is_pro : (class_exists('Chatzio_License') && Chatzio_License::is_pro());
$upgrade_url = apply_filters('chatzio_upgrade_url', 'https://chatzio.pro/pricing');
?>

<div class="wrap chatzio-admin chatzio-analytics-v3">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-chart-area"></span>
                    Analytics Intelligence
                </h1>
                <p class="chatzio-page-subtitle">Comprehensive insights into conversations, leads, engagement, and knowledge gaps.</p>
            </div>
            <div class="header-actions">
                <select id="analytics-range" class="analytics-range-select">
                    <option value="7">Last 7 days</option>
                    <option value="30" selected>Last 30 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="365">Last year</option>
                </select>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="analytics-loading" class="av3-loading">
            <div class="av3-loading-inner">
                <span class="dashicons dashicons-update av3-spin"></span>
                <span>Loading analytics data...</span>
            </div>
        </div>

        <!-- SECTION 1: KPI Hero Row -->
        <div class="av3-kpi-row">
            <div class="av3-kpi av3-kpi--indigo">
                <div class="av3-kpi__head">
                    <span class="av3-kpi__label">Total Conversations</span>
                    <span class="av3-kpi__trend" id="kpi-conv-trend" style="display:none"></span>
                </div>
                <div class="av3-kpi__val" id="kpi-conversations">--</div>
                <canvas class="av3-kpi__spark" id="sparkline-conv" width="120" height="30"></canvas>
            </div>
            <div class="av3-kpi av3-kpi--green">
                <div class="av3-kpi__head">
                    <span class="av3-kpi__label">Unique Sessions</span>
                    <span class="av3-kpi__trend" id="kpi-sessions-trend" style="display:none"></span>
                </div>
                <div class="av3-kpi__val" id="kpi-sessions">--</div>
                <canvas class="av3-kpi__spark" id="sparkline-sessions" width="120" height="30"></canvas>
            </div>
            <div class="av3-kpi av3-kpi--amber">
                <div class="av3-kpi__head">
                    <span class="av3-kpi__label">Leads Captured</span>
                    <span class="av3-kpi__trend" id="kpi-leads-trend" style="display:none"></span>
                </div>
                <div class="av3-kpi__val" id="kpi-leads">--</div>
                <canvas class="av3-kpi__spark" id="sparkline-leads" width="120" height="30"></canvas>
            </div>
            <div class="av3-kpi av3-kpi--blue">
                <div class="av3-kpi__head">
                    <span class="av3-kpi__label">Conversion Rate</span>
                    <span class="av3-kpi__trend" id="kpi-conversion-trend" style="display:none"></span>
                </div>
                <div class="av3-kpi__val" id="kpi-conversion">--%</div>
                <canvas class="av3-kpi__spark" id="sparkline-conversion" width="120" height="30"></canvas>
            </div>
            <div class="av3-kpi av3-kpi--purple">
                <div class="av3-kpi__head">
                    <span class="av3-kpi__label">Avg Msgs / Chat</span>
                    <span class="av3-kpi__trend" id="kpi-avg-trend" style="display:none"></span>
                </div>
                <div class="av3-kpi__val" id="kpi-avg-messages">--</div>
                <canvas class="av3-kpi__spark" id="sparkline-avg" width="120" height="30"></canvas>
            </div>
        </div>

        <!-- SECTION 2: Conversation Trends -->
        <div class="av3-card av3-mb">
            <div class="av3-card__head">
                <h3><span class="dashicons dashicons-chart-line"></span> Conversations & Leads Trend</h3>
                <p>Daily conversations (area) vs leads captured (dashed line)</p>
            </div>
            <div class="av3-card__body av3-chart-wrap" style="height:320px !important;">
                <canvas id="chart-trends"></canvas>
            </div>
        </div>

        <!-- SECTION 3-6: Pro analytics (teaser mode for free) -->
        <div class="av3-pro-zone-wrap <?php echo $is_pro ? 'is-pro' : 'is-free'; ?>">
        <?php if (!$is_pro): ?>
            <div class="av3-pro-zone-overlay">
                <div class="av3-pro-zone-glass">
                    <div class="av3-pro-zone-badge">Pro Analytics</div>
                    <h3>Unlock full analytics intelligence</h3>
                    <p>You can currently see core KPIs and trend graph. Upgrade to access behavior heatmaps, intent analysis, unanswered query insights, and conversion funnel intelligence.</p>
                    <a class="button button-primary button-hero" target="_blank" rel="noopener" href="<?php echo esc_url($upgrade_url); ?>">Upgrade to Pro</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- SECTION 3: Behavioral Intelligence -->
        <div class="av3-section-title">
            <h2><span class="dashicons dashicons-analytics"></span> Behavioral Intelligence</h2>
        </div>
        <div class="av3-grid av3-grid--behav av3-mb">
            <div class="av3-card av3-card--heatmap">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-calendar-alt"></span> Peak Hours Heatmap</h3>
                    <p>7-day x 24-hour activity grid</p>
                </div>
                <div class="av3-card__body">
                    <div id="heatmap-container" class="av3-heatmap-wrap"></div>
                </div>
            </div>
            <div class="av3-card">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-products"></span> Product Mentions</h3>
                    <p>Most discussed products</p>
                </div>
                <div class="av3-card__body">
                    <div id="top-products-list"></div>
                </div>
            </div>
        </div>

        <!-- SECTION 4: Conversation Quality -->
        <div class="av3-section-title">
            <h2><span class="dashicons dashicons-star-filled"></span> Conversation Quality</h2>
        </div>
        <div class="av3-grid av3-grid--2 av3-mb">
            <div class="av3-card">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-editor-help"></span> Top Unanswered Questions</h3>
                    <p>The exact queries your chatbot could not answer</p>
                </div>
                <div class="av3-card__body">
                    <div id="unanswered-questions-table"></div>
                </div>
            </div>
            <div class="av3-card">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-warning"></span> Failed Topics by Category</h3>
                    <p>Knowledge gaps to address</p>
                </div>
                <div class="av3-card__body av3-chart-wrap" style="height:280px !important;">
                    <canvas id="chart-failed-categories"></canvas>
                </div>
            </div>
        </div>

        <!-- SECTION 5: Lead Funnel -->
        <div class="av3-section-title">
            <h2><span class=”dashicons dashicons-filter”></span> Lead Funnel <span class=”chatzio-tooltip”>?<span class=”tooltip-text”>Widget opens → leads captured (pre-chat form) → conversations started → <strong>converted</strong> (leads you mark as customers or closed). Mark leads as Converted on the Leads page or when they place a WooCommerce order.</span></span></h2>
        </div>
        <div class="av3-card av3-mb">
            <div class="av3-card__body">
                <div class="av3-funnel" id="funnel-pipeline">
                    <div class="av3-funnel__stage">
                        <div class="av3-funnel__bar av3-funnel__bar--1">
                            <div class="av3-funnel__count" id="funnel-widget">--</div>
                            <div class="av3-funnel__label">Widget Opens</div>
                        </div>
                    </div>
                    <div class="av3-funnel__arrow"><span class="av3-funnel__drop" id="dropoff-1"></span></div>
                    <div class="av3-funnel__stage">
                        <div class="av3-funnel__bar av3-funnel__bar--2">
                            <div class="av3-funnel__count" id="funnel-captured">--</div>
                            <div class="av3-funnel__label">Leads Captured</div>
                        </div>
                    </div>
                    <div class="av3-funnel__arrow"><span class="av3-funnel__drop" id="dropoff-2"></span></div>
                    <div class="av3-funnel__stage">
                        <div class="av3-funnel__bar av3-funnel__bar--3">
                            <div class="av3-funnel__count" id="funnel-started">--</div>
                            <div class="av3-funnel__label">Conversations</div>
                        </div>
                    </div>
                    <div class="av3-funnel__arrow"><span class="av3-funnel__drop" id="dropoff-3"></span></div>
                    <div class="av3-funnel__stage">
                        <div class="av3-funnel__bar av3-funnel__bar--4">
                            <div class="av3-funnel__count" id="funnel-converted">--</div>
                            <div class="av3-funnel__label">Converted</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 6: Topics and Intent -->
        <div class="av3-section-title">
            <h2><span class="dashicons dashicons-tag"></span> Topics & Intent Analysis</h2>
        </div>
        <div class="av3-grid av3-grid--2">
            <div class="av3-card">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-list-view"></span> Top Discussed Topics <span class="chatzio-tooltip">?<span class="tooltip-text">Most common keywords and phrases in user messages (stop-words filtered).</span></span></h3>
                </div>
                <div class="av3-card__body">
                    <div id="topics-cloud" class="av3-topics"></div>
                </div>
            </div>
            <div class="av3-card">
                <div class="av3-card__head">
                    <h3><span class="dashicons dashicons-chart-pie"></span> Intent Distribution <span class="chatzio-tooltip">?<span class="tooltip-text">High / Medium / Low engagement levels based on message count per session.</span></span></h3>
                </div>
                <div class="av3-card__body av3-donut-body">
                    <div class="av3-donut-wrap">
                        <canvas id="chart-intent"></canvas>
                        <div class="av3-donut-center" id="intent-center">
                            <span class="av3-donut-center__val">--</span>
                            <span class="av3-donut-center__label">Sessions</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>

    </div>
</div>

<style>
/* ============================================================
   Analytics V3 — Full Intelligence Dashboard
   All spacing uses !important to override the global
   .chatzio-shell * { margin:0; padding:0 } reset in admin.css
   ============================================================ */

/* --- Scope prefix: av3- to avoid collisions --- */

.chatzio-analytics-v3 .chatzio-shell {
    padding: 0 !important;
}

/* --- Pro zone teaser (blur lock for free plan) --- */
.av3-pro-zone-wrap {
    position: relative !important;
}
.av3-pro-zone-wrap.is-free {
    margin-top: 4px !important;
}
.av3-pro-zone-wrap.is-free > .av3-section-title,
.av3-pro-zone-wrap.is-free > .av3-grid,
.av3-pro-zone-wrap.is-free > .av3-card {
    filter: blur(4px) saturate(0.85) !important;
    user-select: none !important;
    pointer-events: none !important;
}
.av3-pro-zone-overlay {
    position: absolute !important;
    inset: 0 !important;
    z-index: 15 !important;
    display: flex !important;
    align-items: flex-start !important;
    justify-content: center !important;
    padding-top: 70px !important;
    pointer-events: none !important;
}
.av3-pro-zone-glass {
    max-width: 760px !important;
    pointer-events: auto !important;
    text-align: center !important;
    border-radius: 16px !important;
    padding: 24px 22px !important;
    border: 1px solid rgba(255,255,255,0.4) !important;
    background:
      radial-gradient(800px 180px at 5% -10%, rgba(79,70,229,0.18), transparent 55%),
      radial-gradient(700px 180px at 95% -10%, rgba(59,130,246,0.16), transparent 55%),
      linear-gradient(135deg, rgba(255,255,255,0.76), rgba(255,255,255,0.62)) !important;
    backdrop-filter: blur(10px) !important;
    -webkit-backdrop-filter: blur(10px) !important;
    box-shadow: 0 12px 30px rgba(15,23,42,0.12) !important;
}
.av3-pro-zone-badge {
    display: inline-block !important;
    margin-bottom: 10px !important;
    padding: 3px 10px !important;
    border-radius: 999px !important;
    background: #e9f1ff !important;
    border: 1px solid #bfd3fb !important;
    color: #1f4c93 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: .4px !important;
    text-transform: uppercase !important;
}
.av3-pro-zone-glass h3 {
    margin: 0 0 8px !important;
    font-size: 24px !important;
    color: #0f172a !important;
}
.av3-pro-zone-glass p {
    margin: 0 0 14px !important;
    color: #4b5563 !important;
    font-size: 14px !important;
    line-height: 1.5 !important;
}

/* --- Loading --- */
.av3-loading {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 60px 20px !important;
}
.av3-loading.hidden { display: none !important; }
.av3-loading-inner {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    color: #64748b !important;
    font-size: 15px !important;
}
.av3-loading-inner .dashicons {
    font-size: 22px !important;
    width: 22px !important;
    height: 22px !important;
}
@keyframes av3spin { from{transform:rotate(0)} to{transform:rotate(360deg)} }
.av3-spin { animation: av3spin 1s linear infinite !important; }

/* --- Spacing helper --- */
.av3-mb { margin-bottom: 24px !important; }

/* ============================================================
   Cards
   ============================================================ */
.chatzio-analytics-v3 .av3-card {
    background: #fff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
}
.chatzio-analytics-v3 .av3-card__head {
    padding: 18px 22px 14px !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.chatzio-analytics-v3 .av3-card__head h3 {
    font-size: 15px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    margin: 0 0 3px 0 !important;
    padding: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.chatzio-analytics-v3 .av3-card__head h3 .dashicons {
    font-size: 17px !important;
    width: 17px !important;
    height: 17px !important;
    color: #4f46e5 !important;
}
.chatzio-analytics-v3 .av3-card__head p {
    font-size: 12px !important;
    color: #94a3b8 !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1.4 !important;
}
.chatzio-analytics-v3 .av3-card__body {
    padding: 22px !important;
}

/* Chart wrapper — gives canvas a fixed height container */
.av3-chart-wrap {
    position: relative !important;
}
.av3-chart-wrap canvas {
    width: 100% !important;
    height: 100% !important;
}

/* --- Section Titles --- */
.av3-section-title {
    margin: 36px 0 18px 0 !important;
    padding: 0 !important;
}
.av3-section-title h2 {
    font-size: 18px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 !important;
    padding: 0 !important;
}
.av3-section-title h2 .dashicons {
    font-size: 20px !important;
    width: 20px !important;
    height: 20px !important;
    color: #4f46e5 !important;
}
.av3-section-desc {
    font-size: 13px !important;
    color: #64748b !important;
    margin: 8px 0 0 !important;
    line-height: 1.5 !important;
}

/* --- Grids --- */
.av3-grid {
    display: grid !important;
    gap: 20px !important;
}
.av3-grid--2 { grid-template-columns: repeat(2, 1fr) !important; }
.av3-grid--3 { grid-template-columns: repeat(3, 1fr) !important; }
.av3-grid--behav { grid-template-columns: 2fr 1fr !important; }

/* ============================================================
   SECTION 1: KPI Hero Row
   ============================================================ */
.av3-kpi-row {
    display: grid !important;
    grid-template-columns: repeat(6, 1fr) !important;
    gap: 14px !important;
    margin-bottom: 24px !important;
}
.av3-kpi {
    background: #fff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 16px 14px 12px !important;
    position: relative !important;
    overflow: hidden !important;
    min-width: 0 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
}
.av3-kpi::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important; left: 0 !important; right: 0 !important;
    height: 3px !important;
    background: var(--av3-accent) !important;
}
.av3-kpi--indigo { --av3-accent: #4f46e5; }
.av3-kpi--green  { --av3-accent: #10b981; }
.av3-kpi--amber  { --av3-accent: #f59e0b; }
.av3-kpi--blue   { --av3-accent: #3b82f6; }
.av3-kpi--purple { --av3-accent: #8b5cf6; }
.av3-kpi--teal   { --av3-accent: #14b8a6; }

.av3-kpi__head {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-bottom: 6px !important;
}
.av3-kpi__label {
    font-size: 10px !important;
    font-weight: 700 !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.av3-kpi__trend {
    font-size: 10px !important;
    font-weight: 700 !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    line-height: 1.5 !important;
}
.av3-kpi__trend.positive { background: #ecfdf5 !important; color: #10b981 !important; }
.av3-kpi__trend.negative { background: #fef2f2 !important; color: #ef4444 !important; }
.av3-kpi__trend.neutral  { background: #f1f5f9 !important; color: #94a3b8 !important; }

.av3-kpi__val {
    font-size: 26px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin-bottom: 6px !important;
    line-height: 1.1 !important;
}
.av3-kpi__spark {
    display: block !important;
    width: 100% !important;
    height: 28px !important;
    max-height: 28px !important;
    opacity: 0.65 !important;
}

/* ============================================================
   SECTION 3: Heatmap
   ============================================================ */
.av3-heatmap-wrap {
    overflow-x: auto !important;
}
.av3-heatmap-grid {
    display: grid !important;
    grid-template-columns: 36px repeat(24, 1fr) !important;
    gap: 2px !important;
    min-width: 460px !important;
}
.av3-hm-label {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-weight: 600 !important;
    color: #94a3b8 !important;
    font-size: 10px !important;
    padding: 0 !important;
    margin: 0 !important;
}
.av3-hm-cell {
    aspect-ratio: 1 !important;
    border-radius: 3px !important;
    cursor: default !important;
    min-height: 0 !important;
    min-width: 0 !important;
}
.av3-hm-cell.i0 { background: #f1f5f9 !important; }
.av3-hm-cell.i1 { background: #e0e7ff !important; }
.av3-hm-cell.i2 { background: #c7d2fe !important; }
.av3-hm-cell.i3 { background: #a5b4fc !important; }
.av3-hm-cell.i4 { background: #818cf8 !important; }
.av3-hm-cell.i5 { background: #6366f1 !important; }

.av3-hm-legend {
    display: flex !important;
    align-items: center !important;
    gap: 4px !important;
    margin-top: 10px !important;
    font-size: 10px !important;
    color: #94a3b8 !important;
    justify-content: flex-end !important;
}
.av3-hm-legend-cell {
    width: 12px !important;
    height: 12px !important;
    border-radius: 2px !important;
}

/* --- Ranked lists (pages / products) --- */
.av3-ranked-item {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 9px 0 !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
.av3-ranked-item:last-child { border-bottom: none !important; }
.av3-ranked-num {
    width: 22px !important;
    height: 22px !important;
    border-radius: 50% !important;
    background: #eef2ff !important;
    color: #4f46e5 !important;
    font-weight: 700 !important;
    font-size: 11px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}
.av3-ranked-text {
    flex: 1 !important;
    min-width: 0 !important;
}
.av3-ranked-title {
    font-weight: 600 !important;
    font-size: 12px !important;
    color: #0f172a !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    margin: 0 !important;
    padding: 0 !important;
}
.av3-ranked-sub {
    font-size: 10px !important;
    color: #94a3b8 !important;
    margin: 0 !important;
    padding: 0 !important;
}
.av3-ranked-bar {
    flex: 0 0 60px !important;
    height: 5px !important;
    background: #f1f5f9 !important;
    border-radius: 3px !important;
    overflow: hidden !important;
}
.av3-ranked-fill {
    height: 100% !important;
    background: #4f46e5 !important;
    border-radius: 3px !important;
}
.av3-ranked-count {
    font-weight: 700 !important;
    font-size: 13px !important;
    color: #0f172a !important;
    min-width: 28px !important;
    text-align: right !important;
}

/* --- Empty State --- */
.av3-empty {
    text-align: center !important;
    color: #94a3b8 !important;
    padding: 28px 12px !important;
    font-size: 13px !important;
}
.av3-empty .dashicons {
    display: block !important;
    font-size: 28px !important;
    width: 28px !important;
    height: 28px !important;
    margin: 0 auto 6px !important;
    opacity: 0.35 !important;
}

/* ============================================================
   SECTION 4: Donut charts
   ============================================================ */
.av3-donut-body {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-height: 260px !important;
}
.av3-donut-wrap {
    position: relative !important;
    width: 220px !important;
    height: 220px !important;
}
.av3-donut-wrap canvas {
    width: 100% !important;
    height: 100% !important;
}
.av3-donut-center {
    position: absolute !important;
    top: 44% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    text-align: center !important;
    pointer-events: none !important;
}
.av3-donut-center__val {
    display: block !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.2 !important;
}
.av3-donut-center__label {
    display: block !important;
    font-size: 10px !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

/* --- Unanswered questions --- */
.av3-uq-row {
    padding: 14px 0 !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
.av3-uq-row:last-child { border-bottom: none !important; }
.av3-uq-query {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    margin: 0 0 6px 0 !important;
    padding: 0 !important;
}
.av3-uq-meta {
    display: flex !important;
    gap: 12px !important;
    font-size: 11px !important;
    color: #94a3b8 !important;
    align-items: center !important;
}
.av3-uq-cat {
    padding: 2px 8px !important;
    background: #fffbeb !important;
    color: #d97706 !important;
    border-radius: 4px !important;
    font-weight: 600 !important;
    font-size: 10px !important;
    text-transform: capitalize !important;
}
.av3-uq-score { font-weight: 600 !important; }
.av3-uq-score.low { color: #ef4444 !important; }
.av3-uq-score.medium { color: #f59e0b !important; }

/* ============================================================
   SECTION 5: Lead Funnel
   ============================================================ */
.av3-funnel {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 12px !important;
    padding: 32px 16px !important;
    flex-wrap: wrap !important;
}
.av3-funnel__stage {
    flex: 1 !important;
    min-width: 120px !important;
    max-width: 180px !important;
}
.av3-funnel__bar {
    color: #fff !important;
    padding: 24px 12px !important;
    min-height: 100px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: center !important;
    border-radius: 12px !important;
    text-align: center !important;
}
.av3-funnel__bar--1 { background: linear-gradient(135deg,#4f46e5,#4338ca) !important; }
.av3-funnel__bar--2 { background: linear-gradient(135deg,#10b981,#059669) !important; }
.av3-funnel__bar--3 { background: linear-gradient(135deg,#f59e0b,#d97706) !important; }
.av3-funnel__bar--4 { background: linear-gradient(135deg,#8b5cf6,#7c3aed) !important; }

.av3-funnel__count {
    font-size: 28px !important;
    font-weight: 700 !important;
    margin: 0 0 4px 0 !important;
    padding: 0 !important;
}
.av3-funnel__label {
    font-size: 11px !important;
    font-weight: 600 !important;
    opacity: 0.9 !important;
    margin: 0 !important;
    padding: 0 !important;
}
.av3-funnel__arrow {
    flex-shrink: 0 !important;
    text-align: center !important;
    padding: 0 !important;
    margin: 0 !important;
}
.av3-funnel__arrow::before {
    content: '\2192' !important;
    font-size: 20px !important;
    color: #cbd5e1 !important;
    display: block !important;
}
.av3-funnel__drop {
    display: block !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    color: #ef4444 !important;
    margin-top: 2px !important;
}

/* ============================================================
   SECTION 6: Topics
   ============================================================ */
.av3-topics {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
    justify-content: center !important;
    align-items: center !important;
    min-height: 80px !important;
    padding: 10px 0 !important;
}
.av3-tag {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    padding: 7px 14px !important;
    background: #eef2ff !important;
    color: #4f46e5 !important;
    border-radius: 20px !important;
    font-weight: 600 !important;
    cursor: default !important;
    transition: background 0.15s ease, color 0.15s ease !important;
}
.av3-tag:hover {
    background: #4f46e5 !important;
    color: #fff !important;
}
.av3-tag small { opacity: 0.6 !important; font-weight: 400 !important; }
.av3-tag.s1 { font-size: 11px !important; padding: 5px 10px !important; }
.av3-tag.s2 { font-size: 12px !important; }
.av3-tag.s3 { font-size: 14px !important; }
.av3-tag.s4 { font-size: 16px !important; padding: 8px 16px !important; }
.av3-tag.s5 { font-size: 18px !important; padding: 9px 18px !important; }

/* ============================================================
   Responsive
   ============================================================ */
@media (max-width: 1200px) {
    .av3-kpi-row { grid-template-columns: repeat(3, 1fr) !important; }
}
@media (max-width: 900px) {
    .av3-grid--2,
    .av3-grid--3,
    .av3-grid--behav { grid-template-columns: 1fr !important; }
    .av3-funnel { flex-direction: column !important; gap: 8px !important; }
    .av3-funnel__stage { max-width: none !important; width: 100% !important; }
    .av3-funnel__arrow::before { content: '\2193' !important; }
}
@media (max-width: 768px) {
    .av3-kpi-row { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 500px) {
    .av3-kpi-row { grid-template-columns: 1fr !important; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function($) {
    'use strict';

    var charts = {};
    var analyticsData = null;

    $(document).ready(function() {
        loadAnalytics(30);
        $('#analytics-range').on('change', function() {
            loadAnalytics(parseInt($(this).val(), 10));
        });
    });

    function loadAnalytics(days) {
        $('#analytics-loading').removeClass('hidden');
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'chatzio_get_analytics',
                nonce: '<?php echo wp_create_nonce("chatzio_admin_nonce"); ?>',
                days: days
            },
            success: function(r) {
                if (r.success) { analyticsData = r.data; renderAll(); }
                $('#analytics-loading').addClass('hidden');
            },
            error: function() { $('#analytics-loading').addClass('hidden'); }
        });
    }

    function renderAll() {
        renderKPIs();
        renderTrendsChart();
        renderHeatmap();
        renderTopProducts();
        renderUnansweredQuestions();
        renderFailedCategoriesChart();
        renderFunnel();
        renderTopicsCloud();
        renderIntentChart();
    }

    /* ================================================================
       SECTION 1: KPIs
       ================================================================ */
    function renderKPIs() {
        var d = analyticsData;
        setText('kpi-conversations', num(d.total_conversations));
        setText('kpi-sessions', num(d.total_sessions));
        setText('kpi-leads', num(d.total_leads));
        setText('kpi-conversion', d.lead_conversion_rate + '%');
        setText('kpi-avg-messages', d.avg_messages);

        // Only show trend badge when there's a real non-zero value
        setTrend('#kpi-conv-trend', d.trend_percent);
        setTrend('#kpi-sessions-trend', d.trend_percent);
        setTrend('#kpi-leads-trend', simpleTrend(d.daily_leads));
        setTrend('#kpi-conversion-trend', d.trend_percent);
        setTrend('#kpi-avg-trend', d.trend_percent);

        // Delay sparklines so grid has finished layout and clientWidth is available
        setTimeout(function() {
            sparkline('sparkline-conv', sliceLast(d.daily, 7), '#4f46e5');
            sparkline('sparkline-sessions', sliceLast(d.daily, 7), '#10b981');
            sparkline('sparkline-leads', sliceLast(d.daily_leads, 7), '#f59e0b');
            sparkline('sparkline-conversion', sliceConversionRates(d.daily_sessions || d.daily, d.daily_leads, 7), '#3b82f6');
            sparkline('sparkline-avg', sliceLast(d.daily, 7), '#8b5cf6');
        }, 50);
    }

    function simpleTrend(arr) {
        if (!arr || arr.length < 2) return 0;
        var h = Math.floor(arr.length / 2), a = 0, b = 0;
        for (var i = 0; i < arr.length; i++) {
            if (i < h) a += +arr[i].count; else b += +arr[i].count;
        }
        return a === 0 ? (b > 0 ? 100 : 0) : Math.round(((b - a) / a) * 100);
    }

    function sliceLast(arr, n) {
        if (!arr || !arr.length) return [0];
        return arr.slice(-n).map(function(d) { return +d.count; });
    }
    function sliceConversionRates(dailySessions, dailyLeads, n) {
        if (!dailySessions || !dailySessions.length) return [0];
        var leadsByDate = {};
        (dailyLeads || []).forEach(function(i) { leadsByDate[i.date] = +i.count; });
        var last = dailySessions.slice(-n);
        return last.map(function(d) {
            var sessions = +d.count;
            var leads = leadsByDate[d.date] || 0;
            return sessions === 0 ? 0 : Math.round((leads / sessions) * 100);
        });
    }

    function setTrend(sel, pct) {
        var el = $(sel);
        if (pct > 0) {
            el.text('+' + pct + '%').removeClass('negative neutral').addClass('positive').show();
        } else if (pct < 0) {
            el.text(pct + '%').removeClass('positive neutral').addClass('negative').show();
        } else {
            // Hide the badge entirely when there's no change — avoids "0%" noise
            el.text('').hide();
        }
    }

    function sparkline(id, data, color) {
        var el = document.getElementById(id);
        if (!el) return;
        if (charts[id]) charts[id].destroy();
        el.width = el.parentElement.clientWidth || 120;
        el.height = 28;
        charts[id] = new Chart(el, {
            type: 'line',
            data: {
                labels: data.map(function(_, i) { return i; }),
                datasets: [{ data: data, borderColor: color, backgroundColor: color + '18', borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0 }]
            },
            options: {
                responsive: false, maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
            }
        });
    }

    /* ================================================================
       SECTION 2: Trends Chart
       ================================================================ */
    function renderTrendsChart() {
        var ctx = document.getElementById('chart-trends');
        if (!ctx) return;
        if (charts.trends) charts.trends.destroy();
        var d = analyticsData;
        var labels = d.daily.map(function(i) { return i.date; });
        var conv = d.daily.map(function(i) { return +i.count; });
        var lm = {};
        (d.daily_leads || []).forEach(function(i) { lm[i.date] = +i.count; });
        var leads = labels.map(function(dt) { return lm[dt] || 0; });

        charts.trends = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.map(function(dt) { return new Date(dt+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}); }),
                datasets: [
                    { label:'Conversations', data:conv, borderColor:'#4f46e5', backgroundColor:'rgba(79,70,229,0.07)', borderWidth:2, fill:true, tension:0.3, pointRadius: conv.length>30?0:3, yAxisID:'y' },
                    { label:'Leads', data:leads, borderColor:'#10b981', backgroundColor:'transparent', borderWidth:2, borderDash:[5,3], tension:0.3, pointRadius: leads.length>30?0:3, yAxisID:'y1' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode:'index', intersect:false },
                plugins: {
                    legend: { display:true, position:'top', labels:{ usePointStyle:true, padding:16, font:{size:12} } },
                    tooltip: { backgroundColor:'rgba(15,23,42,0.9)', padding:10, cornerRadius:6 }
                },
                scales: {
                    x: { grid:{display:false}, ticks:{maxTicksLimit:10, font:{size:11}} },
                    y: { type:'linear', position:'left', beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}, ticks:{font:{size:11}}, title:{display:true, text:'Conversations', font:{size:11}} },
                    y1:{ type:'linear', position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, ticks:{font:{size:11}}, title:{display:true, text:'Leads', font:{size:11}} }
                }
            }
        });
    }

    /* ================================================================
       SECTION 3: Heatmap, Pages, Products
       ================================================================ */
    function renderHeatmap() {
        var c = $('#heatmap-container'), data = analyticsData.heatmap || [];
        var g = {}, mx = 0;
        data.forEach(function(r) { var k = r.dow+'-'+r.hour; g[k] = +r.count; if (g[k]>mx) mx=g[k]; });
        var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var h = '<div class="av3-heatmap-grid">';
        h += '<div class="av3-hm-label"></div>';
        for (var hr = 0; hr < 24; hr++) h += '<div class="av3-hm-label">'+(hr%4===0?hr:'')+'</div>';
        for (var d = 1; d <= 7; d++) {
            h += '<div class="av3-hm-label">'+days[d-1]+'</div>';
            for (var hr2 = 0; hr2 < 24; hr2++) {
                var cnt = g[d+'-'+hr2]||0;
                var lv = cnt === 0 ? 0 : (mx === 0 ? 0 : Math.min(5, Math.ceil((cnt/mx)*5)));
                h += '<div class="av3-hm-cell i'+lv+'" title="'+days[d-1]+' '+hr2+':00 — '+cnt+' msgs"></div>';
            }
        }
        h += '</div>';
        h += '<div class="av3-hm-legend"><span>Less</span>';
        for (var i=0;i<=5;i++) h += '<div class="av3-hm-legend-cell i'+i+'"></div>';
        h += '<span>More</span></div>';
        c.html(h);
    }

    function renderTopProducts() {
        var c = $('#top-products-list'), prods = analyticsData.top_products || [];
        if (!prods.length) { c.html(empty('dashicons-products','No product mentions yet')); return; }
        var mx = Math.max.apply(null, prods.map(function(p){return +p.mentions;}));
        var h = '';
        prods.forEach(function(p, i) {
            h += '<div class="av3-ranked-item"><div class="av3-ranked-num">'+(i+1)+'</div><div class="av3-ranked-text"><div class="av3-ranked-title">'+esc(p.name)+'</div></div><div class="av3-ranked-bar"><div class="av3-ranked-fill" style="width:'+((+p.mentions/mx)*100)+'%"></div></div><div class="av3-ranked-count">'+p.mentions+'</div></div>';
        });
        c.html(h);
    }

    /* ================================================================
       SECTION 4: Quality
       ================================================================ */
    function renderFailedCategoriesChart() {
        var ctx = document.getElementById('chart-failed-categories');
        if (!ctx) return;
        if (charts.fc) charts.fc.destroy();
        var cats = analyticsData.failed_by_category || [];
        if (!cats.length) { $(ctx).closest('.av3-card__body').html(empty('dashicons-yes-alt','No failed topics — great job!')); return; }
        var colors = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e','#14b8a6','#06b6d4','#3b82f6','#8b5cf6'];
        charts.fc = new Chart(ctx, {
            type:'bar',
            data:{ labels:cats.map(function(c){return cap(c.topic_category);}), datasets:[{ data:cats.map(function(c){return +c.count;}), backgroundColor:cats.map(function(_,i){return colors[i%colors.length]+'CC';}), borderColor:cats.map(function(_,i){return colors[i%colors.length];}), borderWidth:1, borderRadius:3 }] },
            options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}}, y:{grid:{display:false}, ticks:{font:{size:11,weight:'bold'}}}} }
        });
    }

    function renderUnansweredQuestions() {
        var c = $('#unanswered-questions-table'), qs = analyticsData.top_failed_queries || [];
        if (!qs.length) { c.html(empty('dashicons-thumbs-up','No unanswered questions — your knowledge base is comprehensive!')); return; }
        var h = '';
        qs.forEach(function(q) {
            var s = parseFloat(q.confidence_score)||0;
            var cls = s<0.3?'low': s<0.6?'medium':'';
            h += '<div class="av3-uq-row"><div class="av3-uq-query">&ldquo;'+esc(q.user_query)+'&rdquo;</div><div class="av3-uq-meta"><span class="av3-uq-cat">'+esc(q.topic_category||'unknown')+'</span><span class="av3-uq-score '+cls+'">Confidence: '+Math.round(s*100)+'%</span></div></div>';
        });
        c.html(h);
    }

    /* ================================================================
       SECTION 5: Funnel
       ================================================================ */
    function renderFunnel() {
        var f = analyticsData.funnel||{};
        var wo=f.widget_opens||0, lc=f.leads_captured||0, cs=f.conversations_started||0, lv=f.leads_converted||0;
        setText('funnel-widget', num(wo));
        setText('funnel-captured', num(lc));
        setText('funnel-started', num(cs));
        setText('funnel-converted', num(lv));
        // Widget Opens → Leads Captured → Conversations → Converted
        setText('dropoff-1', (wo>0?Math.round(((wo-lc)/wo)*100):0)+'% drop');
        setText('dropoff-2', (lc>0?Math.round(((lc-cs)/lc)*100):0)+'% drop');
        setText('dropoff-3', (cs>0?Math.round(((cs-lv)/cs)*100):0)+'% drop');
    }

    /* ================================================================
       SECTION 6: Topics & Intent
       ================================================================ */
    function renderTopicsCloud() {
        var c = $('#topics-cloud'), words = analyticsData.top_words || {};
        var keys = Object.keys(words);
        if (!keys.length) { c.html(empty('dashicons-tag','No topic data yet')); return; }
        var arr = keys.map(function(w){return {w:w,c:words[w]};}).sort(function(a,b){return b.c-a.c;});
        var mx = arr[0].c, h = '';
        arr.forEach(function(i) {
            var sz = Math.max(1, Math.min(5, Math.ceil((i.c/mx)*5)));
            h += '<span class="av3-tag s'+sz+'">'+esc(i.w)+' <small>('+i.c+')</small></span>';
        });
        c.html(h);
    }

    function renderIntentChart() {
        var ctx = document.getElementById('chart-intent');
        if (!ctx) return;
        if (charts.intent) charts.intent.destroy();
        var it = analyticsData.intent_distribution||{high:0,medium:0,low:0};
        var tot = it.high+it.medium+it.low;
        $('#intent-center .av3-donut-center__val').text(num(tot));
        charts.intent = new Chart(ctx, {
            type:'doughnut',
            data:{ labels:['High Intent','Medium Intent','Low Intent'], datasets:[{ data:[it.high,it.medium,it.low], backgroundColor:['#10b981','#f59e0b','#e5e7eb'], borderWidth:0, hoverOffset:4 }] },
            options:{ responsive:true, maintainAspectRatio:true, cutout:'65%', plugins:{ legend:{position:'bottom',labels:{padding:12,usePointStyle:true,font:{size:11}}}, tooltip:{callbacks:{label:function(c){var p=tot>0?Math.round((c.parsed/tot)*100):0; return c.label+': '+c.parsed+' ('+p+'%)';}}} } }
        });
    }

    /* ================================================================
       Helpers
       ================================================================ */
    function num(n) { return (+n||0).toLocaleString(); }
    function setText(id, v) { var e=document.getElementById(id); if(e) e.textContent=v; }
    function esc(s) { if(!s)return''; var d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
    function cap(s) { return s?s.charAt(0).toUpperCase()+s.slice(1):''; }
    function empty(icon, txt) { return '<div class="av3-empty"><span class="dashicons '+icon+'"></span>'+txt+'</div>'; }

})(jQuery);
</script>

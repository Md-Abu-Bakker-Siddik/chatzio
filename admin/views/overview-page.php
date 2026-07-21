<?php
/**
 * Admin Overview -- Command Center Dashboard
 * Server-side rendered, no JavaScript/Chart.js needed.
 */
if (!defined('ABSPATH')) exit;

// Pre-compute comparison arrows
$conv_diff = $data['week_conversations'] - $data['prev_week_conversations'];
$lead_diff = $data['week_leads'] - $data['prev_week_leads'];

// Friendly model name
$model_raw = $data['model'];
$model_display = $model_raw;
if (strpos($model_raw, '/') !== false) {
    $model_display = substr($model_raw, strpos($model_raw, '/') + 1);
}
$model_display = ucwords(str_replace(['-', '_'], ' ', $model_display));

// Last sync display
$last_sync_display = 'Never';
if (!empty($data['last_sync'])) {
    $last_sync_display = human_time_diff(strtotime($data['last_sync']), current_time('timestamp')) . ' ago';
}
?>

<div class="wrap chatzio-admin chatzio-overview-v2">
    <div class="chatzio-shell">

        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-format-chat"></span>
                    Chatzio
                </h1>
                <p class="chatzio-page-subtitle">Command center — your chatbot at a glance.</p>
            </div>
        </div>

        <?php
        if (get_option('chatzio_setup_complete', '0') !== '1') :
            $wiz_step1_done = !empty($data['api_configured']);
            $wiz_step2_done = $data['total_content'] > 0;
            $wiz_initial = $wiz_step1_done ? ($wiz_step2_done ? 3 : 2) : 1;
        ?>
        <div class="chatzio-setup-wizard" data-current-step="<?php echo $wiz_initial; ?>">

            <!-- Hero -->
            <div class="wiz-hero">
                <div class="wiz-hero-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
                </div>
                <h2>Welcome to Chatzio</h2>
                <p>Get your AI chatbot live in under 2 minutes</p>
            </div>

            <!-- Progress bar -->
            <div class="wiz-progress">
                <div class="wiz-progress-step <?php echo $wiz_initial >= 1 ? 'active' : ''; ?> <?php echo $wiz_step1_done ? 'done' : ''; ?>" data-step="1">
                    <div class="wiz-dot"><span>1</span></div>
                    <div class="wiz-progress-label">Connect AI</div>
                </div>
                <div class="wiz-progress-line <?php echo $wiz_step1_done ? 'filled' : ''; ?>"></div>
                <div class="wiz-progress-step <?php echo $wiz_initial >= 2 ? 'active' : ''; ?> <?php echo $wiz_step2_done ? 'done' : ''; ?>" data-step="2">
                    <div class="wiz-dot"><span>2</span></div>
                    <div class="wiz-progress-label">Sync Content</div>
                </div>
                <div class="wiz-progress-line <?php echo $wiz_step2_done ? 'filled' : ''; ?>"></div>
                <div class="wiz-progress-step <?php echo $wiz_initial >= 3 ? 'active' : ''; ?>" data-step="3">
                    <div class="wiz-dot"><span>3</span></div>
                    <div class="wiz-progress-label">Customize</div>
                </div>
            </div>

            <!-- Step panels (only one visible at a time) -->
            <div class="wiz-panels">

                <!-- Step 1 -->
                <div class="wiz-panel <?php echo $wiz_initial === 1 ? 'active' : ''; ?>" id="wiz-panel-1">
                    <div class="wiz-panel-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
                    </div>
                    <h3>Connect Your AI Engine</h3>
                    <p class="wiz-panel-desc">Paste your OpenRouter API key below. This powers the chatbot brain.</p>
                    <?php if ($wiz_step1_done) : ?>
                        <div class="wiz-success-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                            API key connected successfully
                        </div>
                    <?php else : ?>
                        <div class="wiz-input-group">
                            <input type="password" id="wizard-api-key" placeholder="sk-or-v1-..." class="wiz-input">
                            <button type="button" id="wizard-test-api" class="wiz-btn wiz-btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                Test &amp; Save
                            </button>
                        </div>
                        <div id="wizard-api-result"></div>
                        <p class="wiz-hint">No key yet? <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">Get one free from OpenRouter</a></p>
                    <?php endif; ?>
                    <div class="wiz-nav">
                        <span></span>
                        <button type="button" class="wiz-btn wiz-btn-next" data-goto="2" <?php echo !$wiz_step1_done ? 'disabled' : ''; ?>>Continue <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg></button>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="wiz-panel <?php echo $wiz_initial === 2 ? 'active' : ''; ?>" id="wiz-panel-2">
                    <div class="wiz-panel-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg>
                    </div>
                    <h3>Import Your Knowledge Base</h3>
                    <p class="wiz-panel-desc">Sync your site content so the AI can answer questions about your business.</p>
                    <?php if ($wiz_step2_done) : ?>
                        <div class="wiz-success-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                            <?php echo esc_html($data['total_content']); ?> items synced
                        </div>
                    <?php else : ?>
                        <button type="button" id="wizard-sync" class="wiz-btn wiz-btn-primary wiz-btn-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182"/></svg>
                            Sync All Content Now
                        </button>
                        <div id="wizard-sync-result"></div>
                    <?php endif; ?>
                    <div class="wiz-nav">
                        <button type="button" class="wiz-btn wiz-btn-back" data-goto="1"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg> Back</button>
                        <button type="button" class="wiz-btn wiz-btn-next" data-goto="3" <?php echo !$wiz_step2_done ? 'disabled' : ''; ?>>Continue <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg></button>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="wiz-panel <?php echo $wiz_initial === 3 ? 'active' : ''; ?>" id="wiz-panel-3">
                    <div class="wiz-panel-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42"/></svg>
                    </div>
                    <h3>Make It Yours</h3>
                    <p class="wiz-panel-desc">Quick personalization — fine-tune everything later in Settings.</p>
                    <div class="wiz-customize-grid">
                        <div class="wiz-field">
                            <label for="wizard-bot-name">Bot Name</label>
                            <input type="text" id="wizard-bot-name" class="wiz-input" value="<?php echo esc_attr(!empty($data['bot_name']) ? $data['bot_name'] : 'AI Assistant'); ?>" placeholder="e.g. ShopBot, Ava, Support AI">
                        </div>
                        <div class="wiz-field">
                            <label for="wizard-welcome">Welcome Message</label>
                            <input type="text" id="wizard-welcome" class="wiz-input" value="<?php echo esc_attr(get_option('chatzio_settings', [])['welcome_message'] ?? 'Hi! How can I help you today?'); ?>" placeholder="e.g. Hey! Ask me anything.">
                        </div>
                        <div class="wiz-field">
                            <label for="wizard-color">Brand Color</label>
                            <input type="text" id="wizard-color" class="wiz-input chatzio-color-input" data-coloris value="<?php echo esc_attr(get_option('chatzio_settings', [])['primary_color'] ?? '#7c3aed'); ?>">
                        </div>
                    </div>
                    <div class="wiz-nav">
                        <button type="button" class="wiz-btn wiz-btn-back" data-goto="2"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg> Back</button>
                        <button type="button" id="wizard-finish" class="wiz-btn wiz-btn-launch">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.493 4.493 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                            Launch My Chatbot
                        </button>
                    </div>
                </div>
            </div>

            <p class="wiz-skip"><a href="#" id="wizard-skip-link">Skip setup — I'll configure manually</a></p>
        </div>
        <?php endif; ?>

        <?php
        // Only show status banner and statistics when setup wizard is complete
        if (get_option('chatzio_setup_complete', '0') === '1') :
        ?>
        <!-- ============================================================
             SECTION 1: Smart Status Bar
             ============================================================ -->
        <?php
        // Determine priority status message
        $status_type = 'ok';
        $status_icon = 'yes-alt';
        $status_msg  = '';

        if (!$data['plugin_enabled']) {
            $status_type = 'warn';
            $status_icon = 'warning';
            $status_msg  = 'Chatbot is <strong>disabled</strong> — <a href="' . esc_url(admin_url('admin.php?page=chatzio-settings')) . '">enable it in Settings</a> to start engaging visitors.';
        } elseif (!$data['api_configured']) {
            $status_type = 'error';
            $status_icon = 'dismiss';
            $status_msg  = 'API key not configured — <a href="' . esc_url(admin_url('admin.php?page=chatzio-settings')) . '">add your OpenRouter API key</a> so the chatbot can respond.';
        } elseif ($data['total_content'] === 0) {
            $status_type = 'warn';
            $status_icon = 'info-outline';
            $status_msg  = 'No content indexed — <a href="' . esc_url(admin_url('admin.php?page=chatzio-content')) . '">sync your site</a> so the bot has knowledge to draw from.';
        } elseif ($data['recent_errors'] > 0) {
            $status_type = 'warn';
            $status_icon = 'warning';
            $status_msg  = intval($data['recent_errors']) . ' error' . ($data['recent_errors'] > 1 ? 's' : '') . ' in the last 24 hours — <a href="' . esc_url(admin_url('admin.php?page=chatzio-logs')) . '">check Logs</a>.';
        } elseif ($data['week_conversations'] === 0) {
            $status_type = 'info';
            $status_icon = 'info-outline';
            $status_msg  = 'No conversations this week — verify the chat widget is visible on your site.';
        } else {
            $status_msg = 'All systems operational — <strong>' . intval($data['today_conversations']) . '</strong> conversation' . ($data['today_conversations'] !== 1 ? 's' : '') . ' today.';
        }
        ?>
        <?php if (!empty($status_msg)): ?>
        <div class="ov2-status ov2-status--<?php echo esc_attr($status_type); ?>" data-status-type="<?php echo esc_attr($status_type); ?>" id="chatzio-status-banner">
            <span class="dashicons dashicons-<?php echo esc_attr($status_icon); ?>"></span>
            <span class="ov2-status__text"><?php echo wp_kses($status_msg, ['a' => ['href' => []], 'strong' => []]); ?></span>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             SECTION 2: KPI Row (4 cards)
             ============================================================ -->
        <div class="ov2-kpi-row">
            <!-- Conversations This Week -->
            <div class="ov2-kpi">
                <div class="ov2-kpi__icon ov2-kpi__icon--indigo">
                    <span class="dashicons dashicons-format-chat"></span>
                </div>
                <div class="ov2-kpi__body">
                    <div class="ov2-kpi__val"><?php echo number_format($data['week_conversations']); ?></div>
                    <div class="ov2-kpi__label">Conversations (last 7 days)</div>
                    <?php if ($data['prev_week_conversations'] > 0): ?>
                        <div class="ov2-kpi__compare <?php echo $conv_diff >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $conv_diff >= 0 ? '+' : ''; ?><?php echo $conv_diff; ?> vs last week
                        </div>
                    <?php else: ?>
                        <div class="ov2-kpi__compare muted"><?php echo $data['total_conversations']; ?> total</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Leads This Week -->
            <div class="ov2-kpi">
                <div class="ov2-kpi__icon ov2-kpi__icon--green">
                    <span class="dashicons dashicons-businessperson"></span>
                </div>
                <div class="ov2-kpi__body">
                    <div class="ov2-kpi__val"><?php echo number_format($data['week_leads']); ?></div>
                    <div class="ov2-kpi__label">Leads this week</div>
                    <?php if ($data['prev_week_leads'] > 0): ?>
                        <div class="ov2-kpi__compare <?php echo $lead_diff >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $lead_diff >= 0 ? '+' : ''; ?><?php echo $lead_diff; ?> vs last week
                        </div>
                    <?php else: ?>
                        <div class="ov2-kpi__compare muted"><?php echo $data['total_leads']; ?> total</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content Indexed -->
            <div class="ov2-kpi">
                <div class="ov2-kpi__icon ov2-kpi__icon--blue">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="ov2-kpi__body">
                    <div class="ov2-kpi__val"><?php echo number_format($data['total_content']); ?></div>
                    <div class="ov2-kpi__label">Content indexed</div>
                    <div class="ov2-kpi__compare muted">
                        <?php echo $data['content_breakdown']['post']; ?>p / <?php echo $data['content_breakdown']['page']; ?>pg / <?php echo $data['content_breakdown']['product']; ?>prod
                    </div>
                </div>
            </div>

            <!-- Response Health -->
            <div class="ov2-kpi">
                <div class="ov2-kpi__icon <?php echo $data['recent_errors'] > 0 ? 'ov2-kpi__icon--red' : 'ov2-kpi__icon--teal'; ?>">
                    <span class="dashicons dashicons-<?php echo $data['recent_errors'] > 0 ? 'warning' : 'shield'; ?>"></span>
                </div>
                <div class="ov2-kpi__body">
                    <div class="ov2-kpi__val"><?php echo $data['recent_errors']; ?></div>
                    <div class="ov2-kpi__label">Errors (24h)</div>
                    <div class="ov2-kpi__compare <?php echo $data['recent_errors'] > 0 ? 'down' : 'up'; ?>">
                        <?php echo $data['recent_errors'] > 0 ? 'Needs attention' : 'All clear'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             SECTION 3: Two-Column Grid
             ============================================================ -->
        <div class="ov2-grid">

            <!-- LEFT COLUMN: System Health -->
            <div class="ov2-col">

                <!-- Knowledge Base Status -->
                <div class="ov2-card">
                    <div class="ov2-card__head">
                        <h3><span class="dashicons dashicons-database"></span> Knowledge Base</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-content')); ?>" class="ov2-card__link">Sync Content &rarr;</a>
                    </div>
                    <div class="ov2-card__body">
                        <div class="ov2-kb-grid">
                            <div class="ov2-kb-item">
                                <span class="ov2-kb-item__val"><?php echo $data['content_breakdown']['post']; ?></span>
                                <span class="ov2-kb-item__label">Posts</span>
                            </div>
                            <div class="ov2-kb-item">
                                <span class="ov2-kb-item__val"><?php echo $data['content_breakdown']['page']; ?></span>
                                <span class="ov2-kb-item__label">Pages</span>
                            </div>
                            <div class="ov2-kb-item">
                                <span class="ov2-kb-item__val"><?php echo $data['content_breakdown']['product']; ?></span>
                                <span class="ov2-kb-item__label">Products</span>
                            </div>
                            <div class="ov2-kb-item">
                                <span class="ov2-kb-item__val"><?php echo $data['total_resources']; ?></span>
                                <span class="ov2-kb-item__label">Resources</span>
                            </div>
                        </div>
                        <div class="ov2-kb-sync">
                            <span class="dashicons dashicons-clock"></span>
                            Last synced: <strong><?php echo esc_html($last_sync_display); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Chatbot Configuration Summary -->
                <div class="ov2-card">
                    <div class="ov2-card__head">
                        <h3><span class="dashicons dashicons-admin-generic"></span> Configuration</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-settings')); ?>" class="ov2-card__link">Settings &rarr;</a>
                    </div>
                    <div class="ov2-card__body">
                        <table class="ov2-config-table">
                            <tr>
                                <td class="ov2-config-label">Model</td>
                                <td class="ov2-config-val"><?php echo esc_html($model_display); ?></td>
                            </tr>
                            <tr>
                                <td class="ov2-config-label">Bot Name</td>
                                <td class="ov2-config-val"><?php echo esc_html($data['bot_name']); ?></td>
                            </tr>
                            <tr>
                                <td class="ov2-config-label">Lead Capture</td>
                                <td class="ov2-config-val">
                                    <?php if ($data['lead_capture_status'] !== 'Disabled'): ?>
                                        <span class="ov2-badge ov2-badge--green"><?php echo esc_html($data['lead_capture_status']); ?></span>
                                    <?php else: ?>
                                        <span class="ov2-badge ov2-badge--grey">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="ov2-config-label">Widget Mode</td>
                                <td class="ov2-config-val"><?php echo esc_html(ucfirst($data['widget_mode'])); ?></td>
                            </tr>
                            <tr>
                                <td class="ov2-config-label">Status</td>
                                <td class="ov2-config-val">
                                    <?php if ($data['plugin_enabled']): ?>
                                        <span class="ov2-badge ov2-badge--green">Active</span>
                                    <?php else: ?>
                                        <span class="ov2-badge ov2-badge--red">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Activity Feed -->
            <div class="ov2-col">

                <!-- Recent Conversations -->
                <div class="ov2-card">
                    <div class="ov2-card__head">
                        <h3><span class="dashicons dashicons-format-chat"></span> Recent Conversations</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-conversations')); ?>" class="ov2-card__link">View All &rarr;</a>
                    </div>
                    <div class="ov2-card__body ov2-card__body--tight">
                        <?php if (!empty($data['recent_conversations'])): ?>
                            <?php foreach ($data['recent_conversations'] as $conv):
                                $messages = json_decode($conv['messages'], true);
                                $preview = '';
                                $msg_count = 0;
                                if (is_array($messages)) {
                                    $msg_count = count($messages);
                                    foreach ($messages as $msg) {
                                        if (isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                                            $preview = mb_substr($msg['content'], 0, 70) . (mb_strlen($msg['content']) > 70 ? '...' : '');
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <div class="ov2-feed-item">
                                <div class="ov2-feed-item__icon"><span class="dashicons dashicons-admin-users"></span></div>
                                <div class="ov2-feed-item__body">
                                    <div class="ov2-feed-item__text"><?php echo esc_html($preview ?: 'No preview'); ?></div>
                                    <div class="ov2-feed-item__meta">
                                        <?php echo human_time_diff(strtotime($conv['created_at']), current_time('timestamp')); ?> ago
                                        <?php if ($msg_count > 0): ?>
                                            <span class="ov2-msg-badge"><?php echo $msg_count; ?> msg<?php echo $msg_count > 1 ? 's' : ''; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ov2-empty">
                                <span class="dashicons dashicons-format-chat"></span>
                                No conversations yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Leads -->
                <div class="ov2-card">
                    <div class="ov2-card__head">
                        <h3><span class="dashicons dashicons-businessperson"></span> Recent Leads</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-leads')); ?>" class="ov2-card__link">View All &rarr;</a>
                    </div>
                    <div class="ov2-card__body ov2-card__body--tight">
                        <?php if (!empty($data['recent_leads'])): ?>
                            <?php foreach ($data['recent_leads'] as $lead): ?>
                            <div class="ov2-feed-item">
                                <div class="ov2-feed-item__icon ov2-feed-item__icon--lead"><span class="dashicons dashicons-email"></span></div>
                                <div class="ov2-feed-item__body">
                                    <div class="ov2-feed-item__text">
                                        <?php echo esc_html($lead['email']); ?>
                                        <?php if (!empty($lead['name'])): ?>
                                            <span class="ov2-lead-name">(<?php echo esc_html($lead['name']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ov2-feed-item__meta">
                                        <?php echo human_time_diff(strtotime($lead['created_at']), current_time('timestamp')); ?> ago
                                        <?php if (!empty($lead['source'])): ?>
                                            <span class="ov2-source-badge ov2-source-badge--<?php echo esc_attr($lead['source']); ?>"><?php echo esc_html($lead['source']); ?></span>
                                        <?php endif; ?>
                                        <span class="ov2-msg-badge"><?php echo (int) $lead['conversation_count']; ?> chat<?php echo (int)$lead['conversation_count'] !== 1 ? 's' : ''; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="ov2-empty">
                                <span class="dashicons dashicons-businessperson"></span>
                                No leads captured yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             SECTION 4: Quick Actions
             ============================================================ -->
        <div class="ov2-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-analytics')); ?>" class="ov2-action">
                <span class="dashicons dashicons-chart-area"></span>
                <span>View Analytics</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-leads')); ?>" class="ov2-action">
                <span class="dashicons dashicons-businessperson"></span>
                <span>Manage Leads</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-content')); ?>" class="ov2-action">
                <span class="dashicons dashicons-update"></span>
                <span>Sync Content</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-settings')); ?>" class="ov2-action">
                <span class="dashicons dashicons-admin-settings"></span>
                <span>Settings</span>
            </a>
        </div>

        <?php endif; // End: Only show when setup wizard is complete ?>

    </div>
</div>

<style>
/* ============================================================
   Overview V2 -- Command Center
   All spacing uses !important to override global shell reset
   ============================================================ */

/* --- Status Bar --- */
.ov2-status {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 12px 18px !important;
    border-radius: 10px !important;
    margin-bottom: 22px !important;
    font-size: 13px !important;
    line-height: 1.4 !important;
}
.ov2-status .dashicons {
    font-size: 18px !important;
    width: 18px !important;
    height: 18px !important;
    flex-shrink: 0 !important;
}
.ov2-status a { text-decoration: underline !important; }
.ov2-status--ok {
    background: #ecfdf5 !important;
    border: 1px solid #a7f3d0 !important;
    color: #065f46 !important;
}
.ov2-status--ok a { color: #065f46 !important; }
.ov2-status--ok .dashicons { color: #10b981 !important; }

.ov2-status--info {
    background: #eff6ff !important;
    border: 1px solid #bfdbfe !important;
    color: #1e40af !important;
}
.ov2-status--info a { color: #1e40af !important; }
.ov2-status--info .dashicons { color: #3b82f6 !important; }

.ov2-status--warn {
    background: #fffbeb !important;
    border: 1px solid #fde68a !important;
    color: #92400e !important;
}
.ov2-status--warn a { color: #92400e !important; }
.ov2-status--warn .dashicons { color: #f59e0b !important; }

.ov2-status--error {
    background: #fef2f2 !important;
    border: 1px solid #fecaca !important;
    color: #991b1b !important;
}
.ov2-status--error a { color: #991b1b !important; }
.ov2-status--error .dashicons { color: #ef4444 !important; }

/* --- KPI Row --- */
.ov2-kpi-row {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
}
.ov2-kpi {
    background: #fff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 18px !important;
    display: flex !important;
    align-items: flex-start !important;
    gap: 14px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
}
.ov2-kpi__icon {
    width: 40px !important;
    height: 40px !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}
.ov2-kpi__icon .dashicons {
    font-size: 20px !important;
    width: 20px !important;
    height: 20px !important;
    color: #fff !important;
}
.ov2-kpi__icon--indigo { background: #4f46e5 !important; }
.ov2-kpi__icon--green  { background: #10b981 !important; }
.ov2-kpi__icon--blue   { background: #3b82f6 !important; }
.ov2-kpi__icon--teal   { background: #14b8a6 !important; }
.ov2-kpi__icon--red    { background: #ef4444 !important; }

.ov2-kpi__body { flex: 1 !important; min-width: 0 !important; }
.ov2-kpi__val {
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.1 !important;
    margin-bottom: 2px !important;
}
.ov2-kpi__label {
    font-size: 12px !important;
    color: #64748b !important;
    margin-bottom: 4px !important;
}
.ov2-kpi__compare {
    font-size: 11px !important;
    font-weight: 600 !important;
}
.ov2-kpi__compare.up { color: #10b981 !important; }
.ov2-kpi__compare.down { color: #ef4444 !important; }
.ov2-kpi__compare.muted { color: #94a3b8 !important; font-weight: 400 !important; }

/* --- Two-Column Grid --- */
.ov2-grid {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 20px !important;
    margin-bottom: 24px !important;
}
.ov2-col {
    display: flex !important;
    flex-direction: column !important;
    gap: 20px !important;
}

/* --- Cards --- */
.chatzio-overview-v2 .ov2-card {
    background: #fff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
}
.chatzio-overview-v2 .ov2-card__head {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 18px 12px !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.chatzio-overview-v2 .ov2-card__head h3 {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
}
.chatzio-overview-v2 .ov2-card__head h3 .dashicons {
    font-size: 16px !important;
    width: 16px !important;
    height: 16px !important;
    color: #4f46e5 !important;
}
.ov2-card__link {
    font-size: 12px !important;
    color: #4f46e5 !important;
    text-decoration: none !important;
    font-weight: 500 !important;
}
.ov2-card__link:hover { text-decoration: underline !important; }
.chatzio-overview-v2 .ov2-card__body {
    padding: 18px !important;
}
.chatzio-overview-v2 .ov2-card__body--tight {
    padding: 8px 18px !important;
}

/* --- Knowledge Base Grid --- */
.ov2-kb-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 12px !important;
    margin-bottom: 14px !important;
}
.ov2-kb-item {
    text-align: center !important;
    padding: 10px 4px !important;
    background: #f8fafc !important;
    border-radius: 8px !important;
}
.ov2-kb-item__val {
    display: block !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.2 !important;
}
.ov2-kb-item__label {
    display: block !important;
    font-size: 10px !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    margin-top: 2px !important;
}
.ov2-kb-sync {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 12px !important;
    color: #64748b !important;
}
.ov2-kb-sync .dashicons {
    font-size: 14px !important;
    width: 14px !important;
    height: 14px !important;
    color: #94a3b8 !important;
}

/* --- Config Table --- */
.ov2-config-table {
    width: 100% !important;
    border-collapse: collapse !important;
}
.ov2-config-table tr { border-bottom: 1px solid #f1f5f9 !important; }
.ov2-config-table tr:last-child { border-bottom: none !important; }
.ov2-config-table td {
    padding: 9px 0 !important;
    font-size: 13px !important;
    vertical-align: middle !important;
}
.ov2-config-label {
    color: #64748b !important;
    width: 110px !important;
    font-weight: 500 !important;
}
.ov2-config-val {
    color: #0f172a !important;
    font-weight: 600 !important;
}

/* --- Badges --- */
.ov2-badge {
    display: inline-block !important;
    padding: 2px 8px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
}
.ov2-badge--green { background: #ecfdf5 !important; color: #059669 !important; }
.ov2-badge--red   { background: #fef2f2 !important; color: #dc2626 !important; }
.ov2-badge--grey  { background: #f1f5f9 !important; color: #64748b !important; }

/* --- Feed Items (conversations & leads) --- */
.ov2-feed-item {
    display: flex !important;
    align-items: flex-start !important;
    gap: 10px !important;
    padding: 10px 0 !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
.ov2-feed-item:last-child { border-bottom: none !important; }

.ov2-feed-item__icon {
    width: 30px !important;
    height: 30px !important;
    border-radius: 50% !important;
    background: #eef2ff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    margin-top: 2px !important;
}
.ov2-feed-item__icon .dashicons {
    font-size: 14px !important;
    width: 14px !important;
    height: 14px !important;
    color: #4f46e5 !important;
}
.ov2-feed-item__icon--lead { background: #ecfdf5 !important; }
.ov2-feed-item__icon--lead .dashicons { color: #10b981 !important; }

.ov2-feed-item__body { flex: 1 !important; min-width: 0 !important; }
.ov2-feed-item__text {
    font-size: 13px !important;
    color: #0f172a !important;
    margin-bottom: 2px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.ov2-lead-name { color: #64748b !important; font-weight: 400 !important; }

.ov2-feed-item__meta {
    font-size: 11px !important;
    color: #94a3b8 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.ov2-msg-badge {
    display: inline-block !important;
    padding: 1px 6px !important;
    background: #f1f5f9 !important;
    border-radius: 3px !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    color: #64748b !important;
}
.ov2-source-badge {
    display: inline-block !important;
    padding: 1px 6px !important;
    border-radius: 3px !important;
    font-size: 10px !important;
    font-weight: 600 !important;
}
.ov2-source-badge--prechat { background: #eff6ff !important; color: #3b82f6 !important; }
.ov2-source-badge--inchat  { background: #ecfdf5 !important; color: #10b981 !important; }

/* --- Empty State --- */
.ov2-empty {
    text-align: center !important;
    color: #94a3b8 !important;
    padding: 24px 12px !important;
    font-size: 13px !important;
}
.ov2-empty .dashicons {
    display: block !important;
    font-size: 24px !important;
    width: 24px !important;
    height: 24px !important;
    margin: 0 auto 6px !important;
    opacity: 0.35 !important;
}

/* --- Quick Actions Row --- */
.ov2-actions {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 14px !important;
}
.ov2-action {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 14px 12px !important;
    background: #fff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    color: #4f46e5 !important;
    text-decoration: none !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    transition: background 0.15s ease, border-color 0.15s ease !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
}
.ov2-action:hover {
    background: #eef2ff !important;
    border-color: #c7d2fe !important;
    color: #4338ca !important;
}
.ov2-action .dashicons {
    font-size: 18px !important;
    width: 18px !important;
    height: 18px !important;
}

/* --- Responsive --- */
@media (max-width: 1100px) {
    .ov2-kpi-row { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 900px) {
    .ov2-grid { grid-template-columns: 1fr !important; }
    .ov2-actions { grid-template-columns: repeat(2, 1fr) !important; }
}
@media (max-width: 600px) {
    .ov2-kpi-row { grid-template-columns: 1fr !important; }
    .ov2-actions { grid-template-columns: 1fr !important; }
    .ov2-kb-grid { grid-template-columns: repeat(2, 1fr) !important; }
}
</style>

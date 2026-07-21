<?php
if (!defined('ABSPATH')) {
    exit;
}
$upgrade_url = apply_filters('chatzio_upgrade_url', 'https://chatzio.pro/pricing');
?>
<div class="wrap chatzio-admin">
    <div class="chatzio-plans-hero">
        <div class="chatzio-plans-hero-content">
            <h1><?php esc_html_e('Free vs Pro Plans', 'chatzio-ai'); ?></h1>
            <p><?php esc_html_e('Let clients start with confidence on Free, then upgrade naturally when they need higher limits and premium growth tools.', 'chatzio-ai'); ?></p>
            <p class="chatzio-plans-upgrade-cta">
                <a class="button button-primary button-hero" target="_blank" rel="noopener" href="<?php echo esc_url($upgrade_url); ?>">
                    <?php esc_html_e('Upgrade to Pro', 'chatzio-ai'); ?>
                </a>
            </p>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="chatzio-plans-download-form">
            <?php wp_nonce_field('chatzio_download_plans_csv'); ?>
            <input type="hidden" name="action" value="chatzio_download_plans_csv" />
            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Download Plan Table (CSV)', 'chatzio-ai'); ?>
            </button>
        </form>
    </div>

    <div class="chatzio-plans-grid">
        <div class="chatzio-plan-card free">
            <div class="chatzio-plan-badge"><?php esc_html_e('FREE', 'chatzio-ai'); ?></div>
            <h2><?php esc_html_e('Start Smoothly', 'chatzio-ai'); ?></h2>
            <p><?php esc_html_e('Top quality answers with a fair daily limit so users can experience value quickly.', 'chatzio-ai'); ?></p>
            <ul>
                <li><?php esc_html_e('20 messages/day', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Top quality bot responses', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Core chat widget experience', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Basic lead and conversation visibility', 'chatzio-ai'); ?></li>
            </ul>
        </div>
        <div class="chatzio-plan-card pro">
            <div class="chatzio-plan-badge"><?php esc_html_e('PRO', 'chatzio-ai'); ?></div>
            <h2><?php esc_html_e('Scale & Convert', 'chatzio-ai'); ?></h2>
            <p><?php esc_html_e('For stores that need higher volume, richer knowledge, and stronger conversion tooling.', 'chatzio-ai'); ?></p>
            <ul>
                <li><?php esc_html_e('Higher or unlimited message quota (plan-based)', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Content Sync and Resource Library', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Advanced analytics and diagnostics', 'chatzio-ai'); ?></li>
                <li><?php esc_html_e('Priority features and growth tools', 'chatzio-ai'); ?></li>
            </ul>
            <p class="chatzio-plan-card-cta">
                <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url($upgrade_url); ?>">
                    <?php esc_html_e('Get Pro Plan', 'chatzio-ai'); ?>
                </a>
            </p>
        </div>
    </div>

    <div class="chatzio-plans-table-wrap">
        <table class="widefat striped chatzio-plans-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Feature', 'chatzio-ai'); ?></th>
                    <th><?php esc_html_e('Free', 'chatzio-ai'); ?></th>
                    <th><?php esc_html_e('Pro', 'chatzio-ai'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('Daily message limit', 'chatzio-ai'); ?></strong></td>
                    <td><?php esc_html_e('20/day', 'chatzio-ai'); ?></td>
                    <td><?php esc_html_e('High or unlimited (plan-based)', 'chatzio-ai'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Response quality', 'chatzio-ai'); ?></strong></td>
                    <td><?php esc_html_e('Top quality', 'chatzio-ai'); ?></td>
                    <td><?php esc_html_e('Top quality', 'chatzio-ai'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Content Sync', 'chatzio-ai'); ?></strong></td>
                    <td><?php esc_html_e('—', 'chatzio-ai'); ?></td>
                    <td><?php esc_html_e('Included', 'chatzio-ai'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Resource Library', 'chatzio-ai'); ?></strong></td>
                    <td><?php esc_html_e('—', 'chatzio-ai'); ?></td>
                    <td><?php esc_html_e('Included', 'chatzio-ai'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Advanced Analytics', 'chatzio-ai'); ?></strong></td>
                    <td><?php esc_html_e('—', 'chatzio-ai'); ?></td>
                    <td><?php esc_html_e('Included', 'chatzio-ai'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="notice notice-info chatzio-plan-note">
        <p><strong><?php esc_html_e('Monetization principle:', 'chatzio-ai'); ?></strong>
        <?php esc_html_e('Keep free quality impressive, limit usage fairly, and lock premium growth features behind Pro.', 'chatzio-ai'); ?></p>
    </div>
</div>

<style>
.chatzio-plans-hero { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 20px; background:linear-gradient(135deg,#eff4ff,#f8faff); border:1px solid #d6e3ff; border-radius:12px; margin:8px 0 18px; }
.chatzio-plans-hero h1 { margin:0 0 6px; }
.chatzio-plans-hero p { margin:0; color:#4b5563; max-width:760px; }
.chatzio-plans-upgrade-cta { margin-top:12px !important; }
.chatzio-plans-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin:0 0 16px; }
.chatzio-plan-card { background:#fff; border:1px solid #d0d7de; border-radius:12px; padding:18px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.chatzio-plan-card.free { border-color:#cfe0ff; }
.chatzio-plan-card.pro { border-color:#c9e7d1; background:linear-gradient(180deg,#fff,#f8fffb); }
.chatzio-plan-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.5px; margin-bottom:10px; }
.chatzio-plan-card.free .chatzio-plan-badge { background:#e9f1ff; color:#1f4c93; }
.chatzio-plan-card.pro .chatzio-plan-badge { background:#e7f7ec; color:#1e6b3a; }
.chatzio-plan-card h2 { margin:0 0 8px; }
.chatzio-plan-card p { color:#4b5563; margin:0 0 10px; }
.chatzio-plan-card ul { margin:0 0 0 18px; list-style:disc; color:#374151; }
.chatzio-plan-card li { margin:5px 0; }
.chatzio-plan-card-cta { margin:14px 0 0 !important; }
.chatzio-plans-table-wrap { background:#fff; border:1px solid #d0d7de; border-radius:12px; padding:8px; }
.chatzio-plans-table th { background:#f8fafc; }
.chatzio-plan-note { margin-top:14px !important; max-width:1000px; }
@media (max-width: 782px){ .chatzio-plans-hero { flex-direction:column; align-items:flex-start; } .chatzio-plans-download-form { width:100%; } }
</style>

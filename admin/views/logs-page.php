<?php
if (!defined('ABSPATH')) exit;

// Get filter values
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
$type = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : null;

// Build filtered query
global $wpdb;
$logs_table = $wpdb->prefix . 'chatzio_logs';

$where_clauses = ['1=1'];
$where_values = [];

if (!empty($type)) {
    $where_clauses[] = "log_type = %s";
    $where_values[] = $type;
}

if (!empty($search_query)) {
    $search_term = '%' . $wpdb->esc_like($search_query) . '%';
    $where_clauses[] = "(message LIKE %s)";
    $where_values[] = $search_term;
}

if (!empty($date_from)) {
    $where_clauses[] = "DATE(created_at) >= %s";
    $where_values[] = $date_from;
}

if (!empty($date_to)) {
    $where_clauses[] = "DATE(created_at) <= %s";
    $where_values[] = $date_to;
}

$where_sql = implode(' AND ', $where_clauses);

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 20;
$offset = ($page - 1) * $per_page;

// Get filtered logs
if (!empty($where_values)) {
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $logs_table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
        array_merge($where_values, [$per_page, $offset])
    ), ARRAY_A);
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $logs_table WHERE $where_sql",
        ...$where_values
    ));
} else {
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $logs_table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ), ARRAY_A);
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE $where_sql");
}

// Stats for log type counts
$stats = [
    'errors' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE log_type = 'error'"),
    'warnings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE log_type = 'warning'"),
    'info' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE log_type = 'info'"),
    'debug' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs_table WHERE log_type = 'debug'"),
];
?>

<div class="wrap chatzio-admin chatzio-logs-v2">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-admin-tools"></span>
                    Logs & Debug
                </h1>
                <p class="chatzio-page-subtitle">Monitor errors, warnings, and performance signals.</p>
            </div>
            <div class="header-actions">
                <button type="button" id="export-logs-btn" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span> Export
                </button>
                <button type="button" id="clear-logs-btn" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span> Clear All
                </button>
            </div>
        </div>
        
        <!-- Stats Cards - 4 in one row -->
        <div class="logs-stats-row">
            <a href="<?php echo esc_url(add_query_arg(['log_type' => 'error'], admin_url('admin.php?page=chatzio-logs'))); ?>" class="stat-card-v2 stat-error <?php echo $type === 'error' ? 'stat-active' : ''; ?>">
                <div class="stat-icon">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo number_format($stats['errors']); ?></div>
                    <div class="stat-label">Errors</div>
                </div>
            </a>
            
            <a href="<?php echo esc_url(add_query_arg(['log_type' => 'warning'], admin_url('admin.php?page=chatzio-logs'))); ?>" class="stat-card-v2 stat-warning <?php echo $type === 'warning' ? 'stat-active' : ''; ?>">
                <div class="stat-icon">
                    <span class="dashicons dashicons-flag"></span>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo number_format($stats['warnings']); ?></div>
                    <div class="stat-label">Warnings</div>
                </div>
            </a>
            
            <a href="<?php echo esc_url(add_query_arg(['log_type' => 'info'], admin_url('admin.php?page=chatzio-logs'))); ?>" class="stat-card-v2 stat-info <?php echo $type === 'info' ? 'stat-active' : ''; ?>">
                <div class="stat-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo number_format($stats['info']); ?></div>
                    <div class="stat-label">Info</div>
                </div>
            </a>
            
            <a href="<?php echo esc_url(add_query_arg(['log_type' => 'debug'], admin_url('admin.php?page=chatzio-logs'))); ?>" class="stat-card-v2 stat-debug <?php echo $type === 'debug' ? 'stat-active' : ''; ?>">
                <div class="stat-icon">
                    <span class="dashicons dashicons-admin-tools"></span>
                </div>
                <div class="stat-details">
                    <div class="stat-value"><?php echo number_format($stats['debug']); ?></div>
                    <div class="stat-label">Debug</div>
                </div>
            </a>
        </div>
        
        <!-- Filters -->
        <div class="chatzio-filters-bar">
            <form method="get" action="" class="filters-form">
                <input type="hidden" name="page" value="chatzio-logs">
                
                <div class="filter-group search-group">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search logs...">
                </div>
                
                <div class="filter-group date-group">
                    <label>From:</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>
                
                <div class="filter-group date-group">
                    <label>To:</label>
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>
                
                <div class="filter-group type-group">
                    <select name="log_type">
                        <option value="">All Types</option>
                        <option value="error" <?php selected($type, 'error'); ?>>Errors</option>
                        <option value="warning" <?php selected($type, 'warning'); ?>>Warnings</option>
                        <option value="info" <?php selected($type, 'info'); ?>>Info</option>
                        <option value="debug" <?php selected($type, 'debug'); ?>>Debug</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select name="per_page" onchange="this.form.submit()">
                        <option value="10" <?php selected($per_page, 10); ?>>10 per page</option>
                        <option value="20" <?php selected($per_page, 20); ?>>20 per page</option>
                        <option value="50" <?php selected($per_page, 50); ?>>50 per page</option>
                        <option value="100" <?php selected($per_page, 100); ?>>100 per page</option>
                    </select>
            </div>
                
                <div class="filter-actions">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <?php if (!empty($search_query) || !empty($date_from) || !empty($date_to) || !empty($type)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-logs')); ?>" class="button">Clear</a>
                    <?php endif; ?>
            </div>
            </form>
        </div>
        
        <?php if (empty($logs)): ?>
            <div class="chatzio-empty-state chatzio-card">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php if (!empty($search_query) || !empty($date_from) || !empty($date_to) || !empty($type)): ?>
                    <p>No logs match your filters</p>
                    <p class="description">Try adjusting your search criteria.</p>
                <?php else: ?>
                    <p>No logs found</p>
                    <p class="description">Your chatbot is running smoothly!</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="chatzio-card chatzio-table-card">
                <table class="chatzio-table logs-table">
                    <thead>
                        <tr>
                            <th width="10%">Type</th>
                            <th width="60%">Message</th>
                            <th width="20%">Time</th>
                            <th width="10%">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $log_type = isset($log['log_type']) ? $log['log_type'] : (isset($log['type']) ? $log['type'] : 'info');
                            $log_message = isset($log['message']) ? $log['message'] : 'No message';
                            $log_created = isset($log['created_at']) ? $log['created_at'] : current_time('mysql');
                        ?>
                            <tr>
                                <td>
                                    <span class="sc-log-badge log-<?php echo esc_attr($log_type); ?>"><?php echo esc_html(strtoupper($log_type)); ?></span>
                                </td>
                                <td>
                                    <span class="log-msg-text"><?php echo esc_html(mb_substr($log_message, 0, 150)); ?><?php echo mb_strlen($log_message) > 150 ? '...' : ''; ?></span>
                                </td>
                                <td>
                                    <span class="sc-muted-text"><?php echo date('M j, g:i A', strtotime($log_created)); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($log['context'])): ?>
                                        <button type="button" class="sc-icon-btn log-toggle" data-target="context-<?php echo $log['id']; ?>" title="View context">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="sc-muted-text">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php if (!empty($log['context'])): ?>
                                <tr class="log-context-row" id="context-<?php echo $log['id']; ?>" style="display:none;">
                                    <td colspan="4" class="log-context-cell">
                                        <pre class="log-context-pre"><?php echo esc_html(json_encode(json_decode($log['context']), JSON_PRETTY_PRINT)); ?></pre>
                                    </td>
                                </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                            </div>
            
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1):
            ?>
                <div class="chatzio-pagination">
                    <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo number_format($total); ?> logs)</span>
                    <div class="pagination-links">
                        <?php
                        $pagination_args = ['page' => 'chatzio-logs', 'paged' => '%#%'];
                        if (!empty($type)) $pagination_args['log_type'] = $type;
                        if (!empty($search_query)) $pagination_args['s'] = $search_query;
                        if (!empty($date_from)) $pagination_args['date_from'] = $date_from;
                        if (!empty($date_to)) $pagination_args['date_to'] = $date_to;
                        if (!empty($_GET['per_page'])) $pagination_args['per_page'] = $per_page;
                        
                        echo paginate_links([
                            'base'      => add_query_arg($pagination_args, admin_url('admin.php')),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'prev_text' => '&larr;',
                            'next_text' => '&rarr;',
                            'type'      => 'plain',
                        ]);
                        ?>
                    </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
/* Logs Page v2 Styles */
.wrap.chatzio-admin.chatzio-logs-v2 .header-actions {
    display: flex;
    gap: 10px;
}

.wrap.chatzio-admin.chatzio-logs-v2 .header-actions .dashicons {
    margin-right: 4px;
    font-size: 16px;
    vertical-align: text-bottom;
}

/* Stats Row - 4 cards */
.wrap.chatzio-admin .logs-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (max-width: 1200px) {
    .wrap.chatzio-admin .logs-stats-row {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .wrap.chatzio-admin .logs-stats-row {
        grid-template-columns: 1fr;
    }
}

.wrap.chatzio-admin .stat-card-v2 {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 24px;
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 14px;
    transition: all 0.2s;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}

.wrap.chatzio-admin .stat-card-v2:hover {
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.1);
    transform: translateY(-2px);
    border-color: var(--sc-primary);
}

.wrap.chatzio-admin .stat-card-v2.stat-active {
    border-width: 2px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}

.wrap.chatzio-admin .stat-error.stat-active {
    border-color: var(--sc-danger);
    background: var(--sc-danger-soft);
}

.wrap.chatzio-admin .stat-warning.stat-active {
    border-color: var(--sc-warning);
    background: var(--sc-warning-soft);
}

.wrap.chatzio-admin .stat-info.stat-active {
    border-color: var(--sc-info);
    background: var(--sc-info-soft);
}

.wrap.chatzio-admin .stat-debug.stat-active {
    border-color: var(--sc-muted);
    background: var(--sc-surface-alt);
}

.wrap.chatzio-admin .stat-card-v2 .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.wrap.chatzio-admin .stat-card-v2 .stat-icon .dashicons {
    font-size: 22px;
    width: 22px;
    height: 22px;
}

.wrap.chatzio-admin .stat-error .stat-icon {
    background: var(--sc-danger-soft);
    color: var(--sc-danger);
}

.wrap.chatzio-admin .stat-warning .stat-icon {
    background: var(--sc-warning-soft);
    color: var(--sc-warning);
}

.wrap.chatzio-admin .stat-info .stat-icon {
    background: var(--sc-info-soft);
    color: var(--sc-info);
}

.wrap.chatzio-admin .stat-debug .stat-icon {
    background: var(--sc-surface-alt);
    color: var(--sc-muted);
}

.wrap.chatzio-admin .stat-card-v2 .stat-details {
    flex: 1;
}

.wrap.chatzio-admin .stat-card-v2 .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--sc-text);
    line-height: 1.1;
}

.wrap.chatzio-admin .stat-card-v2 .stat-label {
    font-size: 13px;
    color: var(--sc-muted);
    margin-top: 4px;
}

/* Filters */
.wrap.chatzio-admin .type-group select {
    padding: 8px 14px;
    border: 1px solid var(--sc-border);
    border-radius: 8px;
    font-size: 13px;
    min-width: 120px;
}

/* Logs List v2 */
.wrap.chatzio-admin .logs-list-v2 {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.wrap.chatzio-admin .log-entry-v2 {
    padding: 16px 20px;
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 12px;
    border-left: 4px solid var(--sc-border);
}

.wrap.chatzio-admin .log-entry-v2.log-error {
    border-left-color: var(--sc-danger);
}

.wrap.chatzio-admin .log-entry-v2.log-warning {
    border-left-color: var(--sc-warning);
}

.wrap.chatzio-admin .log-entry-v2.log-info {
    border-left-color: var(--sc-info);
}

.wrap.chatzio-admin .log-entry-v2.log-debug {
    border-left-color: var(--sc-muted);
}

.wrap.chatzio-admin .log-main {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 10px;
}

.wrap.chatzio-admin .log-badge {
    flex-shrink: 0;
    padding: 4px 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-radius: 6px;
}

.wrap.chatzio-admin .log-badge-error {
    background: var(--sc-danger-soft);
    color: var(--sc-danger);
}

.wrap.chatzio-admin .log-badge-warning {
    background: var(--sc-warning-soft);
    color: var(--sc-warning);
}

.wrap.chatzio-admin .log-badge-info {
    background: var(--sc-info-soft);
    color: var(--sc-info);
}

.wrap.chatzio-admin .log-badge-debug {
    background: var(--sc-surface-alt);
    color: var(--sc-muted);
}

.wrap.chatzio-admin .log-message {
    flex: 1;
    font-size: 14px;
    color: var(--sc-text);
    line-height: 1.5;
    word-break: break-word;
}

.wrap.chatzio-admin .log-meta {
    display: flex;
    align-items: center;
    gap: 12px;
}

.wrap.chatzio-admin .log-time {
    font-size: 12px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .log-toggle {
    padding: 4px 10px;
    font-size: 11px;
    background: var(--sc-surface-alt);
    border: 1px solid var(--sc-border);
    border-radius: 6px;
    color: var(--sc-muted);
    cursor: pointer;
    transition: all 0.2s;
}

.wrap.chatzio-admin .log-toggle:hover {
    background: var(--sc-primary-soft);
    border-color: var(--sc-primary);
    color: var(--sc-primary);
}

.wrap.chatzio-admin .log-context-panel {
    margin-top: 12px;
    padding: 14px;
    background: var(--sc-surface-alt);
    border-radius: 8px;
}

.wrap.chatzio-admin .log-context-panel pre {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--sc-text);
    white-space: pre-wrap;
    word-break: break-word;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle context panels
    $(document).on('click', '.log-toggle', function() {
        var targetId = $(this).data('target');
        $('#' + targetId).slideToggle(200);
    });
    
    // Stat card click handler - if already active, clear filter
    $('.logs-stats-row .stat-card-v2').on('click', function(e) {
        var $card = $(this);
        if ($card.hasClass('stat-active')) {
            e.preventDefault();
            // Clear filter by going to base URL
            window.location.href = '<?php echo esc_js(admin_url('admin.php?page=chatzio-logs')); ?>';
        }
        // Otherwise, let the link work normally
    });
    
    // Clear all logs
    $('#clear-logs-btn').on('click', function() {
        if (confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
            $.post(chatzioAdmin.ajaxUrl, {
                action: 'chatzio_clear_logs',
                nonce: chatzioAdmin.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
    });
    
    // Export logs
    $('#export-logs-btn').on('click', function() {
        var params = new URLSearchParams();
        params.set('action', 'chatzio_export_logs');
        params.set('nonce', chatzioAdmin.nonce);
        
        // Include current filters
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('log_type')) params.set('log_type', urlParams.get('log_type'));
        if (urlParams.get('s')) params.set('s', urlParams.get('s'));
        if (urlParams.get('date_from')) params.set('date_from', urlParams.get('date_from'));
        if (urlParams.get('date_to')) params.set('date_to', urlParams.get('date_to'));
        
        window.location.href = ajaxurl + '?' + params.toString();
    });
});
</script>

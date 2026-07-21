<?php 
if (!defined('ABSPATH')) exit;

// Get filter values
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$source_filter = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

// Build filtered query
global $wpdb;
$leads_table = $wpdb->prefix . 'chatzio_leads';

$where_clauses = ['1=1'];
$where_values = [];

if (!empty($search_query)) {
    $search_term = '%' . $wpdb->esc_like($search_query) . '%';
    $where_clauses[] = "(email LIKE %s OR name LIKE %s)";
    $where_values[] = $search_term;
    $where_values[] = $search_term;
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = %s";
    $where_values[] = $status_filter;
}

if (!empty($source_filter)) {
    $where_clauses[] = "source = %s";
    $where_values[] = $source_filter;
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

// Get filtered leads (order by created_at or id if NULL)
if (!empty($where_values)) {
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $leads_table WHERE $where_sql ORDER BY COALESCE(created_at, NOW()) DESC, id DESC LIMIT %d OFFSET %d",
        array_merge($where_values, [$per_page, $offset])
    ), ARRAY_A);
    $total_leads = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $leads_table WHERE $where_sql",
        ...$where_values
    ));
} else {
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $leads_table WHERE $where_sql ORDER BY COALESCE(created_at, NOW()) DESC, id DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ), ARRAY_A);
    $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE $where_sql");
}

// Ensure leads is an array
if (!is_array($leads)) {
    $leads = [];
}

// Debug: Check if there's a database error
if ($wpdb->last_error) {
    error_log('Chatzio Leads Query Error: ' . $wpdb->last_error);
}

// Stats
$total_all = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
$new_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE status = 'new'");
$contacted = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE status = 'contacted'");
$converted = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE status = 'converted'");

// Helper: Calculate lead score based on conversation count and source
function chatzio_lead_score($lead) {
    $score = 0;
    
    // Base score from source
    if ($lead['source'] === 'prechat') {
        $score += 30; // Pre-chat form shows intent
    } else {
        $score += 40; // In-chat capture shows engagement
    }
    
    // Conversation engagement
    $conv_count = (int) $lead['conversation_count'];
    if ($conv_count >= 5) {
        $score += 30;
    } elseif ($conv_count >= 3) {
        $score += 20;
    } elseif ($conv_count >= 1) {
        $score += 10;
    }
    
    // Name provided
    if (!empty($lead['name'])) {
        $score += 15;
    }
    
    // Phone provided
    if (!empty($lead['phone'])) {
        $score += 15;
    }
    
    return min(100, $score);
}

// Helper: Get intent label based on score
function chatzio_lead_intent($score) {
    if ($score >= 70) return ['label' => 'High', 'class' => 'intent-high'];
    if ($score >= 40) return ['label' => 'Medium', 'class' => 'intent-medium'];
    return ['label' => 'Low', 'class' => 'intent-low'];
}
?>

<div class="wrap chatzio-admin chatzio-leads-v2">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-businessperson"></span>
                    Leads Management
                </h1>
                <p class="chatzio-page-subtitle">Track, qualify, and manage leads captured by your AI assistant.</p>
            </div>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=chatzio_export_leads'), 'chatzio_admin_nonce', 'nonce'); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span> Export CSV
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="leads-stats-row">
            <div class="stat-card-v2 stat-primary">
                <div class="stat-icon"><span class="dashicons dashicons-businessperson"></span></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($total_all); ?></div>
                    <div class="stat-label">Total Leads</div>
                </div>
            </div>
            <div class="stat-card-v2 stat-new">
                <div class="stat-icon"><span class="dashicons dashicons-star-filled"></span></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($new_leads); ?></div>
                    <div class="stat-label">New</div>
                </div>
            </div>
            <div class="stat-card-v2 stat-contacted">
                <div class="stat-icon"><span class="dashicons dashicons-email"></span></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($contacted); ?></div>
                    <div class="stat-label">Contacted</div>
                </div>
            </div>
            <div class="stat-card-v2 stat-converted">
                <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($converted); ?></div>
                    <div class="stat-label">Converted</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="chatzio-filters-bar">
            <form method="get" action="" class="filters-form">
                <input type="hidden" name="page" value="chatzio-leads">
                
                <div class="filter-group search-group">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search by email or name...">
                </div>
                
                <div class="filter-group date-group">
                    <label>From:</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                </div>
                
                <div class="filter-group date-group">
                    <label>To:</label>
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                </div>
                
                <div class="filter-group">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="new" <?php selected($status_filter, 'new'); ?>>New</option>
                        <option value="contacted" <?php selected($status_filter, 'contacted'); ?>>Contacted</option>
                        <option value="converted" <?php selected($status_filter, 'converted'); ?>>Converted</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select name="source">
                        <option value="">All Sources</option>
                        <option value="prechat" <?php selected($source_filter, 'prechat'); ?>>Pre-chat</option>
                        <option value="inchat" <?php selected($source_filter, 'inchat'); ?>>In-chat</option>
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
                    <?php if (!empty($search_query) || !empty($status_filter) || !empty($source_filter) || !empty($date_from) || !empty($date_to)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-leads')); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php 
        // Debug info - Always show if there's a mismatch
        if ($total_all > 0 && empty($leads)) {
            // Get a sample lead to see what's in the database
            $sample_lead = $wpdb->get_row("SELECT * FROM $leads_table LIMIT 1", ARRAY_A);
            
            echo '<div class="chatzio-notice" style="background: #FFF3CD; border: 1px solid #FFC107; color: #856404; padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;">';
            echo '<p style="margin: 0 0 8px 0;"><strong>⚠️ Debug Info:</strong> Stats show ' . $total_all . ' total leads, but query returned 0 results.</p>';
            echo '<p style="margin: 0; font-size: 13px;">';
            echo '<strong>Query:</strong> ' . esc_html($where_sql) . '<br>';
            echo '<strong>Filters:</strong> ' . (!empty($where_values) ? esc_html(json_encode($where_values)) : 'none') . '<br>';
            if ($sample_lead) {
                echo '<strong>Sample Lead:</strong> Email: ' . esc_html($sample_lead['email'] ?? 'NULL') . ', Status: ' . esc_html($sample_lead['status'] ?? 'NULL') . ', Source: ' . esc_html($sample_lead['source'] ?? 'NULL') . ', Date: ' . esc_html($sample_lead['created_at'] ?? 'NULL');
            }
            if ($wpdb->last_error) {
                echo '<br><strong>DB Error:</strong> ' . esc_html($wpdb->last_error);
            }
            echo '</p></div>';
        }
        ?>
        
        <?php if (empty($leads)): ?>
            <div class="chatzio-empty-state chatzio-card">
                <span class="dashicons dashicons-businessperson"></span>
                <?php if (!empty($search_query) || !empty($status_filter) || !empty($source_filter) || !empty($date_from) || !empty($date_to)): ?>
                    <p>No leads match your filters</p>
                    <p class="description">Try adjusting your search criteria or <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-leads')); ?>">clear filters</a>.</p>
                <?php else: ?>
                    <p>No leads captured yet</p>
                    <p class="description">Leads will appear when visitors share their email through the chat.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="chatzio-card chatzio-table-card">
                <table class="chatzio-table leads-table">
                    <thead>
                        <tr>
                            <th width="30%">Contact</th>
                            <th width="12%">Score</th>
                            <th width="12%">Source</th>
                            <th width="12%">Status</th>
                            <th width="10%">Chats</th>
                            <th width="14%">Captured</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $lead): 
                            $score = chatzio_lead_score($lead);
                            $intent = chatzio_lead_intent($score);
                            $lead_time = !empty($lead['created_at']) ? $lead['created_at'] : current_time('mysql');
                        ?>
                            <tr>
                                <td class="lead-contact-cell">
                                    <div class="lead-contact">
                                        <div class="lead-avatar"><?php echo strtoupper(substr($lead['email'] ?? '?', 0, 1)); ?></div>
                                        <div>
                                            <div class="lead-email"><?php echo esc_html($lead['email']); ?></div>
                                            <?php if (!empty($lead['name'])): ?>
                                                <div class="lead-name"><?php echo esc_html($lead['name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="lead-intent-badge <?php echo $intent['class']; ?>"><?php echo $intent['label']; ?></span>
                                </td>
                                <td>
                                    <span class="lead-source-badge <?php echo esc_attr($lead['source']); ?>"><?php echo esc_html(ucfirst($lead['source'] ?? 'Unknown')); ?></span>
                                </td>
                                <td>
                                    <select class="lead-status-select" data-lead-id="<?php echo (int) $lead['id']; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('chatzio_admin_nonce')); ?>">
                                        <option value="new" <?php selected(($lead['status'] ?? 'new'), 'new'); ?>>New</option>
                                        <option value="contacted" <?php selected(($lead['status'] ?? ''), 'contacted'); ?>>Contacted</option>
                                        <option value="converted" <?php selected(($lead['status'] ?? ''), 'converted'); ?>>Converted</option>
                                    </select>
                                </td>
                                <td>
                                    <span class="chat-count"><?php echo (int)($lead['conversation_count'] ?? 0); ?></span>
                                </td>
                                <td>
                                    <span class="lead-time" title="<?php echo esc_attr(date('M j, Y g:i A', strtotime($lead_time))); ?>">
                                        <?php echo human_time_diff(strtotime($lead_time), current_time('timestamp')); ?> ago
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $total_pages = ceil($total_leads / $per_page);
            if ($total_pages > 1):
            ?>
                <div class="chatzio-pagination">
                    <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo number_format($total_leads); ?> leads)</span>
                    <div class="pagination-links">
                        <?php
                        $pagination_args = ['page' => 'chatzio-leads', 'paged' => '%#%'];
                        if (!empty($search_query)) $pagination_args['s'] = $search_query;
                        if (!empty($status_filter)) $pagination_args['status'] = $status_filter;
                        if (!empty($source_filter)) $pagination_args['source'] = $source_filter;
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

<script>
(function() {
    document.querySelectorAll('.lead-status-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var leadId = this.getAttribute('data-lead-id');
            var nonce = this.getAttribute('data-nonce');
            var status = this.value;
            var row = this.closest('tr');
            var formData = new FormData();
            formData.append('action', 'chatzio_update_lead_status');
            formData.append('nonce', nonce);
            formData.append('lead_id', leadId);
            formData.append('status', status);
            fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        row.classList.remove('status-new', 'status-contacted', 'status-converted');
                        row.classList.add('status-' + status);
                    } else {
                        alert(data.data && data.data.message ? data.data.message : 'Failed to update status');
                    }
                })
                .catch(function() { alert('Network error'); });
        });
    });
})();
</script>

<style>
/* Leads Page v2 Styles */
.wrap.chatzio-admin.chatzio-leads-v2 .header-actions .dashicons {
    margin-right: 4px;
    font-size: 16px;
    vertical-align: text-bottom;
}

/* Stats Grid */
.wrap.chatzio-admin .leads-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (max-width: 1000px) {
    .wrap.chatzio-admin .leads-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

.wrap.chatzio-admin .leads-stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px;
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 14px;
}

.wrap.chatzio-admin .leads-stat-card .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.wrap.chatzio-admin .leads-stat-card .stat-icon .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
    color: white;
}

.wrap.chatzio-admin .stat-total .stat-icon { background: linear-gradient(135deg, #4F46E5, #6366F1); }
.wrap.chatzio-admin .stat-new .stat-icon { background: linear-gradient(135deg, #F59E0B, #fbbf24); }
.wrap.chatzio-admin .stat-contacted .stat-icon { background: linear-gradient(135deg, #3B82F6, #60a5fa); }
.wrap.chatzio-admin .stat-converted .stat-icon { background: linear-gradient(135deg, #16A34A, #22c55e); }

.wrap.chatzio-admin .leads-stat-card .stat-value {
    font-size: 26px;
    font-weight: 700;
    color: var(--sc-text);
    line-height: 1.1;
}

.wrap.chatzio-admin .leads-stat-card .stat-label {
    font-size: 13px;
    color: var(--sc-muted);
    margin-top: 2px;
}

/* Leads Table - uses global .chatzio-table from admin.css */

/* Contact cell */
.wrap.chatzio-admin .lead-contact {
    display: flex;
    align-items: center;
    gap: 12px;
}

.wrap.chatzio-admin .lead-avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, var(--sc-primary), var(--sc-primary-strong));
    color: white;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
}

.wrap.chatzio-admin .lead-email {
    font-size: 14px;
    font-weight: 600;
    color: var(--sc-text);
    word-break: break-word;
}

.wrap.chatzio-admin .lead-name {
    font-size: 12px;
    color: var(--sc-muted);
    margin-top: 2px;
}

/* Intent badge */
.wrap.chatzio-admin .lead-intent-badge {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    white-space: nowrap;
}

.wrap.chatzio-admin .lead-intent-badge.intent-high {
    background: #ECFDF3;
    color: #16A34A;
}

.wrap.chatzio-admin .lead-intent-badge.intent-medium {
    background: #FFFBEB;
    color: #D97706;
}

.wrap.chatzio-admin .lead-intent-badge.intent-low {
    background: var(--sc-surface-alt);
    color: var(--sc-muted);
}

/* Source badge */
.wrap.chatzio-admin .lead-source-badge {
    font-size: 12px;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    white-space: nowrap;
}

.wrap.chatzio-admin .lead-source-badge.prechat {
    background: #EEF2FF;
    color: #4F46E5;
}

.wrap.chatzio-admin .lead-source-badge.inchat {
    background: #EFF6FF;
    color: #3B82F6;
}

/* Status badge */
.wrap.chatzio-admin .lead-status-badge {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
    white-space: nowrap;
}

.wrap.chatzio-admin .lead-status-badge.new {
    background: #ECFDF3;
    color: #16A34A;
}

.wrap.chatzio-admin .lead-status-badge.contacted {
    background: #EFF6FF;
    color: #3B82F6;
}

.wrap.chatzio-admin .lead-status-badge.converted {
    background: #FEF3C7;
    color: #D97706;
}

.wrap.chatzio-admin .lead-status-select {
    min-width: 110px;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--sc-border);
    font-size: 12px;
    background: var(--sc-surface);
}

/* Chat count */
.wrap.chatzio-admin .chat-count {
    font-weight: 600;
    color: var(--sc-text);
}

/* Lead time */
.wrap.chatzio-admin .lead-time {
    font-size: 13px;
    color: var(--sc-muted);
}

/* Responsive */
@media (max-width: 900px) {
    .wrap.chatzio-admin .leads-table {
        font-size: 13px;
    }
    .wrap.chatzio-admin .chatzio-table thead th,
    .wrap.chatzio-admin .chatzio-table tbody td {
        padding: 12px 14px;
    }
}

/* Pagination spacing */
.wrap.chatzio-admin .chatzio-pagination {
    margin-top: 20px;
}
</style>

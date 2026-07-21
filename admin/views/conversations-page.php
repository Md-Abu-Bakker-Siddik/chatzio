<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table = $wpdb->prefix . 'chatzio_conversations';
$leads_table = $wpdb->prefix . 'chatzio_leads';

// Get filter values
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
$feedback_filter = isset($_GET['feedback']) ? sanitize_text_field($_GET['feedback']) : '';
$topic_filter = isset($_GET['topic']) ? sanitize_text_field($_GET['topic']) : '';

// Build topic pills data
$topic_pills = [];
$visible_slugs = [];
$general_count = 0;
$unanswered_count = 0;
if (class_exists('Chatzio_Topic_Resolver')) {
    $topic_pills = Chatzio_Topic_Resolver::get_visible_topics();
    $visible_slugs = array_column($topic_pills, 'slug');
    $general_count = Chatzio_Topic_Resolver::get_general_count($visible_slugs);
    $unanswered_count = Chatzio_Topic_Resolver::get_unanswered_count();
}

// Filters are built below alongside the conversation query to avoid JOIN-based duplication.

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 20;
$offset = ($page - 1) * $per_page;

// --------------------------------------------------------------------------
// Count & fetch conversations — no JOIN on leads here to avoid row duplication.
// Lead data is resolved per-session in a separate step below.
// --------------------------------------------------------------------------

// For search: if the user is searching by lead name/email/phone we need to
// pre-resolve which session_ids match so we can filter without a JOIN.
$lead_session_ids = null; // null = no lead-column filter active
if (!empty($search_query)) {
    $search_term = '%' . $wpdb->esc_like($search_query) . '%';
    $lead_session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT session_id FROM $leads_table WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s",
        $search_term, $search_term, $search_term
    ));
}

// Rebuild where clauses without lead columns (those are handled above)
$conv_where = ['1=1'];
$conv_vals  = [];

if (!empty($search_query)) {
    $search_term = '%' . $wpdb->esc_like($search_query) . '%';
    if ($lead_session_ids && count($lead_session_ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($lead_session_ids), '%s'));
        $conv_where[] = "(c.user_message LIKE %s OR c.bot_response LIKE %s OR c.session_id IN ($placeholders))";
        $conv_vals[] = $search_term;
        $conv_vals[] = $search_term;
        $conv_vals = array_merge($conv_vals, $lead_session_ids);
    } else {
        $conv_where[] = "(c.user_message LIKE %s OR c.bot_response LIKE %s)";
        $conv_vals[] = $search_term;
        $conv_vals[] = $search_term;
    }
}

if (!empty($date_from)) {
    $conv_where[] = "DATE(c.created_at) >= %s";
    $conv_vals[]  = $date_from;
}
if (!empty($date_to)) {
    $conv_where[] = "DATE(c.created_at) <= %s";
    $conv_vals[]  = $date_to;
}
if ($feedback_filter !== '' && in_array($feedback_filter, ['up', 'down'], true)) {
    $conv_where[] = "c.feedback = %s";
    $conv_vals[]  = $feedback_filter;
}

// Validate topic filter against allowed values
$valid_topic_filters = array_merge($visible_slugs, ['unanswered', 'general']);
if (!empty($topic_filter) && !in_array($topic_filter, $valid_topic_filters, true)) {
    $topic_filter = ''; // discard invalid filter
}

// Topic filter
if ($topic_filter === 'unanswered') {
    // Filter to sessions that appear in the failed_topics table
    $failed_table = $wpdb->prefix . 'chatzio_failed_topics';
    $conv_where[] = "c.session_id IN (SELECT DISTINCT session_id FROM $failed_table WHERE resolved = 0)";
} elseif ($topic_filter === 'general') {
    // General = NULL, 'general', or not in visible set
    if (!empty($visible_slugs)) {
        $tp_ph = implode(',', array_fill(0, count($visible_slugs), '%s'));
        $conv_where[] = "(c.topic IS NULL OR c.topic = 'general' OR c.topic NOT IN ($tp_ph))";
        $conv_vals = array_merge($conv_vals, $visible_slugs);
    } else {
        // All are general when no visible topics
        // No additional filter needed
    }
} elseif (!empty($topic_filter)) {
    $conv_where[] = "c.topic = %s";
    $conv_vals[] = $topic_filter;
}

$conv_where_sql = implode(' AND ', $conv_where);

// Total unique sessions
if (!empty($conv_vals)) {
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT c.session_id) FROM $table c WHERE $conv_where_sql",
        ...$conv_vals
    ));
} else {
    $total = $wpdb->get_var("SELECT COUNT(DISTINCT c.session_id) FROM $table c WHERE $conv_where_sql");
}

// Fetch one row per session
$core_sql = "SELECT c.session_id,
                    MAX(c.id) AS id,
                    MAX(c.created_at) AS created_at,
                    MAX(c.user_id) AS user_id,
                    (SELECT COUNT(*) FROM $table t2 WHERE t2.session_id = c.session_id) AS exchange_count
             FROM $table c
             WHERE $conv_where_sql
             GROUP BY c.session_id
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d";

if (!empty($conv_vals)) {
    $conversations = $wpdb->get_results($wpdb->prepare(
        $core_sql,
        array_merge($conv_vals, [$per_page, $offset])
    ), ARRAY_A);
} else {
    $conversations = $wpdb->get_results($wpdb->prepare(
        $core_sql,
        $per_page, $offset
    ), ARRAY_A);
}

// --------------------------------------------------------------------------
// Resolve lead info per session in PHP (guarantees one lead per session).
// Priority: lead_id stored on conversation rows > session_id match.
// --------------------------------------------------------------------------
$session_ids_in_page = array_column($conversations, 'session_id');
$lead_map = []; // session_id => {name, email, phone}
if (!empty($session_ids_in_page)) {
    $ph = implode(',', array_fill(0, count($session_ids_in_page), '%s'));

    // 1. Leads linked via lead_id on conversation rows
    $linked = $wpdb->get_results($wpdb->prepare(
        "SELECT c.session_id, l.name, l.email, l.phone
         FROM $table c
         INNER JOIN $leads_table l ON l.id = c.lead_id
         WHERE c.session_id IN ($ph) AND c.lead_id IS NOT NULL
         GROUP BY c.session_id",
        ...$session_ids_in_page
    ), ARRAY_A);
    foreach ($linked as $row) {
        $lead_map[$row['session_id']] = $row;
    }

    // 2. Fallback: leads matched by session_id (for older records without lead_id)
    $fallback = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, name, email, phone
         FROM $leads_table
         WHERE session_id IN ($ph)
         GROUP BY session_id",
        ...$session_ids_in_page
    ), ARRAY_A);
    foreach ($fallback as $row) {
        if (!isset($lead_map[$row['session_id']])) {
            $lead_map[$row['session_id']] = $row;
        }
    }
}

// Attach lead data to each conversation row
foreach ($conversations as &$conv) {
    $sid = $conv['session_id'];
    $conv['lead_name']  = isset($lead_map[$sid]['name'])  ? $lead_map[$sid]['name']  : '';
    $conv['lead_email'] = isset($lead_map[$sid]['email']) ? $lead_map[$sid]['email'] : '';
    $conv['lead_phone'] = isset($lead_map[$sid]['phone']) ? $lead_map[$sid]['phone'] : '';
}
unset($conv);

// Resolve predominant topic per session
$topic_map = [];
if (!empty($session_ids_in_page)) {
    $tp_ph = implode(',', array_fill(0, count($session_ids_in_page), '%s'));
    $topic_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, topic, COUNT(*) as cnt
         FROM $table
         WHERE session_id IN ($tp_ph) AND topic IS NOT NULL AND topic != ''
         GROUP BY session_id, topic
         ORDER BY cnt DESC",
        ...$session_ids_in_page
    ), ARRAY_A);
    foreach ($topic_rows as $tr) {
        if (!isset($topic_map[$tr['session_id']])) {
            $topic_map[$tr['session_id']] = $tr['topic'];
        }
    }
}
foreach ($conversations as &$conv) {
    $conv['topic'] = isset($topic_map[$conv['session_id']]) ? $topic_map[$conv['session_id']] : 'general';
}
unset($conv);

// For each conversation, get the first user message for preview
foreach ($conversations as &$conv) {
    $first_exchange = $wpdb->get_row($wpdb->prepare(
        "SELECT user_message FROM $table WHERE session_id = %s ORDER BY created_at ASC LIMIT 1",
        $conv['session_id']
    ), ARRAY_A);
    $conv['first_message'] = $first_exchange ? $first_exchange['user_message'] : '';
    $conv['total_messages'] = $conv['exchange_count'] * 2; // Each exchange has 2 messages
    
    // Format display name: show lead name/phone if available, then WP user, otherwise "Guest"
    if (!empty($conv['lead_name'])) {
        $conv['display_name'] = $conv['lead_name'];
        if (!empty($conv['lead_phone'])) {
            $conv['display_name'] .= ' • ' . $conv['lead_phone'];
        }
    } elseif (!empty($conv['lead_phone'])) {
        $conv['display_name'] = $conv['lead_phone'];
    } elseif (!empty($conv['user_id'])) {
        $wp_user = get_userdata((int) $conv['user_id']);
        if ($wp_user) {
            $conv['display_name']   = $wp_user->display_name;
            $conv['wp_user_email']  = $wp_user->user_email;
        } else {
            $conv['display_name'] = 'Guest (' . substr($conv['session_id'], 0, 8) . '...)';
        }
    } else {
        $conv['display_name'] = 'Guest (' . substr($conv['session_id'], 0, 8) . '...)';
    }
}

// Time ago helper
function chatzio_time_ago($datetime) {
    $now = current_time('timestamp');
    $time = strtotime($datetime);
    $diff = $now - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

// Get first user message from messages JSON
function chatzio_get_preview($messages_json) {
    if (empty($messages_json)) return 'No messages';
    $messages = json_decode($messages_json, true);
    if (!is_array($messages) || empty($messages)) return 'No messages';
    
    foreach ($messages as $msg) {
        if (isset($msg['role']) && $msg['role'] === 'user') {
            $content = $msg['content'];
            return mb_strlen($content) > 80 ? mb_substr($content, 0, 80) . '...' : $content;
        }
    }
    return 'No messages';
}

// Count messages in conversation
function chatzio_count_messages($messages_json) {
    if (empty($messages_json)) return 0;
    $messages = json_decode($messages_json, true);
    if (!is_array($messages) || empty($messages)) return 0;
    
    $count = 0;
    foreach ($messages as $msg) {
        if (isset($msg['role']) && in_array($msg['role'], ['user', 'assistant'])) {
            $count++;
        }
    }
    return $count;
}
?>

<div class="wrap chatzio-admin chatzio-conversations-v2">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-format-chat"></span>
                    Conversations
                </h1>
                <p class="chatzio-page-subtitle">Review customer conversations. Click any row to view the full thread.</p>
            </div>
            <div class="header-actions">
                <span class="conv-count"><?php echo esc_html(number_format($total)); ?> conversation<?php echo (int)$total !== 1 ? 's' : ''; ?></span>
                <button type="button" class="button button-secondary" id="export-conversations">
                    <span class="dashicons dashicons-download"></span> Export CSV
                </button>
                <button type="button" class="button chatzio-btn-danger" id="clear-all-conversations">
                    <span class="dashicons dashicons-trash"></span> Clear All
                </button>
            </div>
        </div>
        
        <!-- Topic Pills -->
        <?php if (!empty($topic_pills) || !empty($unanswered_count)): ?>
        <div class="chatzio-topic-pills">
            <?php
            $base_url = admin_url('admin.php?page=chatzio-conversations');
            $pill_params = [];
            if (!empty($search_query)) $pill_params['s'] = $search_query;
            if (!empty($date_from)) $pill_params['date_from'] = $date_from;
            if (!empty($date_to)) $pill_params['date_to'] = $date_to;
            if (!empty($per_page) && $per_page != 20) $pill_params['per_page'] = $per_page;
            ?>
            <a href="<?php echo esc_url(add_query_arg($pill_params, $base_url)); ?>"
               class="topic-pill<?php echo empty($topic_filter) ? ' active' : ''; ?>">All</a>

            <?php foreach ($topic_pills as $pill): ?>
                <a href="<?php echo esc_url(add_query_arg(array_merge($pill_params, ['topic' => $pill['slug']]), $base_url)); ?>"
                   class="topic-pill<?php echo $topic_filter === $pill['slug'] ? ' active' : ''; ?>">
                    <?php echo esc_html(ucfirst($pill['slug'])); ?>
                    <span class="pill-count"><?php echo esc_html($pill['count']); ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($unanswered_count > 0): ?>
                <a href="<?php echo esc_url(add_query_arg(array_merge($pill_params, ['topic' => 'unanswered']), $base_url)); ?>"
                   class="topic-pill topic-pill-warn<?php echo $topic_filter === 'unanswered' ? ' active' : ''; ?>">
                    Unanswered
                    <span class="pill-count"><?php echo esc_html($unanswered_count); ?></span>
                </a>
            <?php endif; ?>

            <?php if ($general_count > 0): ?>
                <a href="<?php echo esc_url(add_query_arg(array_merge($pill_params, ['topic' => 'general']), $base_url)); ?>"
                   class="topic-pill<?php echo $topic_filter === 'general' ? ' active' : ''; ?>">
                    General
                    <span class="pill-count"><?php echo esc_html($general_count); ?></span>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Filters Bar -->
        <div class="chatzio-filters-bar">
            <form method="get" action="" class="filters-form">
                <input type="hidden" name="page" value="chatzio-conversations">
                
                <div class="filter-group search-group">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search in messages...">
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
                    <select name="per_page" onchange="this.form.submit()">
                        <option value="10" <?php selected($per_page, 10); ?>>10 per page</option>
                        <option value="20" <?php selected($per_page, 20); ?>>20 per page</option>
                        <option value="50" <?php selected($per_page, 50); ?>>50 per page</option>
                        <option value="100" <?php selected($per_page, 100); ?>>100 per page</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <?php if (!empty($search_query) || !empty($date_from) || !empty($date_to) || !empty($feedback_filter) || !empty($topic_filter)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chatzio-conversations')); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <?php if (empty($conversations)): ?>
            <div class="chatzio-empty-state chatzio-card">
                <span class="dashicons dashicons-format-chat"></span>
                <?php if (!empty($search_query) || !empty($date_from) || !empty($date_to)): ?>
                    <p>No conversations match your filters</p>
                    <p class="description">Try adjusting your search criteria.</p>
                <?php else: ?>
                    <p>No conversations yet</p>
                    <p class="description">Conversations will appear here once users start chatting.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="chatzio-card chatzio-table-card">
                <table class="chatzio-table conversations-table">
                    <thead>
                        <tr>
                            <th width="6%">ID</th>
                            <th width="18%">Client</th>
                            <th width="22%">Email</th>
                            <th width="10%">Topic</th>
                            <th width="10%">Messages</th>
                            <th width="14%">Session</th>
                            <th width="12%">Time</th>
                            <th width="6%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversations as $conv): 
                            $msg_count = isset($conv['total_messages']) ? $conv['total_messages'] : 0;
                        ?>
                            <tr class="chatzio-conv-row" data-id="<?php echo esc_attr($conv['id']); ?>" data-session="<?php echo esc_attr($conv['session_id']); ?>">
                                <td class="conv-id">#<?php echo esc_html($conv['id']); ?></td>
                                <td class="conv-client">
                                    <div class="client-info">
                                        <?php if (!empty($conv['lead_name']) || !empty($conv['lead_phone']) || !empty($conv['wp_user_email'])): ?>
                                            <span class="client-name"><?php echo esc_html($conv['display_name']); ?></span>
                                        <?php else: ?>
                                            <span class="client-guest"><?php echo esc_html($conv['display_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="conv-email">
                                    <?php
                                    $display_email = !empty($conv['lead_email']) ? $conv['lead_email'] : (!empty($conv['wp_user_email']) ? $conv['wp_user_email'] : '');
                                    ?>
                                    <?php if (!empty($display_email)): ?>
                                        <span class="email-text"><?php echo esc_html($display_email); ?></span>
                                    <?php else: ?>
                                        <span class="email-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="conv-topic">
                                    <span class="topic-badge topic-<?php echo esc_attr($conv['topic']); ?>"><?php echo esc_html(ucfirst($conv['topic'])); ?></span>
                                </td>
                                <td class="conv-messages">
                                    <span class="msg-count-badge"><?php echo esc_html($msg_count); ?></span>
                                </td>
                                <td class="conv-session">
                                    <code><?php echo esc_html(substr($conv['session_id'], 0, 12)); ?>...</code>
                                </td>
                                <td class="conv-time">
                                    <span title="<?php echo esc_attr(date('M j, Y g:i A', strtotime($conv['created_at']))); ?>">
                                        <?php echo chatzio_time_ago($conv['created_at']); ?>
                                    </span>
                                </td>
                                <td class="conv-view">
                                    <span class="dashicons dashicons-visibility"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1):
            ?>
                <div class="chatzio-pagination">
                    <span class="pagination-info">Page <?php echo esc_html($page); ?> of <?php echo esc_html($total_pages); ?></span>
                    <div class="pagination-links">
                        <?php
                        $pagination_args = ['page' => 'chatzio-conversations', 'paged' => '%#%'];
                        if (!empty($search_query)) $pagination_args['s'] = $search_query;
                        if (!empty($date_from)) $pagination_args['date_from'] = $date_from;
                        if (!empty($date_to)) $pagination_args['date_to'] = $date_to;
                        if (!empty($topic_filter)) $pagination_args['topic'] = $topic_filter;
                        if (!empty($_GET['per_page'])) $pagination_args['per_page'] = $per_page;
                        
                        echo paginate_links([
                            'base'      => add_query_arg($pagination_args, admin_url('admin.php')),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'prev_text' => '&larr; Previous',
                            'next_text' => 'Next &rarr;',
                            'type'      => 'plain',
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Conversation Modal -->
<div class="chatzio-modal-overlay" id="conv-modal-overlay"></div>
<div class="chatzio-modal" id="conv-modal">
    <div class="modal-header">
        <div class="modal-header-info">
            <h3 id="modal-title">Conversation Thread</h3>
            <span class="modal-header-date" id="modal-meta"></span>
        </div>
        <button type="button" class="modal-close" id="conv-modal-close">&times;</button>
    </div>
    <div class="modal-body" id="conv-modal-body">
        <div class="modal-loading">
            <span class="spinner is-active"></span>
            <span>Loading conversation...</span>
        </div>
    </div>
</div>

<!-- Clear Data Modal -->
<div class="chatzio-modal-overlay" id="clear-data-modal-overlay"></div>
<div class="chatzio-modal" id="clear-data-modal">
    <div class="modal-header">
        <h3>Clear Data</h3>
        <button type="button" class="modal-close" id="clear-data-modal-close">&times;</button>
    </div>
    <div class="modal-body">
        <p class="clear-data-desc">Select what you'd like to permanently delete.</p>
        <ul class="clear-data-checkboxes">
            <li>
                <label><input type="checkbox" name="clear_conversations" id="clear-conversations" checked> Conversations</label>
            </li>
            <li>
                <label><input type="checkbox" name="clear_analytics" id="clear-analytics"> Analytics events</label>
            </li>
            <li>
                <label><input type="checkbox" name="clear_failed_topics" id="clear-failed-topics"> Failed topics</label>
            </li>
        </ul>
        <p class="clear-data-warning">Leads and logs are not affected.</p>
        <div class="modal-footer">
            <button type="button" class="button" id="clear-data-cancel">Cancel</button>
            <button type="button" class="button chatzio-btn-danger" id="clear-data-confirm" disabled>Delete Selected</button>
        </div>
    </div>
</div>

<style>
/* Conversations Page v2 Styles */
.wrap.chatzio-admin.chatzio-conversations-v2 .header-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}

.wrap.chatzio-admin.chatzio-conversations-v2 .conv-count {
    font-size: 13px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin.chatzio-conversations-v2 #export-conversations .dashicons {
    margin-right: 4px;
    font-size: 16px;
    vertical-align: text-bottom;
}

/* Filters Bar */
/* Table v2 */
.wrap.chatzio-admin .conversations-table-v2 {
    border-collapse: collapse;
}

.wrap.chatzio-admin .conversations-table-v2 thead th {
    padding: 14px 16px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--sc-muted);
    background: var(--sc-surface-alt);
    border-bottom: 1px solid var(--sc-border);
}

.wrap.chatzio-admin .conversations-table-v2 tbody tr {
    cursor: pointer;
    transition: all 0.15s ease;
}

.wrap.chatzio-admin .conversations-table-v2 tbody tr:hover {
    background: var(--sc-primary-soft);
}

.wrap.chatzio-admin .conversations-table-v2 tbody td {
    padding: 16px;
    vertical-align: middle;
    border-bottom: 1px solid var(--sc-border);
}

.wrap.chatzio-admin .conv-id {
    font-weight: 600;
    color: var(--sc-muted);
    font-size: 13px;
}

.wrap.chatzio-admin .conv-client {
    font-size: 14px;
}

.wrap.chatzio-admin .client-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.wrap.chatzio-admin .client-name {
    font-weight: 600;
    color: var(--sc-text);
    font-size: 14px;
}

.wrap.chatzio-admin .client-guest {
    color: var(--sc-muted);
    font-size: 13px;
    font-style: italic;
}

.wrap.chatzio-admin .conv-email {
    font-size: 14px;
}

.wrap.chatzio-admin .email-text {
    color: var(--sc-text);
    font-size: 13px;
}

.wrap.chatzio-admin .email-empty {
    color: var(--sc-muted);
    font-size: 14px;
}

.wrap.chatzio-admin .msg-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 10px;
    background: var(--sc-primary-soft);
    color: var(--sc-primary);
    font-size: 13px;
    font-weight: 600;
    border-radius: 14px;
}

.wrap.chatzio-admin .conv-session code {
    font-size: 11px;
    padding: 4px 8px;
    background: var(--sc-surface-alt);
    border-radius: 6px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .conv-time {
    font-size: 13px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .conv-view {
    text-align: center;
}
.wrap.chatzio-admin .conv-view .dashicons {
    color: var(--sc-muted);
    font-size: 18px;
    transition: all 0.2s ease;
}
.wrap.chatzio-admin .chatzio-conv-row:hover .conv-view .dashicons {
    color: var(--sc-primary);
    transform: scale(1.1);
}

/* Pagination */
.wrap.chatzio-admin .chatzio-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    padding: 16px 20px;
    background: var(--sc-surface);
    border: 1px solid var(--sc-border);
    border-radius: 12px;
}

.wrap.chatzio-admin .pagination-info {
    font-size: 13px;
    color: var(--sc-muted);
}

.wrap.chatzio-admin .pagination-links {
    display: flex;
    gap: 8px;
}

.wrap.chatzio-admin .pagination-links a,
.wrap.chatzio-admin .pagination-links span.current {
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s;
}

.wrap.chatzio-admin .pagination-links a {
    background: var(--sc-surface-alt);
    color: var(--sc-text);
    border: 1px solid var(--sc-border);
}

.wrap.chatzio-admin .pagination-links a:hover {
    background: var(--sc-primary-soft);
    border-color: var(--sc-primary);
    color: var(--sc-primary);
}

.wrap.chatzio-admin .pagination-links span.current {
    background: var(--sc-primary);
    color: white;
    font-weight: 600;
}

/* Modal Overlay */
.chatzio-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 100000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}
.chatzio-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* Modal Shell */
.chatzio-modal {
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%) scale(0.95);
    width: 480px;
    max-width: 95vw;
    height: 80vh;
    max-height: 700px;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.3);
    z-index: 100001;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.chatzio-modal.active {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, -50%) scale(1);
}

/* Modal Header — Clean, Light */
.chatzio-modal .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}

.chatzio-modal .modal-header-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.chatzio-modal .modal-header-info h3 {
    margin: 0 !important;
    font-size: 16px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    line-height: 1.3;
}
.chatzio-modal .modal-header-date {
    font-size: 13px;
    color: #64748b;
}

.chatzio-modal .modal-close {
    width: 32px; height: 32px;
    border: none;
    background: #f1f5f9;
    border-radius: 8px;
    font-size: 20px;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.chatzio-modal .modal-close:hover {
    background: #fee2e2;
    color: #ef4444;
}

/* Chat Body — soft gray like a real chat window */
.chatzio-modal .modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
    background: #f0f2f5;
}
.chatzio-modal .modal-body::-webkit-scrollbar { width: 5px; }
.chatzio-modal .modal-body::-webkit-scrollbar-thumb { background: #c4c8cc; border-radius: 3px; }
.chatzio-modal .modal-body::-webkit-scrollbar-track { background: transparent; }

.chatzio-modal .modal-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 12px;
    padding: 60px 20px;
    color: #8b95a5;
}

/* Thread Container */
.chatzio-modal .conversation-thread {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 0;
}

.chatzio-modal .no-messages {
    text-align: center;
    padding: 60px 20px;
    color: #8b95a5;
    font-size: 14px;
}

/* Message Rows */
.chatzio-modal .thread-msg {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    margin-bottom: 8px;
    animation: msg-fadeIn 0.2s ease;
}
.chatzio-modal .thread-msg:last-child {
    margin-bottom: 0;
}

@keyframes msg-fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* User (client) messages — right aligned, white */
.chatzio-modal .thread-msg.user {
    justify-content: flex-end;
}
.chatzio-modal .thread-msg.user .msg-bubble {
    background: #fff;
    color: #1a1a2e;
    border-radius: 18px 18px 4px 18px;
    padding: 10px 16px;
    max-width: 78%;
    font-size: 14px;
    line-height: 1.6;
    word-wrap: break-word;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* AI (assistant) messages — left aligned, purple */
.chatzio-modal .thread-msg.assistant {
    justify-content: flex-start;
}
.chatzio-modal .thread-msg.assistant .msg-bubble {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #fff;
    border-radius: 18px 18px 18px 4px;
    padding: 10px 16px;
    max-width: 78%;
    font-size: 14px;
    line-height: 1.6;
    word-wrap: break-word;
    box-shadow: 0 1px 3px rgba(79, 70, 229, 0.2);
}

/* Bold in AI messages */
.chatzio-modal .thread-msg.assistant .msg-bubble strong {
    font-weight: 600;
    color: rgba(255,255,255,0.95);
}

/* Clear Data Modal */
#clear-data-modal {
    width: 420px;
    height: auto;
    max-height: 90vh;
}
#clear-data-modal .modal-body {
    padding: 20px 24px 24px;
}
.clear-data-desc {
    margin: 0 0 16px;
    font-size: 14px;
    color: var(--sc-text);
}
.clear-data-checkboxes {
    list-style: none;
    margin: 0 0 16px;
    padding: 0;
}
.clear-data-checkboxes li {
    margin: 0 0 10px;
}
.clear-data-checkboxes label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--sc-text);
}
.clear-data-checkboxes input[type="checkbox"] {
    width: 18px;
    height: 18px;
}
.clear-data-warning {
    margin: 0 0 20px;
    font-size: 12px;
    color: var(--sc-muted);
}
#clear-data-modal .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.chatzio-btn-danger {
    background: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #fff !important;
}
.chatzio-btn-danger:hover:not(:disabled) {
    background: #b91c1c !important;
    border-color: #b91c1c !important;
    color: #fff !important;
}
.chatzio-btn-danger:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<script>
(function($) {
    var conversations = <?php echo json_encode($conversations, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    
    function openModal(id) {
        var conv = conversations.find(function(c) { return c.id == id; });
        if (!conv) return;
        
        // Show loading state
        $('#conv-modal, #conv-modal-overlay').addClass('active');
        $('#conv-modal-body').html('<div class="modal-loading"><span class="spinner is-active"></span><span>Loading conversation...</span></div>');
        $('body').css('overflow', 'hidden');
        
        // Fetch ALL messages for this session via AJAX
        $.post(ajaxurl, {
            action: 'chatzio_get_conversation_thread',
            nonce: '<?php echo wp_create_nonce('chatzio_admin_nonce'); ?>',
            session_id: conv.session_id
        }, function(response) {
            if (!response.success) {
                $('#conv-modal-body').html('<div style="text-align:center;padding:40px;color:var(--sc-danger);">Error loading conversation.</div>');
                return;
            }
            
            var thread = response.data.thread || [];
            var totalMessages = response.data.total_messages || 0;
            
            // Update title and meta
            $('#modal-title').text('Chat #' + conv.id);
            
            // Format date nicely
            var dateObj = new Date(conv.created_at);
            var formattedDate = dateObj.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            $('#modal-meta').text(formattedDate + ' • ' + totalMessages + ' messages');
            
            // Build thread with chatbot-style layout (no avatars)
            var html = '<div class="conversation-thread">';
            
            if (thread.length === 0) {
                html += '<div class="no-messages"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="40" height="40" style="opacity:.3;margin-bottom:12px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg><br>No messages in this conversation.</div>';
            } else {
                thread.forEach(function(msg) {
                    var content = $('<div>').text(msg.content || '').html().replace(/\n/g, '<br>');
                    
                    html += '<div class="thread-msg ' + msg.role + '">';
                    html += '<div class="msg-bubble">' + content + '</div>';
                    html += '</div>';
                });
            }
            
            html += '</div>';
            $('#conv-modal-body').html(html);
        }).fail(function() {
            $('#conv-modal-body').html('<div style="text-align:center;padding:40px;color:var(--sc-danger);">Failed to load conversation.</div>');
        });
    }
    
    function closeModal() {
        $('#conv-modal, #conv-modal-overlay').removeClass('active');
        $('body').css('overflow', '');
    }
    
    // Click row to open
    $(document).on('click', '.chatzio-conv-row', function() {
        openModal($(this).data('id'));
    });
    
    // Close modals
    $(document).on('click', '#conv-modal-close, #conv-modal-overlay', closeModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            if ($('#clear-data-modal').hasClass('active')) closeClearDataModal();
            else closeModal();
        }
    });
    
    // Export
    $('#export-conversations').on('click', function() {
        var params = new URLSearchParams();
        params.set('action', 'chatzio_export_conversations');
        params.set('nonce', '<?php echo wp_create_nonce('chatzio_admin_nonce'); ?>');
        window.location.href = ajaxurl + '?' + params.toString();
    });

    // Clear Data Modal
    function openClearDataModal() {
        $('#clear-data-modal, #clear-data-modal-overlay').addClass('active');
        $('body').css('overflow', 'hidden');
        updateClearConfirmButton();
    }
    function closeClearDataModal() {
        $('#clear-data-modal, #clear-data-modal-overlay').removeClass('active');
        $('body').css('overflow', '');
    }
    function updateClearConfirmButton() {
        var anyChecked = $('#clear-conversations').is(':checked') ||
            $('#clear-analytics').is(':checked') ||
            $('#clear-failed-topics').is(':checked');
        $('#clear-data-confirm').prop('disabled', !anyChecked);
    }
    $('#clear-all-conversations').on('click', openClearDataModal);
    $(document).on('click', '#clear-data-modal-close, #clear-data-modal-overlay, #clear-data-cancel', closeClearDataModal);
    $(document).on('change', '#clear-conversations, #clear-analytics, #clear-failed-topics', updateClearConfirmButton);
    $('#clear-data-confirm').on('click', function() {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).text('Deleting...');
        $.post(ajaxurl, {
            action: 'chatzio_clear_conversations',
            nonce: '<?php echo wp_create_nonce('chatzio_admin_nonce'); ?>',
            clear_conversations: $('#clear-conversations').is(':checked') ? 1 : 0,
            clear_analytics: $('#clear-analytics').is(':checked') ? 1 : 0,
            clear_failed_topics: $('#clear-failed-topics').is(':checked') ? 1 : 0
        }).done(function(response) {
            if (response.success) {
                closeClearDataModal();
                window.location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'An error occurred.');
                $btn.prop('disabled', false).text('Delete Selected');
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
            $btn.prop('disabled', false).text('Delete Selected');
        });
    });
})(jQuery);
</script>

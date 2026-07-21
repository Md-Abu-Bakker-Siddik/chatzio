<?php
/**
 * Chatzio - Lead Manager
 * Handles lead capture (pre-chat form + smart in-chat detection),
 * lead storage, and admin lead management.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Lead_Manager {

    /**
     * AJAX handler: capture lead from pre-chat form
     */
    public static function handle_capture_lead() {
        if (!isset($_POST['nonce']) || (!wp_verify_nonce($_POST['nonce'], 'chatzio_nonce') && !wp_verify_nonce($_POST['nonce'], 'smartchat_nonce'))) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $name       = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $phone      = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $page_url   = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'A valid email address is required']);
            return;
        }

        $lead_id = self::capture_lead($session_id, $email, $name, 'prechat', $page_url, $phone);

        if ($lead_id) {
            // Send notification
            if (class_exists('Chatzio_Notifications')) {
                Chatzio_Notifications::notify_new_lead([
                    'id'      => $lead_id,
                    'email'   => $email,
                    'name'    => $name,
                    'phone'   => $phone,
                    'source'  => 'prechat',
                    'page_url' => $page_url,
                ]);
            }

            wp_send_json_success([
                'lead_id' => $lead_id,
                'message' => 'Lead captured successfully',
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to save lead']);
        }
    }

    /**
     * Capture a lead to the database
     *
     * @return int|false  Lead ID or false on failure
     */
    public static function capture_lead($session_id, $email, $name = '', $source = 'inchat', $page_url = '', $phone = '') {
        if (empty($email) || !is_email($email)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';

        // Check for existing lead with same email (deduplicate)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE email = %s LIMIT 1",
            $email
        ));

        if ($existing) {
            // Update conversation count and session
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET conversation_count = conversation_count + 1, session_id = %s WHERE id = %d",
                $session_id, $existing
            ));
            return (int) $existing;
        }

        $result = $wpdb->insert($table, [
            'session_id'         => $session_id,
            'email'              => $email,
            'name'               => $name,
            'phone'              => $phone,
            'source'             => $source,
            'page_url'           => $page_url,
            'conversation_count' => 1,
            'status'             => 'new',
            'created_at'         => current_time('mysql'),
        ]);

        if ($result === false) {
            Chatzio_Logger::log_error('Failed to save lead', ['email' => $email, 'error' => $wpdb->last_error]);
            return false;
        }

        // Track analytics event
        Chatzio_AJAX::handle_track_event_internal('lead_captured', $session_id, [
            'source'   => $source,
            'page_url' => $page_url,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Detect email address in a user message (smart in-chat capture)
     *
     * @return string|false  Email address or false
     */
    public static function detect_email_in_message($message) {
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $message, $matches)) {
            $email = $matches[0];
            if (is_email($email)) {
                return $email;
            }
        }
        return false;
    }

    /**
     * Get leads with optional filters
     */
    public static function get_leads($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($args['status'])) {
            $where .= " AND status = %s";
            $params[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $where .= " AND (email LIKE %s OR name LIKE %s)";
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $limit = isset($args['limit']) ? intval($args['limit']) : 50;
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;

        $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    /**
     * Get lead count
     */
    public static function get_lead_count($status = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s", $status
            ));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    /**
     * Export leads as CSV
     */
    public static function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export leads.', 'chatzio-ai'), 403);
        }
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['nonce'])), 'chatzio_admin_nonce')) {
            wp_die(esc_html__('Invalid security token.', 'chatzio-ai'), 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';

        $leads = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=chatzio-leads-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Email', 'Name', 'Phone', 'Source', 'Page URL', 'Status', 'Conversations', 'Date']);

        foreach ($leads as $lead) {
            fputcsv($output, [
                $lead['id'],
                $lead['email'],
                $lead['name'],
                $lead['phone'],
                $lead['source'],
                $lead['page_url'],
                $lead['status'],
                $lead['conversation_count'],
                $lead['created_at'],
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Mark any lead with the given email as converted (e.g. after WooCommerce order).
     *
     * @param string $email Lead's email address.
     * @return bool True if at least one lead was updated, false otherwise.
     */
    public static function mark_converted_by_email($email) {
        if (empty($email) || !is_email($email)) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';
        $updated = $wpdb->update($table, ['status' => 'converted'], ['email' => $email], ['%s'], ['%s']);
        return $updated !== false && $updated > 0;
    }

    /**
     * AJAX: Update a lead's status (new, contacted, converted).
     * Used on Leads page so "Converted" in Analytics funnel reflects manual marking.
     */
    public static function handle_update_lead_status() {
        if (!current_user_can('manage_options') || empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatzio_admin_nonce')) {
            wp_send_json_error(['message' => 'Invalid request']);
            return;
        }
        $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
        $status  = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $allowed = ['new', 'contacted', 'converted'];
        if (!$lead_id || !in_array($status, $allowed, true)) {
            wp_send_json_error(['message' => 'Invalid lead or status']);
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_leads';
        $updated = $wpdb->update($table, ['status' => $status], ['id' => $lead_id], ['%s'], ['%d']);
        if ($updated === false) {
            wp_send_json_error(['message' => 'Update failed']);
            return;
        }
        wp_send_json_success(['status' => $status]);
    }
}

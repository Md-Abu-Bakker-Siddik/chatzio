<?php
/**
 * Chatzio - Privacy (GDPR export / erasure)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Privacy {

    public static function register() {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
    }

    public static function register_exporters($exporters) {
        $exporters['chatzio-ai'] = [
            'exporter_friendly_name' => __('Chatzio', 'chatzio-ai'),
            'callback'               => [__CLASS__, 'export_personal_data'],
        ];
        return $exporters;
    }

    public static function register_erasers($erasers) {
        $erasers['chatzio-ai'] = [
            'eraser_friendly_name' => __('Chatzio', 'chatzio-ai'),
            'callback'             => [__CLASS__, 'erase_personal_data'],
        ];
        return $erasers;
    }

    public static function export_personal_data($email, $page = 1) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'chatzio_leads';
        $conv_table = $wpdb->prefix . 'chatzio_conversations';
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT id, session_id, email, name, phone, source, page_url, status, conversation_count, created_at FROM $leads_table WHERE email = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $email,
            $per_page,
            $offset
        ), ARRAY_A);

        $export_items = [];
        foreach ($leads as $lead) {
            $data = [
                ['name' => __('Lead ID', 'chatzio-ai'), 'value' => $lead['id']],
                ['name' => __('Session ID', 'chatzio-ai'), 'value' => $lead['session_id']],
                ['name' => __('Email', 'chatzio-ai'), 'value' => $lead['email']],
                ['name' => __('Name', 'chatzio-ai'), 'value' => $lead['name']],
                ['name' => __('Phone', 'chatzio-ai'), 'value' => $lead['phone']],
                ['name' => __('Source', 'chatzio-ai'), 'value' => $lead['source']],
                ['name' => __('Page URL', 'chatzio-ai'), 'value' => $lead['page_url']],
                ['name' => __('Status', 'chatzio-ai'), 'value' => $lead['status']],
                ['name' => __('Created', 'chatzio-ai'), 'value' => $lead['created_at']],
            ];
            $export_items[] = [
                'group_id'    => 'chatzio_leads',
                'group_label' => __('Chatzio Leads', 'chatzio-ai'),
                'item_id'     => 'lead-' . $lead['id'],
                'data'        => $data,
            ];
        }

        $sessions = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT session_id FROM $leads_table WHERE email = %s",
            $email
        ));
        if (!empty($sessions)) {
            $placeholders = implode(',', array_fill(0, count($sessions), '%s'));
            $convs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, session_id, user_message, bot_response, created_at FROM $conv_table WHERE session_id IN ($placeholders) ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge($sessions, [$per_page, $offset])
            ), ARRAY_A);
            foreach ($convs as $c) {
                $export_items[] = [
                    'group_id'    => 'chatzio_conversations',
                    'group_label' => __('Chatzio Conversations', 'chatzio-ai'),
                    'item_id'     => 'conv-' . $c['id'],
                    'data'        => [
                        ['name' => __('Session ID', 'chatzio-ai'), 'value' => $c['session_id']],
                        ['name' => __('User message', 'chatzio-ai'), 'value' => wp_strip_all_tags($c['user_message'])],
                        ['name' => __('Bot response', 'chatzio-ai'), 'value' => wp_strip_all_tags($c['bot_response'])],
                        ['name' => __('Date', 'chatzio-ai'), 'value' => $c['created_at']],
                    ],
                ];
            }
        }

        $done = count($export_items) < $per_page;
        return ['data' => $export_items, 'done' => $done];
    }

    public static function erase_personal_data($email, $page = 1) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'chatzio_leads';
        $conv_table = $wpdb->prefix . 'chatzio_conversations';
        $analytics_table = $wpdb->prefix . 'chatzio_analytics';

        $sessions = $wpdb->get_col($wpdb->prepare(
            "SELECT session_id FROM $leads_table WHERE email = %s",
            $email
        ));
        $items_removed = false;
        if (!empty($sessions)) {
            $placeholders = implode(',', array_fill(0, count($sessions), '%s'));
            $wpdb->query($wpdb->prepare("DELETE FROM $conv_table WHERE session_id IN ($placeholders)", $sessions));
            $wpdb->query($wpdb->prepare("DELETE FROM $analytics_table WHERE session_id IN ($placeholders)", $sessions));
            $items_removed = true;
        }
        $deleted = $wpdb->delete($leads_table, ['email' => $email], ['%s']);
        if ($deleted) {
            $items_removed = true;
        }

        return ['items_removed' => $items_removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}

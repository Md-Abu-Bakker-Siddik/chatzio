<?php
/**
 * Chatzio - Restock Notification System
 *
 * Handles subscriptions for out-of-stock product notifications.
 * Two entry points: in-chat product cards and [chatzio_restock] shortcode.
 * When WooCommerce stock status flips to 'instock', subscribers are emailed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Restock {

    /**
     * Subscribe an email to restock notifications for a product.
     *
     * @param int    $product_id  WooCommerce product ID.
     * @param string $email       Subscriber email.
     * @param string $session_id  Chat session ID (optional).
     * @param string $source      'inchat' or 'shortcode'.
     * @return array ['status' => 'subscribed'|'already_subscribed'|'error', 'message' => string]
     */
    public static function subscribe($product_id, $email, $session_id = '', $source = 'inchat') {
        global $wpdb;

        if (!is_email($email)) {
            return ['status' => 'error', 'message' => 'Invalid email address.'];
        }
        if ($product_id <= 0) {
            return ['status' => 'error', 'message' => 'Invalid product.'];
        }

        $settings = get_option('chatzio_settings', []);
        if (empty($settings['notify_restock'])) {
            return ['status' => 'error', 'message' => 'Restock notifications are not enabled.'];
        }

        $table = $wpdb->prefix . 'chatzio_restock_subscriptions';

        // Check if already subscribed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE product_id = %d AND email = %s AND status = 'active' LIMIT 1",
            $product_id, $email
        ));

        if ($existing) {
            return ['status' => 'already_subscribed', 'message' => "You're already on the list! We'll email you when it's back."];
        }

        // Create or update lead
        $lead_id = null;
        if (class_exists('Chatzio_Lead_Manager') && !empty($session_id)) {
            $lead_id = Chatzio_Lead_Manager::capture_lead($session_id, $email, '', 'restock');
        }

        // Get product title for confirmation message
        $product_title = '';
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_title = $product->get_name();
            }
        }
        if (!$product_title) {
            $product_title = 'this product';
        }

        // Insert subscription
        $result = $wpdb->insert($table, [
            'product_id' => $product_id,
            'email'      => $email,
            'session_id' => $session_id ?: null,
            'lead_id'    => $lead_id ?: null,
            'source'     => in_array($source, ['inchat', 'shortcode'], true) ? $source : 'inchat',
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%s', '%s', '%s']);

        if ($result === false) {
            return ['status' => 'error', 'message' => 'Could not save subscription. Please try again.'];
        }

        // Log analytics event
        if (class_exists('Chatzio_AJAX')) {
            $wpdb->insert($wpdb->prefix . 'chatzio_analytics', [
                'session_id' => $session_id ?: '',
                'event_type' => 'restock_requested',
                'event_data' => wp_json_encode(['product_id' => $product_id, 'source' => $source]),
                'page_url'   => '',
                'created_at' => current_time('mysql'),
            ]);
        }

        return [
            'status'  => 'subscribed',
            'message' => "You'll be notified when " . esc_html($product_title) . " is back in stock!",
        ];
    }

    /**
     * Check if an email is already subscribed for a specific product.
     *
     * @param int    $product_id
     * @param string $email
     * @return bool
     */
    public static function is_subscribed($product_id, $email) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_restock_subscriptions';

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE product_id = %d AND email = %s AND status = 'active'",
            $product_id, $email
        ));
    }

    /**
     * Get email for current session from leads table or logged-in WP user.
     *
     * @param string $session_id
     * @return string|null Email or null.
     */
    public static function get_session_email($session_id) {
        // Check leads table by session
        if (!empty($session_id)) {
            global $wpdb;
            $table = $wpdb->prefix . 'chatzio_leads';

            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM $table WHERE session_id = %s AND email IS NOT NULL AND email != '' ORDER BY id DESC LIMIT 1",
                $session_id
            ));

            if ($email) {
                return $email;
            }
        }

        // Fallback: logged-in WordPress user
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user && $user->user_email) {
                return $user->user_email;
            }
        }

        return null;
    }

    /**
     * Process restock notifications when a product comes back in stock.
     *
     * @param int $product_id WooCommerce product ID.
     */
    public static function process_restock($product_id) {
        $settings = get_option('chatzio_settings', []);
        if (empty($settings['notify_restock'])) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_restock_subscriptions';

        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email FROM $table WHERE product_id = %d AND status = 'active'",
            $product_id
        ), ARRAY_A);

        if (empty($subscribers)) {
            return;
        }

        // Get product data
        $product_data = ['title' => '', 'url' => '', 'image' => ''];
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_data['title'] = $product->get_name();
                $product_data['url']   = get_permalink($product_id);
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $product_data['image'] = wp_get_attachment_image_url($image_id, 'medium');
                }
            }
        }

        if (empty($product_data['title'])) {
            return;
        }

        // Send emails
        foreach ($subscribers as $sub) {
            if (class_exists('Chatzio_Notifications')) {
                Chatzio_Notifications::send_restock_notification($sub['email'], $product_data);
            }
        }

        // Batch-update all to notified
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = 'notified', notified_at = %s WHERE product_id = %d AND status = 'active'",
            current_time('mysql'), $product_id
        ));

        if (class_exists('Chatzio_Logger')) {
            Chatzio_Logger::log_info('Restock notifications sent', [
                'product_id' => $product_id,
                'count'      => count($subscribers),
            ]);
        }
    }

    /**
     * Cleanup old notified subscriptions (called from data retention cron).
     *
     * @param string $cutoff MySQL datetime cutoff.
     */
    public static function cleanup_notified($cutoff) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_restock_subscriptions';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE status = 'notified' AND notified_at < %s",
            $cutoff
        ));
    }
}

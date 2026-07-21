<?php
/**
 * Chatzio - API Request Caching
 * Prevents repeated identical API calls within a short TTL.
 * Handles both message hash keys and conversation ID keys.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Request_Cache {

    // Cache window: 15 minutes
    const TTL = 15 * 60;

    /**
     * Get cached response for message
     */
    public static function get($session_id, $message) {
        $key = self::generate_key($session_id, $message);
        return get_transient($key);
    }

    /**
     * Set cached response for message
     */
    public static function set($session_id, $message, $response) {
        $key = self::generate_key($session_id, $message);
        return set_transient($key, $response, self::TTL);
    }

    /**
     * Clear all cache for a specific session
     */
    public static function clear_session($session_id) {
        global $wpdb;
        $pattern = '_transient_chatzio_' . md5($session_id);
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%' . $wpdb->esc_like($pattern) . '%'
        ));
        if ($results) {
            foreach ($results as $row) {
                delete_option($row);
                // _transient_<key> → _transient_timeout_<key>
                $key_name = substr($row, strlen('_transient_'));
                delete_option('_transient_timeout_' . $key_name);
            }
        }
    }

    /**
     * Generate deterministic cache key
     */
    private static function generate_key($session_id, $message) {
        return 'chatzio_' . md5($session_id . '|' . trim($message));
    }
}

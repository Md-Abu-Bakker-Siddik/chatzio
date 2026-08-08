<?php
/**
 * Short-lived verified order context for conversational follow-ups.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Conversation_State {

    const TTL = 600;

    /**
     * Store an allowlisted successful order-tool result for exactly 10 minutes.
     *
     * Billing email and WooCommerce objects are never accepted or persisted.
     * Replacing the value naturally clears context when the customer switches
     * to another verified order.
     *
     * @param string $session_id Guest chat session ID; optional for logged-in users.
     * @param mixed  $tool_result Successful Chatzio_Order_Tool result.
     * @return bool
     */
    public static function set_verified_order($session_id, $tool_result) {
        $transient_key = self::get_transient_key($session_id);
        if (false === $transient_key || !self::is_valid_tool_result($tool_result)) {
            return false;
        }

        $verified_at = time();
        $context = array(
            'order'               => $tool_result['order'],
            'shipments'           => $tool_result['shipments'],
            'support_recommended' => $tool_result['support_recommended'],
            'verified_at'         => $verified_at,
            'expires_at'          => $verified_at + self::TTL,
        );

        return (bool) set_transient($transient_key, $context, self::TTL);
    }

    /**
     * Retrieve unexpired verified context for follow-up questions.
     *
     * @param string $session_id Guest chat session ID; optional for logged-in users.
     * @return array|false Verified context, or false when missing/expired.
     */
    public static function get_verified_order($session_id) {
        $transient_key = self::get_transient_key($session_id);
        if (false === $transient_key) {
            return false;
        }

        $context = get_transient($transient_key);
        if (!self::is_valid_context($context) || time() >= $context['expires_at']) {
            delete_transient($transient_key);
            return false;
        }

        return $context;
    }

    /**
     * Clear the verified context after logout, cancellation, or order switch.
     *
     * @param string $session_id Guest chat session ID; optional for logged-in users.
     * @return bool
     */
    public static function clear($session_id = '') {
        $transient_key = self::get_transient_key($session_id);
        if (false === $transient_key) {
            return false;
        }

        return (bool) delete_transient($transient_key);
    }

    /**
     * Compatibility alias for callers using store terminology.
     *
     * @param string $session_id Guest chat session ID.
     * @param mixed  $tool_result Successful order-tool result.
     * @return bool
     */
    public static function store_verified_order($session_id, $tool_result) {
        return self::set_verified_order($session_id, $tool_result);
    }

    /**
     * Build a privacy-conscious transient key.
     *
     * @param string $session_id Guest chat session ID.
     * @return string|false
     */
    private static function get_transient_key($session_id) {
        $user_id = (int) get_current_user_id();
        if (is_user_logged_in() && $user_id > 0) {
            $identity = 'user:' . $user_id;
        } else {
            $session_id = self::normalize_session_id($session_id);
            if (false === $session_id) {
                return false;
            }
            $identity = 'session:' . $session_id;
        }

        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $digest = hash_hmac('sha256', $blog_id . '|' . $identity, wp_salt('auth'));

        return 'chatzio_order_ctx_' . $digest;
    }

    /**
     * Validate a guest session ID without retaining unsafe input.
     *
     * @param mixed $session_id Proposed session ID.
     * @return string|false
     */
    private static function normalize_session_id($session_id) {
        if (!is_string($session_id)) {
            return false;
        }

        $session_id = trim(wp_unslash($session_id));
        if (!preg_match('/\A[A-Za-z0-9._-]{8,100}\z/D', $session_id)) {
            return false;
        }

        return $session_id;
    }

    /**
     * Enforce the strict successful tool-result schema before storage.
     *
     * @param mixed $result Proposed result.
     * @return bool
     */
    private static function is_valid_tool_result($result) {
        if (!is_array($result)) {
            return false;
        }

        $required_keys = array('ok', 'result_type', 'order', 'shipments', 'support_recommended');
        if ($required_keys !== array_keys($result)) {
            return false;
        }

        return true === $result['ok']
            && 'order_status' === $result['result_type']
            && self::is_valid_order($result['order'])
            && self::are_valid_shipments($result['shipments'])
            && is_bool($result['support_recommended']);
    }

    /**
     * Validate stored context and its explicit expiry boundary.
     *
     * @param mixed $context Proposed stored context.
     * @return bool
     */
    private static function is_valid_context($context) {
        if (!is_array($context)) {
            return false;
        }

        $required_keys = array('order', 'shipments', 'support_recommended', 'verified_at', 'expires_at');
        if ($required_keys !== array_keys($context)) {
            return false;
        }

        return self::is_valid_order($context['order'])
            && self::are_valid_shipments($context['shipments'])
            && is_bool($context['support_recommended'])
            && is_int($context['verified_at'])
            && is_int($context['expires_at'])
            && self::TTL === ($context['expires_at'] - $context['verified_at']);
    }

    /**
     * Validate the exact non-PII order allowlist.
     *
     * @param mixed $order Proposed public order data.
     * @return bool
     */
    private static function is_valid_order($order) {
        if (!is_array($order)) {
            return false;
        }

        $required_keys = array('number', 'date', 'status_code', 'status_message');
        if ($required_keys !== array_keys($order)) {
            return false;
        }

        foreach ($required_keys as $key) {
            if (!is_string($order[$key])) {
                return false;
            }
        }

        return '' !== $order['number'] && '' !== $order['status_code'];
    }

    /**
     * Validate the exact allowlisted shipment schema.
     *
     * @param mixed $shipments Proposed shipment list.
     * @return bool
     */
    private static function are_valid_shipments($shipments) {
        if (!is_array($shipments)) {
            return false;
        }

        $required_keys = array('carrier', 'tracking_number', 'tracking_url', 'shipped_date');
        foreach ($shipments as $shipment) {
            if (!is_array($shipment) || $required_keys !== array_keys($shipment)) {
                return false;
            }
            foreach ($required_keys as $key) {
                if (!is_string($shipment[$key])) {
                    return false;
                }
            }
            if ('' === $shipment['tracking_number']) {
                return false;
            }
            if ('' !== $shipment['tracking_url'] && '' === Chatzio_AST_Adapter::validate_tracking_url($shipment['tracking_url'])) {
                return false;
            }
        }

        return true;
    }
}

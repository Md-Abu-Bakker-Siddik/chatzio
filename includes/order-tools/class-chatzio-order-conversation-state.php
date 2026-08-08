<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Conversation_State {
    const TTL = 600;

    public static function get($session_id) {
        $state = get_transient(self::key($session_id));
        return is_array($state) ? $state : [];
    }

    public static function save($session_id, array $state) {
        $state['updated_at'] = time();
        set_transient(self::key($session_id), $state, self::TTL);
    }

    public static function collect($session_id, array $credentials) {
        $state = self::get($session_id);
        if (!empty($credentials['order_number'])) $state['order_number'] = $credentials['order_number'];
        if (!empty($credentials['billing_email'])) $state['billing_email'] = $credentials['billing_email'];
        if (!empty($credentials['order_number']) || !empty($credentials['billing_email'])) self::save($session_id, $state);
        return $state;
    }

    public static function verify($session_id, array $result) {
        $state = self::get($session_id);
        unset($state['billing_email'], $state['failures']);
        $state['order_number'] = isset($result['order']['number']) ? (string) $result['order']['number'] : '';
        $state['verified_result'] = $result;
        $state['verified_at'] = time();
        self::save($session_id, $state);
    }

    public static function clear($session_id) {
        delete_transient(self::key($session_id));
    }

    private static function key($session_id) {
        $ip = isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)
            ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return 'chatzio_ots_' . hash_hmac('sha256', sanitize_text_field($session_id) . '|' . $ip, wp_salt('nonce'));
    }
}

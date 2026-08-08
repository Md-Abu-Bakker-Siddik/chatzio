<?php
/**
 * Central abuse protection for every server-side order lookup path.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Rate_Limiter {

    const MAX_FAILURES       = 3;
    const LOCKOUT_TTL        = 900;
    const LOOKUP_LIMIT       = 5;
    const LOOKUP_WINDOW_TTL  = 600;

    /**
     * Check lockout state and consume one lookup slot.
     *
     * @return true|WP_Error True when allowed; a generic lockout error otherwise.
     */
    public static function check_and_consume() {
        if (self::is_locked_out()) {
            return self::lockout_error();
        }

        $key = self::key('lookups');
        $count = (int) get_transient($key);
        if ($count >= self::LOOKUP_LIMIT) {
            self::force_lock('lookup_rate_limit');
            return self::lockout_error();
        }

        set_transient($key, $count + 1, self::LOOKUP_WINDOW_TTL);
        return true;
    }

    /**
     * Record an authorization mismatch and lock after three failures.
     *
     * @param mixed $order_number Sanitized order number when available.
     * @param mixed $email        Billing email when available; always masked in logs.
     * @return bool True when the third failure activated a lockout.
     */
    public static function record_failure($order_number = '', $email = '') {
        $key = self::key('failures');
        $failures = min(self::MAX_FAILURES, (int) get_transient($key) + 1);
        set_transient($key, $failures, self::LOCKOUT_TTL);

        self::audit('Order verification failed', array(
            'order_number' => self::safe_order_number($order_number),
            'email'        => $email,
            'failure_count' => $failures,
        ), 'warning');

        if ($failures >= self::MAX_FAILURES) {
            self::force_lock('verification_failures');
            return true;
        }

        return false;
    }

    /**
     * Clear consecutive failures after successful authorization.
     *
     * @param mixed $order_number Sanitized order number when available.
     * @return void
     */
    public static function record_success($order_number = '') {
        delete_transient(self::key('failures'));
        self::audit('Order verification succeeded', array(
            'order_number' => self::safe_order_number($order_number),
        ));
    }

    /**
     * Return whether the current trusted identity is locked out.
     *
     * @return bool
     */
    public static function is_locked_out() {
        $expires_at = (int) get_transient(self::key('lockout'));
        if ($expires_at <= time()) {
            if ($expires_at > 0) {
                delete_transient(self::key('lockout'));
            }
            return false;
        }

        return true;
    }

    /**
     * Force the standard 15-minute lockout.
     *
     * @param string $reason Internal allowlisted reason.
     * @return void
     */
    public static function force_lock($reason = 'verification_failures') {
        $reason = in_array($reason, array('verification_failures', 'lookup_rate_limit'), true)
            ? $reason
            : 'verification_failures';
        $expires_at = time() + self::LOCKOUT_TTL;

        set_transient(self::key('lockout'), $expires_at, self::LOCKOUT_TTL);
        self::audit('Order lookup lockout activated', array(
            'reason'        => $reason,
            'duration'      => self::LOCKOUT_TTL,
            'identity_hash' => self::identity_hash(),
        ), 'warning');
    }

    /**
     * Public message shared by every blocked lookup path.
     *
     * @return string
     */
    public static function public_message() {
        return 'Too many unsuccessful attempts were made. Please wait 15 minutes before trying again or contact support for assistance.';
    }

    /**
     * Build a private transient key scoped to site and trusted identity.
     *
     * @param string $purpose Key purpose.
     * @return string
     */
    private static function key($purpose) {
        return 'chatzio_order_' . $purpose . '_' . self::identity_hash();
    }

    /**
     * Hash a logged-in user ID or the direct client IP for guest requests.
     *
     * @return string
     */
    private static function identity_hash() {
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        if ($user_id > 0) {
            $identity = 'user:' . $user_id;
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $ip = filter_var($ip, FILTER_VALIDATE_IP);
            $identity = 'guest:' . ($ip ? $ip : '0.0.0.0');
        }

        return hash_hmac('sha256', $blog_id . '|' . $identity, wp_salt('auth'));
    }

    /**
     * Normalize an order number for audit metadata without logging raw input.
     *
     * @param mixed $order_number Proposed order number.
     * @return string
     */
    private static function safe_order_number($order_number) {
        if (class_exists('Chatzio_Input_Validator')) {
            $order_number = Chatzio_Input_Validator::sanitize_order_number($order_number);
        }

        return is_string($order_number) && preg_match('/\A[0-9]+\z/D', $order_number)
            ? $order_number
            : '';
    }

    /**
     * Write an audit event without ever recording a raw network address.
     *
     * @param string $event Event name.
     * @param array  $context Safe event context.
     * @param string $level Log level.
     * @return void
     */
    private static function audit($event, $context, $level = 'info') {
        if (class_exists('Chatzio_Order_Audit_Logger')) {
            if (!isset($context['identity_hash'])) {
                $context['identity_hash'] = self::identity_hash();
            }
            Chatzio_Order_Audit_Logger::log($event, $context, $level);
        }
    }

    /**
     * Build a standard WP error for blocked callers.
     *
     * @return WP_Error
     */
    private static function lockout_error() {
        return new WP_Error(
            'order_lookup_locked',
            self::public_message(),
            array('status' => 429, 'retry_after' => self::LOCKOUT_TTL)
        );
    }
}

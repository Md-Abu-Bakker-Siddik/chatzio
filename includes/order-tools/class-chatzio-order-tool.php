<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Tool {
    const MAX_FAILURES = 3;
    const LOCKOUT_TTL = 900;
    const LOOKUP_LIMIT = 5;
    const LOOKUP_WINDOW = 600;

    public static function execute($session_id, array $state) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) return Chatzio_Order_Result::failure('temporarily_unavailable', 'Order lookup is temporarily unavailable. Please try again shortly.');
        if (self::locked()) return Chatzio_Order_Result::failure('rate_limited', 'Too many unsuccessful attempts were made. Please wait 15 minutes before trying again or contact support for assistance.');

        $number = Chatzio_Order_Input_Validator::validate_order_number($state['order_number'] ?? '');
        if (!$number) return Chatzio_Order_Result::failure('missing_order_number', 'Please provide the numeric order number shown in your confirmation email.');
        if (!is_user_logged_in() && !Chatzio_Order_Input_Validator::validate_email($state['billing_email'] ?? '')) return Chatzio_Order_Result::failure('missing_billing_email', 'Please provide the billing email used for the order.');
        if (!self::consume()) return Chatzio_Order_Result::failure('rate_limited', 'Too many unsuccessful attempts were made. Please wait 15 minutes before trying again or contact support for assistance.');

        try {
            $order = wc_get_order((int) $number);
            $authorized = Chatzio_Order_Authorization::authorize($order, $state['billing_email'] ?? '');
        } catch (Throwable $error) {
            Chatzio_Logger::log_error('Order tool exception', ['order_no' => $number]);
            return Chatzio_Order_Result::failure('temporarily_unavailable', 'I\'m temporarily unable to check your order. Please try again shortly or contact support.');
        }

        if (!$authorized) {
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $state['failures'] = $failures;
            Chatzio_Order_Conversation_State::save($session_id, $state);
            Chatzio_Logger::log_warning('Order verification failed', ['order_no' => $number, 'email' => self::mask($state['billing_email'] ?? ''), 'attempt' => $failures]);
            if ($failures >= self::MAX_FAILURES) {
                self::lock();
                return Chatzio_Order_Result::failure('rate_limited', 'Too many unsuccessful attempts were made. Please wait 15 minutes before trying again or contact support for assistance.');
            }
            return Chatzio_Order_Result::failure('verification_failed', 'We couldn\'t verify an order with those details. Please check the order number and billing email and try again.');
        }

        $result = Chatzio_Order_Result::from_order($order);
        Chatzio_Order_Conversation_State::verify($session_id, $result);
        Chatzio_Logger::log_info('Order verification succeeded', ['order_no' => $number]);
        return $result;
    }

    private static function consume() {
        $key = 'chatzio_ot2_count_' . self::identity();
        $count = (int) get_transient($key);
        if ($count >= self::LOOKUP_LIMIT) { self::lock(); return false; }
        set_transient($key, $count + 1, self::LOOKUP_WINDOW);
        return true;
    }
    private static function locked() { return (bool) get_transient('chatzio_ot2_lock_' . self::identity()); }
    private static function lock() { set_transient('chatzio_ot2_lock_' . self::identity(), 1, self::LOCKOUT_TTL); }
    private static function identity() {
        $ip = isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }
    private static function mask($email) {
        $at = strpos($email, '@');
        return $at > 0 ? substr($email, 0, 1) . '***' . substr($email, $at) : '***';
    }
}

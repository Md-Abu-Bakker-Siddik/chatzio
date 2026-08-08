<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Input_Validator {
    public static function extract($message) {
        $message = is_string($message) ? $message : '';
        $email = '';
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $match)) {
            $candidate = strtolower(sanitize_email($match[0]));
            if (is_email($candidate)) $email = $candidate;
            $message = str_replace($match[0], ' ', $message);
        }

        $order_number = '';
        if (preg_match('/#?\b(\d{1,10})\b/', $message, $match)) {
            $order_number = ltrim($match[1], '0');
            if ($order_number === '') $order_number = '0';
        }

        return ['order_number' => $order_number, 'billing_email' => $email];
    }

    public static function validate_order_number($value) {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^[1-9]\d{0,9}$/', $value) ? $value : '';
    }

    public static function validate_email($value) {
        $value = is_scalar($value) ? strtolower(sanitize_email((string) $value)) : '';
        return is_email($value) ? $value : '';
    }

    public static function redact($message) {
        if (!is_string($message)) return '';
        return preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', function ($match) {
            $email = strtolower($match[0]);
            $at = strpos($email, '@');
            return $at > 0 ? substr($email, 0, 1) . '***' . substr($email, $at) : '[email redacted]';
        }, $message);
    }
}

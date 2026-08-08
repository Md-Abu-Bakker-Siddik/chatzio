<?php
/**
 * Strict input validation for Chatzio order tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Input_Validator {

    /**
     * Remove email addresses before conversation history or logs leave the
     * server. Order verification receives its arguments through the approved
     * server-side tool path instead of relying on stored transcript content.
     *
     * @param mixed $text Potentially sensitive customer text.
     * @return string Redacted text.
     */
    public static function redact($text) {
        if (!is_string($text)) {
            return '';
        }

        return preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            '[email redacted]',
            $text
        );
    }

    /**
     * Sanitize an order number into its canonical numeric string.
     *
     * A single conventional leading hash is accepted at the input boundary.
     * After it is removed, the value must contain decimal digits only.
     *
     * @param mixed $order_number Proposed order number.
     * @return string|false Canonical numeric string, or false when invalid.
     */
    public static function sanitize_order_number($order_number) {
        if (is_int($order_number)) {
            $candidate = (string) $order_number;
        } elseif (is_string($order_number)) {
            $candidate = trim(wp_unslash($order_number));
        } else {
            return false;
        }

        if (isset($candidate[0]) && '#' === $candidate[0]) {
            $candidate = trim(substr($candidate, 1));
        }

        if (!preg_match('/\A[0-9]+\z/D', $candidate)) {
            return false;
        }

        $candidate = ltrim($candidate, '0');
        if ('' === $candidate) {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($candidate) > strlen($maximum)
            || (strlen($candidate) === strlen($maximum) && strcmp($candidate, $maximum) > 0)
        ) {
            return false;
        }

        return $candidate;
    }

    /**
     * Validate and normalize a WooCommerce order number.
     *
     * Only positive, base-10 integer values are accepted. Decimal values,
     * signs, scientific notation, and arbitrary order-key text are rejected.
     *
     * @param mixed $order_number Proposed order number.
     * @return int|false Normalized order number, or false when invalid.
     */
    public static function validate_order_number($order_number) {
        $candidate = self::sanitize_order_number($order_number);
        if (false === $candidate) {
            return false;
        }

        return (int) $candidate;
    }

    /**
     * Sanitize and validate an email for an order verification attempt.
     *
     * Email comparison is case-insensitive, so both stored and submitted
     * values are normalized to lowercase before authorization compares them.
     *
     * @param mixed $email Proposed billing email.
     * @return string|false Normalized email, or false when invalid.
     */
    public static function sanitize_email($email) {
        if (!is_string($email)) {
            return false;
        }

        $email = sanitize_email(trim(wp_unslash($email)));
        if ('' === $email || !is_email($email)) {
            return false;
        }

        return strtolower($email);
    }
}

// Public Sprint 1 name. Keep the original class available for compatibility
// with code written before the shorter utility name was finalized.
if (!class_exists('Chatzio_Input_Validator', false)) {
    class_alias('Chatzio_Order_Input_Validator', 'Chatzio_Input_Validator');
}

<?php
/**
 * Strict input validation for Chatzio order tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Input_Validator {

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
        if (is_int($order_number)) {
            $candidate = (string) $order_number;
        } elseif (is_string($order_number)) {
            $candidate = trim(wp_unslash($order_number));
        } else {
            return false;
        }

        if (!preg_match('/\A[0-9]+\z/D', $candidate)) {
            return false;
        }

        // Normalize leading zeroes before checking the platform integer limit.
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

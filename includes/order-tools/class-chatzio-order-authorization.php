<?php
/**
 * Server-side authorization boundary for Chatzio order tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Authorization {

    /**
     * Verify a guest order using its exact normalized billing email.
     *
     * This method intentionally returns the same false value for an unknown
     * order, an unsupported order type, invalid input, and an email mismatch.
     * Callers must expose one generic public verification-failure message for
     * all of those outcomes.
     *
     * @param mixed $order_number Proposed numeric order number.
     * @param mixed $billing_email Billing email supplied by the customer.
     * @return WC_Order|false Verified order on success; false on any failure.
     */
    public static function verify_guest($order_number, $billing_email) {
        $order_number = Chatzio_Order_Input_Validator::validate_order_number($order_number);
        $billing_email = Chatzio_Order_Input_Validator::sanitize_email($billing_email);

        if (false === $order_number || false === $billing_email) {
            return false;
        }

        $order = self::get_order($order_number);
        if (false === $order) {
            return false;
        }

        $stored_email = Chatzio_Order_Input_Validator::sanitize_email($order->get_billing_email());
        if (false === $stored_email) {
            return false;
        }

        // Both values are normalized strings; hash_equals prevents a
        // character-by-character timing side channel during comparison.
        if (!hash_equals($stored_email, $billing_email)) {
            return false;
        }

        return $order;
    }

    /**
     * Verify that an order belongs to the current logged-in WordPress user.
     *
     * The user ID is read from the authenticated WordPress session and is
     * never accepted from the request or from an AI tool call.
     *
     * @param mixed $order_number Proposed numeric order number.
     * @return WC_Order|false Verified order on success; false on any failure.
     */
    public static function verify_logged_in($order_number) {
        $order_number = Chatzio_Order_Input_Validator::validate_order_number($order_number);
        if (false === $order_number) {
            return false;
        }

        $current_user_id = (int) get_current_user_id();
        if ($current_user_id <= 0) {
            return false;
        }

        $order = self::get_order($order_number);
        if (false === $order) {
            return false;
        }

        if ((int) $order->get_customer_id() !== $current_user_id) {
            return false;
        }

        return $order;
    }

    /**
     * Retrieve a supported WooCommerce order without direct database access.
     *
     * @param int $order_number Validated numeric order number.
     * @return WC_Order|false
     */
    private static function get_order($order_number) {
        if (!function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($order_number);
        if (!$order || !is_a($order, 'WC_Order')) {
            return false;
        }

        if (!method_exists($order, 'get_type') || 'shop_order' !== $order->get_type()) {
            return false;
        }

        return $order;
    }
}

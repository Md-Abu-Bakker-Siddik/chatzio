<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Authorization {
    public static function authorize($order, $billing_email = '') {
        if (!($order instanceof WC_Order) || $order->get_type() !== 'shop_order') return false;

        if (is_user_logged_in()) {
            $customer_id = (int) $order->get_customer_id();
            return $customer_id > 0 && $customer_id === (int) get_current_user_id();
        }

        $supplied = Chatzio_Order_Input_Validator::validate_email($billing_email);
        $stored = strtolower(trim((string) $order->get_billing_email()));
        return $supplied !== '' && $stored !== '' && hash_equals($stored, $supplied);
    }
}

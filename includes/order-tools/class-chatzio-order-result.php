<?php
/**
 * Allowlisted public data mapper for WooCommerce orders.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Result {

    /**
     * Map a WooCommerce order to its public, non-PII representation.
     *
     * The returned array intentionally contains exactly four fields. Never
     * add billing, shipping, customer, payment, note, item, or metadata fields
     * here without a separate privacy and authorization review.
     *
     * @param mixed $order WooCommerce order object.
     * @return array|false Allowlisted result, or false for an invalid object.
     */
    public static function from_order($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return false;
        }

        if (!method_exists($order, 'get_type') || 'shop_order' !== $order->get_type()) {
            return false;
        }

        $status_code = sanitize_key((string) $order->get_status());
        $date_created = $order->get_date_created();
        $date = '';

        if ($date_created && is_object($date_created) && method_exists($date_created, 'date_i18n')) {
            $date = (string) $date_created->date_i18n(get_option('date_format'));
        }

        return array(
            'number'         => sanitize_text_field((string) $order->get_order_number()),
            'date'           => sanitize_text_field($date),
            'status_code'    => $status_code,
            'status_message' => self::get_public_status_message($status_code),
        );
    }

    /**
     * Return a fixed public explanation for a WooCommerce status.
     *
     * Unknown custom statuses deliberately receive no generated explanation.
     *
     * @param string $status_code Normalized WooCommerce status code.
     * @return string
     */
    private static function get_public_status_message($status_code) {
        $messages = array(
            'pending'         => 'We\'re waiting for payment confirmation.',
            'processing'      => 'Payment has been received and your order is being prepared for shipment.',
            'on-hold'         => 'Your order is on hold while we wait for confirmation.',
            'completed'       => 'Your order has been fulfilled.',
            'shipped'         => 'Your order is on its way.',
            'partial-shipped' => 'Part of your order has shipped.',
            'cancelled'       => 'This order was cancelled. Please contact support if you need help.',
            'refunded'        => 'This order has been refunded. Please contact support if you have questions about the refund.',
            'failed'          => 'Payment for this order was not completed. Please contact support if you need help.',
        );

        return isset($messages[$status_code]) ? $messages[$status_code] : '';
    }
}

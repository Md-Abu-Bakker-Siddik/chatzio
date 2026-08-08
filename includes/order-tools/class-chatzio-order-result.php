<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Result {
    public static function from_order($order) {
        $status = sanitize_key($order->get_status());
        $messages = [
            'pending' => 'We\'re waiting for payment confirmation.',
            'processing' => 'Payment has been received and your order is being prepared for shipment.',
            'on-hold' => 'Your order is on hold, usually while payment is being confirmed.',
            'completed' => 'Your order has been fulfilled.',
            'shipped' => 'Your order is on its way.',
            'partial-shipped' => 'Part of your order has shipped; the remaining items will follow.',
            'cancelled' => 'This order was cancelled. Please contact support if you need help.',
            'refunded' => 'This order has been refunded. Please contact support if you have questions about the refund.',
            'failed' => 'The payment for this order did not go through.',
        ];
        return [
            'ok' => true,
            'result_type' => 'order_status',
            'order' => [
                'number' => sanitize_text_field((string) $order->get_order_number()),
                'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '',
                'status_code' => $status,
                'status_label' => sanitize_text_field(wc_get_order_status_name($status)),
                'status_message' => isset($messages[$status]) ? $messages[$status] : '',
            ],
            'shipments' => Chatzio_AST_Adapter::shipments($order),
            'support_recommended' => in_array($status, ['cancelled', 'refunded', 'failed'], true),
        ];
    }

    public static function failure($code, $message) {
        return ['ok' => false, 'error_code' => sanitize_key($code), 'public_message' => sanitize_text_field($message)];
    }
}

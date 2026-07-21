<?php
/**
 * Chatzio - WooCommerce integration
 *
 * When a WooCommerce order is placed (processing or completed), checks if the
 * order's billing email matches a lead captured by Chatzio and automatically
 * marks that lead as "converted".
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_WooCommerce {

    /**
     * Register WooCommerce hooks (only when WC is active).
     */
    public static function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_action('woocommerce_order_status_processing', [__CLASS__, 'maybe_mark_lead_converted'], 10, 2);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'maybe_mark_lead_converted'], 10, 2);
        add_action('woocommerce_product_set_stock_status', [__CLASS__, 'on_stock_status_change'], 10, 3);
    }

    /**
     * When order reaches processing or completed, mark matching Chatzio lead as converted.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object (optional, for older WC).
     */
    public static function maybe_mark_lead_converted($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }
        $email = $order->get_billing_email();
        if (empty($email)) {
            return;
        }
        if (Chatzio_Lead_Manager::mark_converted_by_email($email)) {
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_info('Lead auto-converted from WooCommerce order', [
                    'order_id' => $order_id,
                    'email'    => $email,
                ]);
            }
        }
    }

    /**
     * When product stock status changes to 'instock', trigger restock notifications.
     *
     * @param int    $product_id Product ID.
     * @param string $status     New stock status ('instock', 'outofstock', 'onbackorder').
     * @param object $product    WC_Product object.
     */
    public static function on_stock_status_change($product_id, $status, $product = null) {
        if ($status !== 'instock') {
            return;
        }
        if (class_exists('Chatzio_Restock')) {
            Chatzio_Restock::process_restock($product_id);
        }
    }
}

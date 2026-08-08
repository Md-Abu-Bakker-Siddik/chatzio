<?php
/**
 * Deterministic HTML renderer for public Chatzio order results.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Response_Renderer {

    /**
     * Render allowlisted order and shipment data as secure HTML.
     *
     * @param array $order_result Output from Chatzio_Order_Result::from_order().
     * @param array $shipments    Output from Chatzio_AST_Adapter::get_shipments().
     * @return string Safe HTML, or an empty string for malformed order data.
     */
    public static function render_html($order_result, $shipments = array()) {
        if (!self::is_valid_order_result($order_result)) {
            return '';
        }

        if (!is_array($shipments)) {
            $shipments = array();
        }

        $number = (string) $order_result['number'];
        $date = (string) $order_result['date'];
        $status_code = (string) $order_result['status_code'];
        $status_message = (string) $order_result['status_message'];
        $status_label = self::get_status_label($status_code);

        $html = '<section class="chatzio-order-result" aria-live="polite">';
        $html .= '<header class="chatzio-order-result__header">';
        $html .= '<h3 class="chatzio-order-result__title">Order #' . esc_html($number) . '</h3>';
        if ('' !== $date) {
            $html .= '<p class="chatzio-order-result__date">Placed: ' . esc_html($date) . '</p>';
        }
        $html .= '</header>';
        $html .= '<p class="chatzio-order-result__status">Status: <strong>' . esc_html($status_label) . '</strong></p>';
        if ('' !== $status_message) {
            $html .= '<p class="chatzio-order-result__message">' . esc_html($status_message) . '</p>';
        }

        $rendered_shipments = 0;
        foreach ($shipments as $shipment) {
            if (!is_array($shipment) || empty($shipment['tracking_number'])) {
                continue;
            }

            $rendered_shipments++;
            $carrier = isset($shipment['carrier']) ? (string) $shipment['carrier'] : '';
            $tracking_number = (string) $shipment['tracking_number'];
            $shipped_date = isset($shipment['shipped_date']) ? (string) $shipment['shipped_date'] : '';
            $tracking_url = isset($shipment['tracking_url'])
                ? self::validate_https_url($shipment['tracking_url'])
                : '';

            $html .= '<section class="chatzio-order-shipment">';
            $html .= '<h4 class="chatzio-order-shipment__title">Shipment ' . esc_html((string) $rendered_shipments) . '</h4>';
            if ('' !== $carrier) {
                $html .= '<p class="chatzio-order-shipment__carrier">Carrier: ' . esc_html($carrier) . '</p>';
            }
            $html .= '<p class="chatzio-order-shipment__number">Tracking: ' . esc_html($tracking_number) . '</p>';
            if ('' !== $shipped_date) {
                $html .= '<p class="chatzio-order-shipment__date">Shipped: ' . esc_html($shipped_date) . '</p>';
            }
            if ('' !== $tracking_url) {
                $html .= '<p class="chatzio-order-shipment__action"><a class="chatzio-order-tracking-link" href="'
                    . esc_url($tracking_url)
                    . '" target="_blank" rel="noopener noreferrer nofollow">Track shipment</a></p>';
            }
            $html .= '</section>';
        }

        if (0 === $rendered_shipments) {
            $html .= '<p class="chatzio-order-result__no-tracking">Tracking information is not available yet. Please check again after the order ships.</p>';
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * Validate the exact allowlisted order result shape.
     *
     * @param mixed $result Proposed order result.
     * @return bool
     */
    private static function is_valid_order_result($result) {
        if (!is_array($result)) {
            return false;
        }

        $required_keys = array('number', 'date', 'status_code', 'status_message');
        if ($required_keys !== array_keys($result)) {
            return false;
        }

        foreach ($required_keys as $key) {
            if (!is_string($result[$key])) {
                return false;
            }
        }

        return '' !== $result['number'] && '' !== $result['status_code'];
    }

    /**
     * Convert a public status code to a display label.
     *
     * @param string $status_code Public WooCommerce status code.
     * @return string
     */
    private static function get_status_label($status_code) {
        if (function_exists('wc_get_order_status_name')) {
            return (string) wc_get_order_status_name($status_code);
        }

        return ucwords(str_replace('-', ' ', $status_code));
    }

    /**
     * Revalidate tracking URLs at the output boundary.
     *
     * @param mixed $url Proposed tracking URL.
     * @return string
     */
    private static function validate_https_url($url) {
        if (class_exists('Chatzio_AST_Adapter')) {
            return Chatzio_AST_Adapter::validate_tracking_url($url);
        }

        return '';
    }
}

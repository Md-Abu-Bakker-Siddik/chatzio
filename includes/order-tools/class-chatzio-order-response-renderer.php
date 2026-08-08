<?php
if (!defined('ABSPATH')) exit;

class Chatzio_Order_Response_Renderer {
    public static function render(array $result, $view = 'full') {
        if (empty($result['ok'])) return self::message($result['public_message'] ?? 'I\'m temporarily unable to check your order. Please try again shortly.');
        $order = $result['order'];
        $shipments = isset($result['shipments']) && is_array($result['shipments']) ? $result['shipments'] : [];

        if ($view === 'status') {
            $message = 'Order #' . $order['number'] . ' is ' . $order['status_label'] . '.';
            if (!empty($order['status_message'])) $message .= ' ' . $order['status_message'];
            return self::message($message);
        }
        if ($view === 'carrier') {
            $carriers = array_values(array_unique(array_filter(array_column($shipments, 'carrier'))));
            return self::message($carriers ? 'Your shipment is being handled by ' . implode(', ', $carriers) . '.' : 'A carrier has not been added to this order yet.');
        }
        if ($view === 'tracking') return self::render_shipments($shipments, true);
        if ($view === 'shipped_date') {
            $dates = array_values(array_unique(array_filter(array_column($shipments, 'shipped_date'))));
            return self::message($dates ? 'The shipment date is ' . implode(', ', $dates) . '.' : 'A shipment date is not available yet.');
        }

        $html = '<section class="chatzio-order-result" aria-label="Verified order status">';
        $html .= '<h3>Order #' . esc_html($order['number']) . '</h3>';
        if (!empty($order['date'])) $html .= '<p class="chatzio-order-result__date">Placed ' . esc_html($order['date']) . '</p>';
        $html .= '<p><strong>Status:</strong> ' . esc_html($order['status_label']) . '</p>';
        if (!empty($order['status_message'])) $html .= '<p>' . esc_html($order['status_message']) . '</p>';
        $html .= self::shipments_html($shipments);
        $html .= '</section>';

        $text = 'Order #' . $order['number'] . ' — ' . $order['status_label'];
        if (!empty($order['status_message'])) $text .= '. ' . $order['status_message'];
        foreach ($shipments as $shipment) {
            $text .= ' Tracking: ' . trim(($shipment['carrier'] ? $shipment['carrier'] . ' ' : '') . $shipment['tracking_number']);
            if ($shipment['tracking_url']) $text .= ' ' . $shipment['tracking_url'];
        }
        return ['html' => $html, 'raw' => $text, 'message_type' => 'order_status', 'conversation_state' => 'verified_order'];
    }

    public static function message($message) {
        return ['html' => '<p>' . esc_html($message) . '</p>', 'raw' => $message, 'message_type' => 'order_support', 'conversation_state' => 'order_support'];
    }

    private static function render_shipments(array $shipments, $only_tracking = false) {
        if (!$shipments) return self::message('Tracking information is not available yet. Please check again after the order ships.');
        $html = '<div class="chatzio-order-result">' . self::shipments_html($shipments) . '</div>';
        $text = '';
        foreach ($shipments as $shipment) {
            $text .= trim(($shipment['carrier'] ? $shipment['carrier'] . ': ' : '') . $shipment['tracking_number']);
            if ($shipment['tracking_url']) $text .= ' ' . $shipment['tracking_url'];
            $text .= "\n";
        }
        return ['html' => $html, 'raw' => trim($text), 'message_type' => 'order_status', 'conversation_state' => 'verified_order'];
    }

    private static function shipments_html(array $shipments) {
        if (!$shipments) return '<p class="chatzio-order-result__notice">Tracking information is not available yet. Please check again after the order ships.</p>';
        $html = '<div class="chatzio-order-result__shipments">';
        foreach ($shipments as $index => $shipment) {
            $html .= '<section class="chatzio-order-result__shipment"><h4>Shipment ' . (int) ($index + 1) . '</h4>';
            if ($shipment['carrier']) $html .= '<p><strong>Carrier:</strong> ' . esc_html($shipment['carrier']) . '</p>';
            $html .= '<p><strong>Tracking:</strong> <span class="chatzio-order-result__tracking">' . esc_html($shipment['tracking_number']) . '</span></p>';
            if ($shipment['shipped_date']) $html .= '<p><strong>Shipped:</strong> ' . esc_html($shipment['shipped_date']) . '</p>';
            if ($shipment['tracking_url']) $html .= '<p><a class="chatzio-order-result__link" href="' . esc_url($shipment['tracking_url'], ['https']) . '" target="_blank" rel="noopener noreferrer nofollow">Track shipment</a></p>';
            $html .= '</section>';
        }
        return $html . '</div>';
    }
}

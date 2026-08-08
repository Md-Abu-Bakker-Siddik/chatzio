<?php
if (!defined('ABSPATH')) exit;

class Chatzio_AST_Adapter {
    public static function shipments($order) {
        $raw = [];
        try {
            if (class_exists('AST_Pro_Actions')) {
                $raw = AST_Pro_Actions::get_instance()->get_tracking_items($order->get_id(), true);
            }
        } catch (Throwable $error) {
            Chatzio_Logger::log_warning('AST tracking lookup failed', ['order_no' => $order->get_order_number()]);
            $raw = [];
        }
        if (!is_array($raw) || !$raw) $raw = $order->get_meta('_wc_shipment_tracking_items', true);
        if (!is_array($raw)) return [];

        $shipments = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['tracking_number'])) continue;
            $number = sanitize_text_field((string) $item['tracking_number']);
            $carrier = self::first($item, ['formatted_tracking_provider', 'tracking_provider', 'custom_tracking_provider']);
            $url = self::https_url(self::first($item, ['ast_tracking_link', 'formatted_tracking_link', 'custom_tracking_link']));
            $date = '';
            if (!empty($item['date_shipped'])) {
                $timestamp = is_numeric($item['date_shipped']) ? (int) $item['date_shipped'] : strtotime($item['date_shipped']);
                if ($timestamp) $date = date_i18n(get_option('date_format'), $timestamp);
            }
            $key = strtolower($carrier . '|' . $number . '|' . $url);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $shipments[] = ['carrier' => $carrier, 'tracking_number' => $number, 'tracking_url' => $url, 'shipped_date' => $date];
        }
        return $shipments;
    }

    private static function first(array $item, array $keys) {
        foreach ($keys as $key) if (!empty($item[$key])) return sanitize_text_field((string) $item[$key]);
        return '';
    }

    private static function https_url($url) {
        $url = esc_url_raw((string) $url, ['https']);
        if (!$url) return '';
        $parts = wp_parse_url($url);
        return isset($parts['scheme'], $parts['host']) && strtolower($parts['scheme']) === 'https' ? $url : '';
    }
}

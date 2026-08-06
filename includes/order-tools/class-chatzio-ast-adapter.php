<?php
/**
 * Restricted Advanced Shipment Tracking Pro adapter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_AST_Adapter {

    /**
     * Retrieve and normalize shipment records for an order.
     *
     * AST Pro's public API is preferred. WooCommerce shipment metadata is used
     * as a safe fallback when AST Pro is unavailable or returns no records.
     *
     * @param mixed $order WooCommerce order object.
     * @return array[] Allowlisted shipment records.
     */
    public static function get_shipments($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return array();
        }

        $raw_items = self::get_ast_items($order);
        if (empty($raw_items) && method_exists($order, 'get_meta')) {
            $raw_items = $order->get_meta('_wc_shipment_tracking_items', true);
        }

        if (!is_array($raw_items)) {
            return array();
        }

        $shipments = array();
        $seen = array();

        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $tracking_number = self::sanitize_text_value(
                self::first_value($item, array('tracking_number', 'tracking_id'))
            );
            if ('' === $tracking_number) {
                continue;
            }

            $carrier = self::sanitize_text_value(
                self::first_value(
                    $item,
                    array('formatted_tracking_provider', 'tracking_provider', 'custom_tracking_provider', 'carrier')
                )
            );
            $tracking_url = self::validate_tracking_url(
                self::first_value(
                    $item,
                    array('ast_tracking_link', 'formatted_tracking_link', 'custom_tracking_link', 'tracking_link', 'tracking_url')
                )
            );
            $shipped_date = self::normalize_date(
                self::first_value($item, array('date_shipped', 'shipped_date'))
            );

            $duplicate_key = strtolower($carrier) . '|' . strtolower($tracking_number) . '|' . $tracking_url;
            if (isset($seen[$duplicate_key])) {
                continue;
            }
            $seen[$duplicate_key] = true;

            $shipments[] = array(
                'carrier'        => $carrier,
                'tracking_number' => $tracking_number,
                'tracking_url'    => $tracking_url,
                'shipped_date'    => $shipped_date,
            );
        }

        return $shipments;
    }

    /**
     * Validate an external tracking URL.
     *
     * Only absolute HTTPS URLs with a hostname are accepted. Credentials in
     * URLs are rejected to avoid misleading or ambiguous external links.
     *
     * @param mixed $url Proposed tracking URL.
     * @return string Validated HTTPS URL, or an empty string.
     */
    public static function validate_tracking_url($url) {
        if (!is_string($url)) {
            return '';
        }

        $url = trim(wp_unslash($url));
        if ('' === $url) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (
            !is_array($parts)
            || empty($parts['scheme'])
            || 'https' !== strtolower($parts['scheme'])
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '';
        }

        $validated = esc_url_raw($url, array('https'));
        return is_string($validated) ? $validated : '';
    }

    /**
     * Ask AST Pro for its formatted tracking records.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    private static function get_ast_items($order) {
        if (!class_exists('AST_Pro_Actions') || !method_exists('AST_Pro_Actions', 'get_instance')) {
            return array();
        }

        try {
            $ast = AST_Pro_Actions::get_instance();
            if (!$ast || !method_exists($ast, 'get_tracking_items')) {
                return array();
            }

            $items = $ast->get_tracking_items($order->get_id(), true);
            return is_array($items) ? $items : array();
        } catch (Throwable $exception) {
            return array();
        }
    }

    /**
     * Return the first usable scalar value from a record.
     *
     * @param array $item Tracking record.
     * @param array $keys Candidate keys.
     * @return string
     */
    private static function first_value($item, $keys) {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && '' !== trim((string) $item[$key])) {
                return (string) $item[$key];
            }
        }

        return '';
    }

    /**
     * Sanitize plain shipment text.
     *
     * @param mixed $value Proposed text.
     * @return string
     */
    private static function sanitize_text_value($value) {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field(wp_strip_all_tags($value));
    }

    /**
     * Normalize a shipment date using the site's public date format.
     *
     * @param mixed $value Proposed timestamp or date string.
     * @return string
     */
    private static function normalize_date($value) {
        if (!is_scalar($value) || '' === trim((string) $value)) {
            return '';
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($timestamp <= 0) {
            return '';
        }

        return sanitize_text_field(date_i18n(get_option('date_format'), $timestamp));
    }
}

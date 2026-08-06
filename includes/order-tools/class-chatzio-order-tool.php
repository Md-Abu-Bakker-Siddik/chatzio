<?php
/**
 * Main server-side service for the get_order_status tool.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Tool {

    /**
     * Validate, authorize, map, and enrich one order lookup.
     *
     * Tool arguments are untrusted. Unknown properties are rejected, and the
     * response contains only JSON-serializable allowlisted scalar data.
     *
     * @param mixed $arguments Proposed tool arguments.
     * @return array Strict success or failure payload.
     */
    public static function execute($arguments) {
        if (!is_array($arguments)) {
            return self::failure('temporarily_unavailable');
        }

        $logged_in = is_user_logged_in() && get_current_user_id() > 0;
        $allowed_keys = $logged_in
            ? array('order_number')
            : array('order_number', 'billing_email');

        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !in_array($key, $allowed_keys, true)) {
                return self::failure('temporarily_unavailable');
            }
        }

        if (
            !array_key_exists('order_number', $arguments)
            || (!is_string($arguments['order_number']) && !is_int($arguments['order_number']))
            || '' === trim((string) $arguments['order_number'])
        ) {
            return self::failure('missing_order_number');
        }

        $order_number = Chatzio_Input_Validator::validate_order_number($arguments['order_number']);
        if (false === $order_number) {
            return self::failure('missing_order_number');
        }

        $billing_email = '';
        if (!$logged_in) {
            if (
                !array_key_exists('billing_email', $arguments)
                || !is_string($arguments['billing_email'])
                || '' === trim($arguments['billing_email'])
            ) {
                return self::failure('missing_billing_email');
            }

            $billing_email = Chatzio_Input_Validator::sanitize_email($arguments['billing_email']);
            if (false === $billing_email) {
                return self::failure('invalid_email');
            }
        }

        if (!function_exists('wc_get_order') || !class_exists('WC_Order')) {
            return self::failure('temporarily_unavailable');
        }

        try {
            $order = $logged_in
                ? Chatzio_Order_Authorization::verify_logged_in($order_number)
                : Chatzio_Order_Authorization::verify_guest($order_number, $billing_email);

            if (false === $order) {
                return self::failure('verification_failed');
            }

            $order_result = Chatzio_Order_Result::from_order($order);
            if (!is_array($order_result)) {
                return self::failure('temporarily_unavailable');
            }

            $shipments = Chatzio_AST_Adapter::get_shipments($order);
            $support_recommended = in_array(
                $order_result['status_code'],
                array('cancelled', 'refunded', 'failed'),
                true
            );

            return array(
                'ok'                  => true,
                'result_type'         => 'order_status',
                'order'               => $order_result,
                'shipments'           => $shipments,
                'support_recommended' => $support_recommended,
            );
        } catch (Throwable $exception) {
            return self::failure('temporarily_unavailable');
        }
    }

    /**
     * Backward-compatible descriptive entry point.
     *
     * @param mixed $arguments Proposed tool arguments.
     * @return array
     */
    public static function get_order_status($arguments) {
        return self::execute($arguments);
    }

    /**
     * Build a strict public failure payload from an approved error code.
     *
     * @param string $error_code Approved internal error code.
     * @return array
     */
    private static function failure($error_code) {
        $messages = array(
            'missing_order_number'   => 'Please provide the numeric order number shown in your confirmation email.',
            'missing_billing_email'  => 'Please provide the billing email used for the order.',
            'invalid_email'          => 'That email address does not appear to be valid. Please enter the billing email used for the order.',
            'verification_failed'    => 'We couldn\'t verify an order with those details. Please check the order number and billing email and try again.',
            'temporarily_unavailable' => 'I\'m temporarily unable to check your order. Please try again shortly or contact support.',
        );

        if (!isset($messages[$error_code])) {
            $error_code = 'temporarily_unavailable';
        }

        return array(
            'ok'             => false,
            'error_code'     => $error_code,
            'public_message' => $messages[$error_code],
        );
    }
}

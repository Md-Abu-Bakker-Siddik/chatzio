<?php
/**
 * Strict guest-facing verification tool adapter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Verification_Tool {

    /**
     * Verify a guest order using the public tool argument names.
     *
     * @param mixed $arguments Expected: order_id and email only.
     * @return array Strict JSON-serializable result.
     */
    public static function execute($arguments) {
        if (!is_array($arguments)) {
            return self::failure('temporarily_unavailable');
        }

        $allowed_keys = array('order_id', 'email');
        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !in_array($key, $allowed_keys, true)) {
                return self::failure('temporarily_unavailable');
            }
        }

        if (
            !array_key_exists('order_id', $arguments)
            || (!is_string($arguments['order_id']) && !is_int($arguments['order_id']))
            || '' === trim((string) $arguments['order_id'])
        ) {
            return self::failure('missing_order_number');
        }

        if (
            !array_key_exists('email', $arguments)
            || !is_string($arguments['email'])
            || '' === trim($arguments['email'])
        ) {
            return self::failure('missing_billing_email');
        }

        $order_id = Chatzio_Input_Validator::validate_order_number($arguments['order_id']);
        if (false === $order_id) {
            return self::failure('missing_order_number');
        }

        $email = Chatzio_Input_Validator::sanitize_email($arguments['email']);
        if (false === $email) {
            return self::failure('invalid_email');
        }

        if (!function_exists('wc_get_order') || !class_exists('WC_Order')) {
            return self::failure('temporarily_unavailable');
        }

        try {
            $order = Chatzio_Order_Authorization::verify_guest($order_id, $email);
            if (false === $order) {
                return self::failure('verification_failed');
            }

            $order_result = Chatzio_Order_Result::from_order($order);
            if (!is_array($order_result)) {
                return self::failure('temporarily_unavailable');
            }

            return array(
                'ok'                  => true,
                'result_type'         => 'order_status',
                'order'               => $order_result,
                'shipments'           => Chatzio_AST_Adapter::get_shipments($order),
                'support_recommended' => in_array(
                    $order_result['status_code'],
                    array('cancelled', 'refunded', 'failed'),
                    true
                ),
            );
        } catch (Throwable $exception) {
            return self::failure('temporarily_unavailable');
        }
    }

    /**
     * Compatibility alias for explicit verification callers.
     *
     * @param mixed $arguments Tool arguments.
     * @return array
     */
    public static function verify($arguments) {
        return self::execute($arguments);
    }

    /**
     * Build a public failure without exposing whether an order exists.
     *
     * @param string $error_code Approved error code.
     * @return array
     */
    private static function failure($error_code) {
        $messages = array(
            'missing_order_number'    => 'Please provide the numeric order number shown in your confirmation email.',
            'missing_billing_email'   => 'Please provide the billing email used for the order.',
            'invalid_email'           => 'That email address does not appear to be valid. Please enter the billing email used for the order.',
            'verification_failed'     => 'We couldn\'t verify an order with those details. Please check the order number and billing email and try again.',
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

<?php
/**
 * OpenRouter native-tool orchestration for secure order lookups.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_AI_Tool_Orchestrator {

    const ORDER_TOOL_NAME = 'get_order_status';

    /**
     * Return the exact tool definition for the current authorization mode.
     *
     * Guest calls require an order number and billing email. Authenticated
     * calls accept only an order number; ownership is checked server-side.
     *
     * @param bool|null $logged_in Override for tests; defaults to WP auth state.
     * @return array
     */
    public static function get_order_status_tool($logged_in = null) {
        if (null === $logged_in) {
            $logged_in = is_user_logged_in() && get_current_user_id() > 0;
        }

        $properties = array(
            'order_number' => array(
                'type'        => 'string',
                'description' => 'The numeric WooCommerce order number from the customer confirmation.',
                'pattern'     => '^[0-9]+$',
            ),
        );
        $required = array('order_number');

        if (!$logged_in) {
            $properties['billing_email'] = array(
                'type'        => 'string',
                'description' => 'The exact billing email used when the guest order was placed.',
                'format'      => 'email',
            );
            $required[] = 'billing_email';
        }

        return array(
            'type'     => 'function',
            'function' => array(
                'name'        => self::ORDER_TOOL_NAME,
                'description' => $logged_in
                    ? 'Retrieve status and tracking for one order owned by the authenticated customer.'
                    : 'Verify a guest order using its order number and billing email, then retrieve status and tracking.',
                'strict'      => true,
                'parameters'  => array(
                    'type'                 => 'object',
                    'properties'           => $properties,
                    'required'             => $required,
                    'additionalProperties' => false,
                ),
            ),
        );
    }

    /**
     * Send a message with native tools and handle any approved tool request.
     *
     * @param Chatzio_OpenRouter $openrouter  Configured provider client.
     * @param string             $message     Current customer message.
     * @param array              $context     Existing knowledge context.
     * @param array              $history     Sanitized conversation history.
     * @param string             $session_id  Chatzio session ID.
     * @return array Existing OpenRouter result shape plus order_tool_used when applicable.
     */
    public function process_message($openrouter, $message, $context, $history, $session_id) {
        if (!$openrouter instanceof Chatzio_OpenRouter) {
            return self::provider_failure('The AI service is temporarily unavailable. Please try again.');
        }

        $tools = array(self::get_order_status_tool());
        $ai_result = $openrouter->send_message($message, $context, $history, $tools);

        // Some configured OpenRouter models do not support native tools. Keep
        // normal chat available, while the deterministic order gate remains
        // responsible for secure order requests.
        if (empty($ai_result['success']) && self::is_tool_unsupported_error($ai_result)) {
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_warning('Configured model does not support native tools; using normal chat fallback');
            }
            return $openrouter->send_message($message, $context, $history);
        }

        if (empty($ai_result['success']) || empty($ai_result['tool_calls'])) {
            return $ai_result;
        }

        // Only one order lookup is allowed per customer message.
        if (1 !== count($ai_result['tool_calls'])) {
            return self::tool_response(
                self::temporary_message(),
                isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
                isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null
            );
        }

        $parsed = self::parse_tool_call($ai_result['tool_calls'][0]);
        if (empty($parsed['ok'])) {
            self::log_validation_failure(isset($parsed['error_code']) ? $parsed['error_code'] : 'invalid_tool_call');
            return self::tool_response(
                self::temporary_message(),
                isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
                isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null
            );
        }

        $validated = self::validate_arguments($parsed['arguments']);
        if (empty($validated['ok'])) {
            self::log_validation_failure(isset($validated['error_code']) ? $validated['error_code'] : 'invalid_arguments');
            $message_text = isset($validated['public_message'])
                ? $validated['public_message']
                : self::temporary_message();
            return self::tool_response(
                $message_text,
                isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
                isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null
            );
        }

        // The model may request the tool, but only this server-side service can
        // execute it and decide authorization.
        $tool_result = Chatzio_Order_Tool::execute($validated['arguments']);
        if (empty($tool_result['ok'])) {
            $public_message = isset($tool_result['public_message'])
                ? (string) $tool_result['public_message']
                : self::temporary_message();
            return self::tool_response(
                $public_message,
                isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
                isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null
            );
        }

        if (!Chatzio_Order_Conversation_State::set_verified_order($session_id, $tool_result)) {
            self::log_validation_failure('context_store_failed');
        }

        $html = Chatzio_Order_Response_Renderer::render_html(
            $tool_result['order'],
            $tool_result['shipments']
        );
        if ('' === $html) {
            return self::tool_response(
                self::temporary_message(),
                isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
                isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null
            );
        }

        return array(
            'success'         => true,
            'response'        => $html,
            'raw_response'    => self::plain_text_result($tool_result),
            'model_used'      => isset($ai_result['model_used']) ? $ai_result['model_used'] : 'order-tool',
            'tokens_used'     => isset($ai_result['tokens_used']) ? $ai_result['tokens_used'] : null,
            'order_tool_used' => true,
            'order_tool_verified' => true,
        );
    }

    /**
     * Parse one native provider tool call without executing it.
     *
     * Expected provider shape:
     * {id, type:"function", function:{name, arguments:"{...}"}}
     *
     * @param mixed $tool_call Proposed provider tool call.
     * @return array Parsed result.
     */
    public static function parse_tool_call($tool_call) {
        if (
            !is_array($tool_call)
            || !isset($tool_call['type'], $tool_call['function'])
            || 'function' !== $tool_call['type']
            || !is_array($tool_call['function'])
            || !isset($tool_call['function']['name'], $tool_call['function']['arguments'])
            || self::ORDER_TOOL_NAME !== $tool_call['function']['name']
        ) {
            return array('ok' => false, 'error_code' => 'unknown_tool');
        }

        $raw_arguments = $tool_call['function']['arguments'];
        if (is_string($raw_arguments)) {
            $arguments = json_decode($raw_arguments, true, 32);
            if (JSON_ERROR_NONE !== json_last_error() || !is_array($arguments)) {
                return array('ok' => false, 'error_code' => 'malformed_arguments');
            }
        } elseif (is_array($raw_arguments)) {
            $arguments = $raw_arguments;
        } else {
            return array('ok' => false, 'error_code' => 'malformed_arguments');
        }

        return array(
            'ok'        => true,
            'call_id'   => isset($tool_call['id']) && is_string($tool_call['id'])
                ? sanitize_text_field($tool_call['id'])
                : '',
            'name'      => self::ORDER_TOOL_NAME,
            'arguments' => $arguments,
        );
    }

    /**
     * Validate arguments independently of the model and normalize them.
     *
     * @param mixed     $arguments Proposed arguments.
     * @param bool|null $logged_in Override for tests; defaults to WP auth state.
     * @return array Validation result.
     */
    public static function validate_arguments($arguments, $logged_in = null) {
        if (!is_array($arguments)) {
            return self::invalid_arguments('malformed_arguments', self::temporary_message());
        }

        if (null === $logged_in) {
            $logged_in = is_user_logged_in() && get_current_user_id() > 0;
        }

        $allowed_keys = $logged_in
            ? array('order_number')
            : array('order_number', 'billing_email');

        foreach (array_keys($arguments) as $key) {
            if (!is_string($key) || !in_array($key, $allowed_keys, true)) {
                return self::invalid_arguments('additional_property', self::temporary_message());
            }
        }

        if (!array_key_exists('order_number', $arguments)) {
            return self::invalid_arguments(
                'missing_order_number',
                'Please provide the numeric order number shown in your confirmation email.'
            );
        }
        if (!is_string($arguments['order_number']) || !preg_match('/\A[0-9]+\z/D', $arguments['order_number'])) {
            return self::invalid_arguments(
                'invalid_order_number',
                'Please provide the numeric order number shown in your confirmation email.'
            );
        }

        $order_number = Chatzio_Input_Validator::sanitize_order_number($arguments['order_number']);
        if (false === $order_number) {
            return self::invalid_arguments(
                'invalid_order_number',
                'Please provide the numeric order number shown in your confirmation email.'
            );
        }

        $normalized = array('order_number' => $order_number);

        if (!$logged_in) {
            if (!array_key_exists('billing_email', $arguments)) {
                return self::invalid_arguments(
                    'missing_billing_email',
                    'Please provide the billing email used for the order.'
                );
            }
            if (!is_string($arguments['billing_email'])) {
                return self::invalid_arguments(
                    'invalid_email',
                    'That email address does not appear to be valid. Please enter the billing email used for the order.'
                );
            }

            $billing_email = Chatzio_Input_Validator::sanitize_email($arguments['billing_email']);
            if (false === $billing_email) {
                return self::invalid_arguments(
                    'invalid_email',
                    'That email address does not appear to be valid. Please enter the billing email used for the order.'
                );
            }
            $normalized['billing_email'] = $billing_email;
        }

        return array('ok' => true, 'arguments' => $normalized);
    }

    /**
     * Build an internal argument-validation failure.
     *
     * @param string $error_code     Internal validation code.
     * @param string $public_message Safe public response.
     * @return array
     */
    private static function invalid_arguments($error_code, $public_message) {
        return array(
            'ok'             => false,
            'error_code'     => $error_code,
            'public_message' => $public_message,
        );
    }

    /**
     * Convert a public tool failure to the existing provider result shape.
     *
     * @param string $message     Safe public message.
     * @param string $model       Provider model identifier.
     * @param mixed  $tokens_used Provider usage data.
     * @return array
     */
    private static function tool_response($message, $model, $tokens_used) {
        return array(
            'success'         => true,
            'response'        => '<p>' . esc_html($message) . '</p>',
            'raw_response'    => $message,
            'model_used'      => $model,
            'tokens_used'     => $tokens_used,
            'order_tool_used' => true,
            'order_tool_verified' => false,
        );
    }

    /**
     * Return a provider-style failure before a model request is possible.
     *
     * @param string $message Safe public error.
     * @return array
     */
    private static function provider_failure($message) {
        return array('success' => false, 'error' => $message);
    }

    /**
     * Build a private-data-free transcript value.
     *
     * @param array $result Successful order tool result.
     * @return string
     */
    private static function plain_text_result($result) {
        $order = $result['order'];
        $status_label = function_exists('wc_get_order_status_name')
            ? wc_get_order_status_name($order['status_code'])
            : ucwords(str_replace('-', ' ', $order['status_code']));
        $text = 'Order #' . $order['number'] . ' - Status: ' . $status_label;

        if ('' !== $order['status_message']) {
            $text .= '. ' . $order['status_message'];
        }

        foreach ($result['shipments'] as $index => $shipment) {
            $text .= ' Shipment ' . ((int) $index + 1) . ': ';
            if ('' !== $shipment['carrier']) {
                $text .= $shipment['carrier'] . ' ';
            }
            $text .= $shipment['tracking_number'];
            if ('' !== $shipment['tracking_url']) {
                $text .= ' ' . $shipment['tracking_url'];
            }
        }

        return $text;
    }

    /**
     * Return the generic temporary order message.
     *
     * @return string
     */
    private static function temporary_message() {
        return 'I\'m temporarily unable to check your order. Please try again shortly or contact support.';
    }

    /**
     * Detect a provider error that specifically indicates missing tool support.
     *
     * @param mixed $result Provider failure result.
     * @return bool
     */
    private static function is_tool_unsupported_error($result) {
        if (!is_array($result)) {
            return false;
        }

        $error = isset($result['error']) ? strtolower((string) $result['error']) : '';
        $error_code = isset($result['debug']['error_code'])
            ? strtolower((string) $result['debug']['error_code'])
            : '';
        $combined = $error . ' ' . $error_code;

        return false !== strpos($combined, 'tool')
            || false !== strpos($combined, 'function call')
            || false !== strpos($combined, 'unsupported parameter');
    }

    /**
     * Log only a validation category, never raw arguments or emails.
     *
     * @param string $error_code Internal category.
     * @return void
     */
    private static function log_validation_failure($error_code) {
        if (class_exists('Chatzio_Logger')) {
            Chatzio_Logger::log_warning('AI order tool validation rejected', array(
                'error_code' => sanitize_key($error_code),
            ));
        }
    }
}

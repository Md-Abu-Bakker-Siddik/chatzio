<?php
/**
 * Secure conversational order-status orchestration.
 *
 * This handler runs before the general LLM path. It collects only the missing
 * verification fields, routes guest lookups through the verification tool,
 * and renders order data only after server-side authorization succeeds.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Tracking {

    const PENDING_TTL  = 600;
    const MAX_ATTEMPTS = 3;
    const LOCKOUT_TTL  = 900;
    const LOOKUP_LIMIT = 5;
    const LOOKUP_WINDOW = 600;

    /**
     * Handle an order message or return false for the normal AI flow.
     *
     * @param string $message    Sanitized customer message.
     * @param string $session_id Chat session identifier.
     * @return array|false HTML/raw response, or false when not handled.
     */
    public static function maybe_handle($message, $session_id) {
        if (!self::services_available()) {
            return false;
        }

        $credentials = self::parse_credentials($message);
        $pending = self::get_pending_state($session_id);
        $verified = Chatzio_Order_Conversation_State::get_verified_order($session_id);

        if (self::is_cancel($message) && (is_array($pending) || is_array($verified))) {
            self::clear_pending_state($session_id);
            Chatzio_Order_Conversation_State::clear($session_id);
            return self::message_response('Order lookup cancelled. Is there anything else I can help you with?');
        }

        // Verified context may answer order follow-ups for 600 seconds. A new
        // order number clears it and starts a fresh authorization boundary.
        if (is_array($verified)) {
            $verified_number = isset($verified['order']['number']) ? (string) $verified['order']['number'] : '';
            $requested_number = $credentials['order_id'];

            if ('' !== $requested_number && $requested_number !== $verified_number) {
                Chatzio_Order_Conversation_State::clear($session_id);
                $verified = false;
            } elseif (self::detect_intent($message) || self::is_order_follow_up($message)) {
                return self::render_verified_context($verified);
            }
        }

        $has_complete_guest_credentials = '' !== $credentials['order_id'] && '' !== $credentials['email'];
        if (!is_array($pending) && !self::detect_intent($message) && !$has_complete_guest_credentials) {
            return false;
        }

        if (self::is_locked_out()) {
            self::clear_pending_state($session_id);
            return self::locked_out_response();
        }

        $pending = is_array($pending)
            ? $pending
            : array('order_id' => '', 'email' => '', 'attempts' => 0);

        if ('' !== $credentials['order_id']) {
            $pending['order_id'] = $credentials['order_id'];
        }
        if ('' !== $credentials['email']) {
            $pending['email'] = $credentials['email'];
        }

        $logged_in = is_user_logged_in() && get_current_user_id() > 0;

        if ('' === $pending['order_id']) {
            self::save_pending_state($session_id, $pending);
            return self::ask_for_missing_fields($pending, $logged_in);
        }

        if (!$logged_in && '' === $pending['email']) {
            self::save_pending_state($session_id, $pending);
            if ($credentials['email_candidate']) {
                return self::message_response('That email address does not appear to be valid. Please enter the billing email used for the order.');
            }
            return self::ask_for_missing_fields($pending, false);
        }

        return self::verify_and_render($pending, $session_id, $logged_in);
    }

    /**
     * Confirm that the secure order service stack is ready.
     *
     * @return bool
     */
    private static function services_available() {
        return function_exists('wc_get_order')
            && class_exists('WC_Order')
            && class_exists('Chatzio_Order_Tool')
            && class_exists('Chatzio_Order_Verification_Tool')
            && class_exists('Chatzio_Order_Rate_Limiter')
            && class_exists('Chatzio_Order_Conversation_State')
            && class_exists('Chatzio_Order_Response_Renderer');
    }

    /**
     * Execute the correct authorization tool and render only successful data.
     *
     * @param array  $pending    Collected verification fields.
     * @param string $session_id Chat session identifier.
     * @param bool   $logged_in  Whether account ownership should be used.
     * @return array
     */
    private static function verify_and_render($pending, $session_id, $logged_in) {
        if ($logged_in) {
            $result = Chatzio_Order_Tool::execute(
                array('order_number' => $pending['order_id'])
            );
        } else {
            $result = Chatzio_Order_Verification_Tool::execute(
                array(
                    'order_id' => $pending['order_id'],
                    'email'    => $pending['email'],
                )
            );
        }

        if (isset($result['ok']) && true === $result['ok']) {
            self::clear_pending_state($session_id);

            if (!Chatzio_Order_Conversation_State::set_verified_order($session_id, $result)) {
                self::log_warning('Verified order context could not be stored', array(
                    'order_no' => $pending['order_id'],
                ));
            }

            self::log_info('Order lookup success', array(
                'order_no' => $pending['order_id'],
            ));

            return self::render_tool_result($result);
        }

        $error_code = isset($result['error_code']) ? (string) $result['error_code'] : 'temporarily_unavailable';
        if ('verification_failed' !== $error_code) {
            if ('locked_out' === $error_code) {
                self::clear_pending_state($session_id);
            }
            $public_message = isset($result['public_message'])
                ? (string) $result['public_message']
                : 'I\'m temporarily unable to check your order. Please try again shortly or contact support.';
            return self::message_response($public_message);
        }

        $attempts = isset($pending['attempts']) ? (int) $pending['attempts'] + 1 : 1;
        self::log_warning('Order lookup failed', array(
            'order_no' => $pending['order_id'],
            'email'    => self::mask_email($pending['email']),
            'attempt'  => $attempts,
        ));

        if ($attempts >= self::MAX_ATTEMPTS) {
            self::clear_pending_state($session_id);
            self::set_lockout();
            return self::locked_out_response();
        }

        // Keep the order number so an email-only retry is recognized, but do
        // not retain a failed email longer than the single verification call.
        $pending['email'] = '';
        $pending['attempts'] = $attempts;
        self::save_pending_state($session_id, $pending);

        return self::message_response(
            'We couldn\'t verify an order with those details. Please check the order number and billing email and try again.'
        );
    }

    /**
     * Render a successful strict tool result.
     *
     * @param array $result Successful tool result.
     * @return array
     */
    private static function render_tool_result($result) {
        $html = Chatzio_Order_Response_Renderer::render_html($result['order'], $result['shipments']);
        if ('' === $html) {
            return self::message_response('I\'m temporarily unable to display your order. Please try again shortly.');
        }

        return array(
            'html' => $html,
            'raw'  => self::plain_text_result($result['order'], $result['shipments']),
        );
    }

    /**
     * Rebuild a strict tool result from verified transient context.
     *
     * @param array $context Verified conversation context.
     * @return array
     */
    private static function render_verified_context($context) {
        return self::render_tool_result(
            array(
                'ok'                  => true,
                'result_type'         => 'order_status',
                'order'               => $context['order'],
                'shipments'           => $context['shipments'],
                'support_recommended' => $context['support_recommended'],
            )
        );
    }

    /**
     * Build a private-data-free transcript representation.
     *
     * @param array $order     Public order result.
     * @param array $shipments Public shipment records.
     * @return string
     */
    private static function plain_text_result($order, $shipments) {
        $status_label = function_exists('wc_get_order_status_name')
            ? wc_get_order_status_name($order['status_code'])
            : ucwords(str_replace('-', ' ', $order['status_code']));
        $text = 'Order #' . $order['number'] . ' - Status: ' . $status_label;

        if ('' !== $order['status_message']) {
            $text .= '. ' . $order['status_message'];
        }

        foreach ($shipments as $index => $shipment) {
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
     * Extract an order number and email independently from natural language.
     *
     * @param string $message Customer message.
     * @return array
     */
    private static function parse_credentials($message) {
        $email = '';
        $email_candidate = false !== strpos($message, '@');

        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $message, $match)) {
            $normalized = Chatzio_Input_Validator::sanitize_email($match[0]);
            if (false !== $normalized) {
                $email = $normalized;
            }
            $message = str_replace($match[0], ' ', $message);
        }

        $order_id = '';
        if (preg_match('/#?\b([0-9]{1,19})\b/', $message, $match)) {
            $normalized = Chatzio_Input_Validator::sanitize_order_number($match[1]);
            if (false !== $normalized) {
                $order_id = $normalized;
            }
        }

        return array(
            'order_id'        => $order_id,
            'email'           => $email,
            'email_candidate' => $email_candidate,
        );
    }

    /**
     * Detect a customer-specific order or shipment request.
     *
     * @param string $message Customer message.
     * @return bool
     */
    private static function detect_intent($message) {
        $patterns = array(
            '/^\s*(?:my\s+)?order\s*[?!.]*\s*$/i',
            '/\btrack(?:ing)?\b.{0,50}\b(order|package|parcel|shipment|number|info)\b/i',
            '/\b(order|package|parcel|shipment)\b.{0,50}\b(track|tracking|status|update|shipped|arrive|delayed|delivery)\b/i',
            '/\bwhere(?:\'s| is)?\s+(my|the)\s+(order|package|parcel|shipment|delivery)\b/i',
            '/\bstatus\s+of\s+(my|the|an)?\s*order\b/i',
            '/\b(check|find|look\s*up)\b.{0,30}\b(my\s+)?order\b/i',
            '/\bwhen\b.{0,30}\b(order|package|parcel|shipment)\b.{0,30}\b(arrive|delivery|come)\b/i',
            '/\bwismo\b/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect a follow-up that may use already verified context.
     *
     * @param string $message Customer message.
     * @return bool
     */
    private static function is_order_follow_up($message) {
        return (bool) preg_match(
            '/\b(tracking\s*link|track\s*it|carrier|shipped|shipment|delivery|arrive|status|where\s+is\s+it)\b/i',
            $message
        );
    }

    /**
     * Detect explicit cancellation of the lookup conversation.
     *
     * @param string $message Customer message.
     * @return bool
     */
    private static function is_cancel($message) {
        return (bool) preg_match('/\b(cancel|never\s*mind|nevermind|forget\s+it|no\s+thanks|stop)\b/i', $message);
    }

    /**
     * Ask only for fields still required by the authorization mode.
     *
     * @param array $pending   Collected fields.
     * @param bool  $logged_in Whether account ownership is available.
     * @return array
     */
    private static function ask_for_missing_fields($pending, $logged_in) {
        if ('' === $pending['order_id']) {
            if (!$logged_in && '' !== $pending['email']) {
                return self::message_response('Thanks. What is your order number?');
            }

            return $logged_in
                ? self::message_response('Please provide the order number you want me to check.')
                : self::message_response('Please provide your order number and the billing email used for the order so I can verify it.');
        }

        return self::message_response(
            'Please provide the billing email used for order #' . $pending['order_id'] . ' so I can verify it.'
        );
    }

    /**
     * Create a safely escaped conversational message.
     *
     * @param string $message Plain public message.
     * @return array
     */
    private static function message_response($message) {
        return array(
            'html' => '<p>' . esc_html($message) . '</p>',
            'raw'  => $message,
        );
    }

    /**
     * Return the public lockout response.
     *
     * @return array
     */
    private static function locked_out_response() {
        return self::message_response(
            'Too many unsuccessful attempts were made. Please wait 15 minutes before trying again or contact support for assistance.'
        );
    }

    /**
     * Load pending, unverified field collection state.
     *
     * @param string $session_id Chat session identifier.
     * @return array|false
     */
    private static function get_pending_state($session_id) {
        $state = get_transient(self::pending_state_key($session_id));
        if (!is_array($state)) {
            return false;
        }

        $required = array('order_id', 'email', 'attempts');
        if ($required !== array_keys($state)) {
            self::clear_pending_state($session_id);
            return false;
        }

        return $state;
    }

    /**
     * Save pending fields for the next conversational turn.
     *
     * @param string $session_id Chat session identifier.
     * @param array  $state      Pending state.
     * @return void
     */
    private static function save_pending_state($session_id, $state) {
        set_transient(
            self::pending_state_key($session_id),
            array(
                'order_id' => (string) $state['order_id'],
                'email'    => (string) $state['email'],
                'attempts' => (int) $state['attempts'],
            ),
            self::PENDING_TTL
        );
    }

    /**
     * Clear unverified field collection state.
     *
     * @param string $session_id Chat session identifier.
     * @return void
     */
    private static function clear_pending_state($session_id) {
        delete_transient(self::pending_state_key($session_id));
    }

    /**
     * Build a privacy-conscious pending-state transient key.
     *
     * @param string $session_id Chat session identifier.
     * @return string
     */
    private static function pending_state_key($session_id) {
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $identity = (string) $session_id . '|' . self::client_ip();
        return 'chatzio_order_pending_' . hash_hmac('sha256', $blog_id . '|' . $identity, wp_salt('auth'));
    }

    /**
     * Consume a dedicated order-lookup rate-limit slot.
     *
     * @return bool
     */
    private static function consume_lookup_slot() {
        if (!class_exists('Chatzio_Order_Rate_Limiter')) {
            return true;
        }

        return !is_wp_error(Chatzio_Order_Rate_Limiter::check_and_consume());
    }

    /**
     * Check the temporary verification lockout.
     *
     * @return bool
     */
    private static function is_locked_out() {
        return class_exists('Chatzio_Order_Rate_Limiter')
            && Chatzio_Order_Rate_Limiter::is_locked_out();
    }

    /**
     * Set the temporary verification lockout.
     *
     * @return void
     */
    private static function set_lockout() {
        if (class_exists('Chatzio_Order_Rate_Limiter')) {
            Chatzio_Order_Rate_Limiter::force_lock('verification_failures');
        }
    }

    /**
     * Return the direct client IP without trusting forwarding headers.
     *
     * @return string
     */
    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        return $ip ? $ip : '0.0.0.0';
    }

    /**
     * Mask an email for exceptional audit events.
     *
     * @param string $email Normalized email.
     * @return string
     */
    private static function mask_email($email) {
        if (class_exists('Chatzio_Order_Audit_Logger')) {
            return Chatzio_Order_Audit_Logger::mask_email($email);
        }

        $at = strpos($email, '@');
        if (false === $at || $at < 1) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Add privacy-conscious audit context.
     *
     * @param array $context Existing context.
     * @return array
     */
    private static function audit_context($context = array()) {
        $context['ip_hash'] = hash_hmac('sha256', self::client_ip(), wp_salt('auth'));
        return $context;
    }

    /**
     * Write an informational audit event when logging is available.
     *
     * @param string $message Event message.
     * @param array  $context Event context.
     * @return void
     */
    private static function log_info($message, $context = array()) {
        if (class_exists('Chatzio_Order_Audit_Logger')) {
            Chatzio_Order_Audit_Logger::log_info($message, self::audit_context($context));
            return;
        }

        if (class_exists('Chatzio_Logger')) {
            Chatzio_Logger::log_info($message, self::audit_context($context));
        }
    }

    /**
     * Write a warning audit event when logging is available.
     *
     * @param string $message Event message.
     * @param array  $context Event context.
     * @return void
     */
    private static function log_warning($message, $context = array()) {
        if (class_exists('Chatzio_Order_Audit_Logger')) {
            Chatzio_Order_Audit_Logger::log_warning($message, self::audit_context($context));
            return;
        }

        if (class_exists('Chatzio_Logger')) {
            Chatzio_Logger::log_warning($message, self::audit_context($context));
        }
    }
}

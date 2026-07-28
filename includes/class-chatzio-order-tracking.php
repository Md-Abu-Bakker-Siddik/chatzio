<?php
/**
 * Order Status & Tracking Lookup — 100% server-side.
 *
 * Intercepts order-status questions BEFORE the LLM: order data is never sent
 * to OpenRouter. Replies are built from templates with values escaped.
 *
 * Guest flow (conversational state machine, transient-backed):
 *   1. Intent detected  -> ask for order number + billing email
 *   2. Details received -> verify BOTH against WooCommerce server-side
 *   3. Match            -> order status + AST Pro tracking (number, carrier, link)
 *
 * Logged-in users skip verification and get their own recent orders directly.
 *
 * Anti-enumeration: identical failure message whether the order doesn't exist
 * or the email doesn't match; 3 failed attempts -> 15-minute lockout per IP;
 * max 5 verification attempts per 10 minutes per IP. All of this sits behind
 * Chatzio_Rate_Limiter, which has already vetted the request.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Order_Tracking {

    const STATE_TTL     = 600;  // 10 min: how long we wait for order details
    const MAX_ATTEMPTS  = 3;    // failed verifications before lockout
    const LOCKOUT_TTL   = 900;  // 15 min lockout
    const LOOKUP_LIMIT  = 5;    // verification attempts per window per IP
    const LOOKUP_WINDOW = 600;  // 10 min window

    /**
     * Entry point, called from Chatzio_AJAX::handle_send_message() after rate
     * limiting. Returns ['html' => ..., 'raw' => ...] when this class handled
     * the message, or false to let the normal LLM flow continue.
     *
     * @param string $message    Sanitized user message.
     * @param string $session_id Chat session ID.
     * @return array|false
     */
    public static function maybe_handle($message, $session_id) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return false;
        }

        $state = get_transient(self::state_key($session_id));

        // No active flow: only engage when the message looks like an order query.
        if (!is_array($state)) {
            if (!self::detect_intent($message)) {
                return false;
            }

            if (self::is_locked_out()) {
                return self::tpl_locked_out();
            }

            // Logged-in customers are already authenticated — show their orders.
            if (is_user_logged_in()) {
                return self::logged_in_orders_response();
            }

            // Guest may have sent order number + email in the same message.
            $creds = self::parse_credentials($message);
            if ($creds['order_no'] && $creds['email']) {
                return self::verify_and_respond($creds['order_no'], $creds['email'], $session_id, 0);
            }

            set_transient(self::state_key($session_id), ['step' => 'awaiting', 'attempts' => 0], self::STATE_TTL);
            return self::tpl_ask_details();
        }

        // Active flow: we're waiting for order number + email.
        if (self::is_locked_out()) {
            delete_transient(self::state_key($session_id));
            return self::tpl_locked_out();
        }

        if (self::is_cancel($message)) {
            delete_transient(self::state_key($session_id));
            return self::tpl_cancelled();
        }

        $creds = self::parse_credentials($message);

        // Message has neither digits nor an email — user changed topic.
        // Drop the flow and let the LLM answer normally.
        if (!preg_match('/\d/', $message) && strpos($message, '@') === false) {
            delete_transient(self::state_key($session_id));
            return false;
        }

        if (!$creds['order_no'] || !$creds['email']) {
            return self::tpl_need_both($creds);
        }

        $attempts = isset($state['attempts']) ? (int) $state['attempts'] : 0;
        return self::verify_and_respond($creds['order_no'], $creds['email'], $session_id, $attempts);
    }

    // =========================================================================
    // Intent & parsing
    // =========================================================================

    private static function detect_intent($message) {
        $patterns = [
            '/\btrack(?:ing)?\b.{0,40}\b(order|package|parcel|shipment|number|info)\b/i',
            '/\b(order|package|parcel|shipment)\b.{0,40}\btrack(?:ing)?\b/i',
            '/\bwhere(?:\'s| is)?\s+(my|the)\s+(order|package|parcel|shipment|delivery)\b/i',
            '/\border\s*(status|update|number)\b/i',
            '/\bstatus\s+of\s+(my|the|an)\s+order\b/i',
            '/\b(has|did|is|when)\b.{0,30}\border\b.{0,30}\b(ship|shipped|shipping|arrive|arriving|delivered|dispatched)\b/i',
            '/\bmy\s+order\b.{0,40}\b(status|shipped|arrive|update|track|coming|delivery)\b/i',
            '/\b(check|find|look\s*up)\b.{0,30}\b(my\s+)?order\b/i',
            '/\bwismo\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        return false;
    }

    private static function is_cancel($message) {
        return (bool) preg_match('/\b(cancel|never\s*mind|nevermind|forget\s+it|no\s+thanks|stop)\b/i', $message);
    }

    /**
     * Pull an email and an order number out of free text.
     * Email is extracted first and removed, so digits in the address
     * (john99@mail.com) are never mistaken for the order number.
     */
    private static function parse_credentials($message) {
        $email = '';
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $message, $m)) {
            $candidate = sanitize_email($m[0]);
            if (is_email($candidate)) {
                $email = strtolower($candidate);
            }
            $message = str_replace($m[0], ' ', $message);
        }

        $order_no = 0;
        if (preg_match('/#?\b(\d{1,10})\b/', $message, $m)) {
            $order_no = absint($m[1]);
        }

        return ['order_no' => $order_no, 'email' => $email];
    }

    // =========================================================================
    // Verification
    // =========================================================================

    private static function verify_and_respond($order_no, $email, $session_id, $attempts) {
        // Dedicated lookup throttle (separate from the chat message limiter).
        if (!self::consume_lookup_slot()) {
            delete_transient(self::state_key($session_id));
            self::set_lockout();
            return self::tpl_locked_out();
        }

        $order = wc_get_order($order_no);

        $matched = false;
        if ($order instanceof WC_Order && 'shop_order' === $order->get_type()) {
            $billing = strtolower(trim($order->get_billing_email()));
            // hash_equals: constant-time compare, no timing side-channel
            $matched = ($billing !== '' && hash_equals($billing, $email));
        }

        if (!$matched) {
            $attempts++;
            Chatzio_Logger::log_warning('Order lookup failed', [
                'order_no' => $order_no,
                'email'    => self::mask_email($email),
                'attempt'  => $attempts,
                'ip'       => self::client_ip(),
            ]);

            if ($attempts >= self::MAX_ATTEMPTS) {
                delete_transient(self::state_key($session_id));
                self::set_lockout();
                return self::tpl_locked_out();
            }

            set_transient(self::state_key($session_id), ['step' => 'awaiting', 'attempts' => $attempts], self::STATE_TTL);
            // Same message for "order not found" and "email mismatch" — no enumeration.
            return self::tpl_no_match($attempts);
        }

        delete_transient(self::state_key($session_id));
        Chatzio_Logger::log_info('Order lookup success', [
            'order_no' => $order_no,
            'ip'       => self::client_ip(),
        ]);

        return self::format_order_response($order);
    }

    // =========================================================================
    // Data collection (WooCommerce + AST Pro)
    // =========================================================================

    /**
     * Tracking items for an order. Prefers AST Pro's formatter (carrier name +
     * tracking URL from its provider DB); falls back to raw order meta.
     *
     * @return array[] Each: ['number' => ..., 'carrier' => ..., 'link' => ..., 'date' => ...]
     */
    private static function get_tracking($order) {
        $items = [];

        if (class_exists('AST_Pro_Actions')) {
            $raw = AST_Pro_Actions::get_instance()->get_tracking_items($order->get_id(), true);
        } else {
            $raw = $order->get_meta('_wc_shipment_tracking_items', true);
        }

        if (!is_array($raw)) {
            return $items;
        }

        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['tracking_number'])) {
                continue;
            }
            $carrier = '';
            foreach (['formatted_tracking_provider', 'tracking_provider', 'custom_tracking_provider'] as $key) {
                if (!empty($item[$key])) {
                    $carrier = $item[$key];
                    break;
                }
            }
            $link = '';
            foreach (['ast_tracking_link', 'formatted_tracking_link', 'custom_tracking_link'] as $key) {
                if (!empty($item[$key])) {
                    $link = $item[$key];
                    break;
                }
            }
            $date = '';
            if (!empty($item['date_shipped'])) {
                $ts = is_numeric($item['date_shipped']) ? (int) $item['date_shipped'] : strtotime($item['date_shipped']);
                if ($ts) {
                    $date = date_i18n(get_option('date_format'), $ts);
                }
            }
            $items[] = [
                'number'  => (string) $item['tracking_number'],
                'carrier' => (string) $carrier,
                'link'    => (string) $link,
                'date'    => $date,
            ];
        }

        return $items;
    }

    private static function status_line($order) {
        $status = $order->get_status();
        $label  = wc_get_order_status_name($status);

        $friendly = [
            'pending'         => 'We\'re waiting for payment confirmation.',
            'processing'      => 'Payment received — your order is being prepared for shipment.',
            'on-hold'         => 'Your order is on hold — usually awaiting payment confirmation.',
            'completed'       => 'Your order has been fulfilled.',
            'shipped'         => 'Your order is on its way!',
            'partial-shipped' => 'Part of your order has shipped; the rest follows shortly.',
            'cancelled'       => 'This order was cancelled.',
            'refunded'        => 'This order was refunded.',
            'failed'          => 'The payment for this order didn\'t go through.',
        ];

        return [
            'label' => $label,
            'note'  => isset($friendly[$status]) ? $friendly[$status] : '',
        ];
    }

    // =========================================================================
    // Responses (templates — the ONLY place output is built)
    // =========================================================================

    private static function format_order_response($order) {
        $status = self::status_line($order);
        $date   = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '';

        $html  = '<p><strong>Order #' . esc_html($order->get_order_number()) . '</strong>';
        $html .= $date ? ' <span style="opacity:.7">(placed ' . esc_html($date) . ')</span></p>' : '</p>';
        $html .= '<p>Status: <strong>' . esc_html($status['label']) . '</strong>';
        $html .= $status['note'] ? '<br>' . esc_html($status['note']) : '';
        $html .= '</p>';

        $raw = 'Order #' . $order->get_order_number() . ' — Status: ' . $status['label'];

        $tracking = self::get_tracking($order);
        if (!empty($tracking)) {
            $html .= '<p><strong>Tracking:</strong></p>';
            foreach ($tracking as $t) {
                $line = esc_html($t['number']);
                if ($t['carrier']) {
                    $line = esc_html($t['carrier']) . ': ' . $line;
                }
                if ($t['date']) {
                    $line .= ' <span style="opacity:.7">(shipped ' . esc_html($t['date']) . ')</span>';
                }
                if ($t['link']) {
                    $html .= '<p>' . $line . '<br><a href="' . esc_url($t['link']) . '" target="_blank" rel="noopener nofollow">Track this shipment →</a></p>';
                } else {
                    $html .= '<p>' . $line . '</p>';
                }
                $raw .= ' | Tracking ' . ($t['carrier'] ? $t['carrier'] . ' ' : '') . $t['number'];
            }
        } else {
            $in_flight = in_array($order->get_status(), ['pending', 'processing', 'on-hold'], true);
            $note = $in_flight
                ? 'Tracking hasn\'t been added yet — you\'ll receive an email with the tracking link as soon as your order ships.'
                : 'No tracking information is attached to this order.';
            $html .= '<p>' . esc_html($note) . '</p>';
            $raw  .= ' | No tracking yet';
        }

        $html .= '<p>Anything else I can help you with?</p>';

        return ['html' => $html, 'raw' => $raw];
    }

    private static function logged_in_orders_response() {
        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'limit'       => 3,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'type'        => 'shop_order',
        ]);

        if (empty($orders)) {
            $html = '<p>I couldn\'t find any orders on your account. If you checked out as a guest, '
                  . 'tell me your <strong>order number</strong> and <strong>billing email</strong> and I\'ll look it up.</p>';
            return ['html' => $html, 'raw' => 'No orders found on account'];
        }

        $html = count($orders) > 1
            ? '<p>Here are your recent orders:</p>'
            : '<p>Here\'s your most recent order:</p>';
        $raw = 'Recent orders:';

        foreach ($orders as $order) {
            $piece = self::format_order_response($order);
            // Strip the per-order sign-off; we add one at the end.
            $piece['html'] = str_replace('<p>Anything else I can help you with?</p>', '', $piece['html']);
            $html .= $piece['html'];
            $raw  .= ' [' . $piece['raw'] . ']';
        }

        $html .= '<p>Anything else I can help you with?</p>';
        return ['html' => $html, 'raw' => $raw];
    }

    private static function tpl_ask_details() {
        $html = '<p>I can check that for you! 📦 Please send me your <strong>order number</strong> and the '
              . '<strong>billing email</strong> used for the order — both in one message is fine.</p>'
              . '<p style="opacity:.7">Example: <em>#1234 name@email.com</em></p>';
        return ['html' => $html, 'raw' => 'Asked for order number + billing email'];
    }

    private static function tpl_need_both($creds) {
        if ($creds['order_no'] && !$creds['email']) {
            $html = '<p>Got the order number — now I just need the <strong>billing email</strong> used on the order to verify it\'s you.</p>';
        } elseif (!$creds['order_no'] && $creds['email']) {
            $html = '<p>Thanks! I also need your <strong>order number</strong> — you\'ll find it in your confirmation email (e.g. #1234).</p>';
        } else {
            $html = '<p>I need both your <strong>order number</strong> and <strong>billing email</strong> to look that up. '
                  . 'Example: <em>#1234 name@email.com</em></p>';
        }
        return ['html' => $html, 'raw' => 'Asked again for missing order details'];
    }

    private static function tpl_no_match($attempts) {
        $remaining = self::MAX_ATTEMPTS - $attempts;
        $html = '<p>Hmm, I couldn\'t find an order matching those details. Please double-check the '
              . '<strong>order number</strong> and <strong>billing email</strong> and try again'
              . ($remaining === 1 ? ' <strong>(last attempt)</strong>' : '') . '.</p>';
        return ['html' => $html, 'raw' => 'Order lookup: no match (attempt ' . $attempts . ')'];
    }

    private static function tpl_locked_out() {
        $html = '<p>Too many unsuccessful lookup attempts. For security, order lookup is paused for a little while. '
              . 'Please try again in 15 minutes, or contact our support team — they\'ll be happy to help.</p>';
        return ['html' => $html, 'raw' => 'Order lookup locked out'];
    }

    private static function tpl_cancelled() {
        $html = '<p>No problem! Is there anything else I can help you with?</p>';
        return ['html' => $html, 'raw' => 'Order lookup cancelled'];
    }

    // =========================================================================
    // Throttling / lockout / helpers
    // =========================================================================

    private static function consume_lookup_slot() {
        $key   = 'chatzio_ot_cnt_' . md5(self::client_ip());
        $count = (int) get_transient($key);
        if ($count >= self::LOOKUP_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, self::LOOKUP_WINDOW);
        return true;
    }

    private static function is_locked_out() {
        return (bool) get_transient('chatzio_ot_lock_' . md5(self::client_ip()));
    }

    private static function set_lockout() {
        set_transient('chatzio_ot_lock_' . md5(self::client_ip()), 1, self::LOCKOUT_TTL);
        Chatzio_Logger::log_warning('Order lookup lockout set', ['ip' => self::client_ip()]);
    }

    private static function state_key($session_id) {
        // Bound to IP as well, so a spoofed session_id can't share flow state.
        return 'chatzio_ot_' . md5($session_id . '|' . self::client_ip());
    }

    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        return $ip ? $ip : '0.0.0.0';
    }

    private static function mask_email($email) {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}

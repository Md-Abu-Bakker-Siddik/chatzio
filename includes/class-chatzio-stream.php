<?php
/**
 * Chatzio - SSE Streaming Endpoint
 * Provides real-time word-by-word responses via Server-Sent Events.
 * Registered as a WordPress REST API route.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Stream {

    /**
     * Register REST API route
     */
    public static function register_routes() {
        register_rest_route('chatzio/v1', '/stream', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stream'],
            'permission_callback' => '__return_true', // Public endpoint, nonce-verified inside
        ]);
    }

    /**
     * Handle streaming request
     */
    public static function handle_stream(\WP_REST_Request $request) {
        // Verify nonce
        $nonce = $request->get_param('nonce');
        if (!wp_verify_nonce($nonce, 'chatzio_nonce') && !wp_verify_nonce($nonce, 'smartchat_nonce')) {
            return new \WP_Error('invalid_nonce', 'Invalid security token.', ['status' => 403]);
        }

        $message = $request->get_param('message');
        if (!is_string($message)) {
            $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        }
        $message = Chatzio_AJAX::sanitize_message_public($message);
        $session_id = sanitize_text_field($request->get_param('session_id') ?? '');
        $page_url   = sanitize_text_field($request->get_param('page_url') ?? '');

        // Validate
        $validated = Chatzio_Rate_Limiter::validate_message($message);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $message = $validated;

        if (empty($session_id)) {
            $session_id = uniqid('chatzio_', true);
        }

        // Rate limiting
        $rate_check = Chatzio_Rate_Limiter::check($session_id);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // Parse conversation history
        $history = [];
        $raw_history = $request->get_param('conversation_history');
        if (is_string($raw_history)) {
            $decoded = json_decode(stripslashes($raw_history), true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $clean_history = [];
        foreach ($history as $entry) {
            if (isset($entry['role']) && isset($entry['content'])) {
                $role = ($entry['role'] === 'assistant') ? 'assistant' : 'user';
                $content = Chatzio_AJAX::sanitize_message_public($entry['content']);
                $clean_history[] = [
                    'role'    => $role,
                    'content' => mb_substr($content, 0, 2000),
                ];
            }
        }

        // Build context
        $context = Chatzio_AJAX::build_context_public($message);

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx: disable buffering

        // Disable output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Stream the response
        $openrouter = new Chatzio_OpenRouter();
        $full_response = '';
        $model_used = '';
        $tokens_used = null;

        $result = $openrouter->send_message_stream($message, $context, $clean_history, function($chunk) use (&$full_response) {
            $full_response .= $chunk;
            // Strip [TOPIC:xxx] and [UNANSWERED] tags from streamed chunks so they never reach the user
            $clean_chunk = preg_replace('/\s*\[TOPIC\s*:\s*[a-z_]+\]\s*/i', '', $chunk);
            $clean_chunk = preg_replace('/\s*\[UNANSWERED\]\s*/i', '', $clean_chunk);
            if ($clean_chunk !== '') {
                echo "data: " . json_encode(['content' => $clean_chunk]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }
        });

        if (empty($result['success'])) {
            $error_msg = isset($result['error']) ? $result['error'] : 'Stream failed';
            $error_debug = isset($result['debug']) ? $result['debug'] : [];
            
            // Log stream error
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_error('Streaming API Error', [
                    'error' => $error_msg,
                    'debug' => $error_debug,
                ]);
            }
            
            echo "data: " . json_encode([
                'error' => $error_msg,
                'debug' => $error_debug,
            ]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            exit;
        }

        if (isset($result['model_used'])) $model_used = $result['model_used'];
        if (isset($result['tokens_used'])) $tokens_used = $result['tokens_used'];

        // Format and save
        $formatted = $openrouter->format_response_public($full_response);

        // Detect leads (email in user message)
        $lead_id = null;
        if (class_exists('Chatzio_Lead_Manager')) {
            $detected = Chatzio_Lead_Manager::detect_email_in_message($message);
            if ($detected) {
                $lead_id = Chatzio_Lead_Manager::capture_lead($session_id, $detected, '', 'inchat', $page_url);
            }
        }

        // Detect product mentions
        $products_mentioned = [];
        $stream_settings = get_option('chatzio_settings', []);
        $stream_cards_enabled = !isset($stream_settings['enable_product_cards']) || !empty($stream_settings['enable_product_cards']);
        if (class_exists('Chatzio_Product_Cards') && $stream_cards_enabled) {
            $card_result = Chatzio_Product_Cards::process_response($formatted, $context, $message);
            $formatted = $card_result['html'];
            $products_mentioned = $card_result['product_ids'];
        }

        // Resolve topic from AI response and strip tag
        $stream_topic = 'general';
        if (class_exists('Chatzio_Topic_Resolver')) {
            $stream_topic = Chatzio_Topic_Resolver::resolve($full_response, $message);
            $full_response = Chatzio_Topic_Resolver::strip_topic_tag($full_response);
            $formatted = Chatzio_Topic_Resolver::strip_topic_tag($formatted);
        }

        // Analyze for unanswered BEFORE stripping the tag
        $failed_analysis = null;
        if (class_exists('Chatzio_Failed_Topics')) {
            $failed_analysis = Chatzio_Failed_Topics::analyze_response($full_response);
            // Now strip [UNANSWERED] tag so it never reaches the user
            $full_response = Chatzio_Failed_Topics::strip_unanswered_tag($full_response);
            $formatted = Chatzio_Failed_Topics::strip_unanswered_tag($formatted);
        }

        // Save conversation
        $conversation_id = Chatzio_AJAX::save_conversation_public(
            $session_id, $message, $formatted, $full_response, $context, $lead_id, $products_mentioned, $stream_topic
        );

        if (class_exists('Chatzio_Notifications')) {
            Chatzio_Notifications::notify_new_conversation($session_id, $message, $stream_topic);
        }

        // Log and notify for failed/unanswered queries
        if ($failed_analysis && $failed_analysis['is_uncertain']) {
            Chatzio_Failed_Topics::log(
                $session_id,
                $message,
                $full_response,
                $failed_analysis['confidence_score']
            );
            if (class_exists('Chatzio_Notifications')) {
                Chatzio_Notifications::notify_failed_query($session_id, $message, $full_response, $stream_topic);
            }
        }

        // Track analytics
        if (class_exists('Chatzio_Analytics')) {
            Chatzio_Analytics::track('message_sent', $session_id, ['page_url' => $page_url]);
        }

        // Send final event with metadata
        echo "data: " . json_encode([
            'done'            => true,
            'formatted'       => $formatted,
            'conversation_id' => $conversation_id,
            'session_id'      => $session_id,
            'model_used'      => $model_used,
        ]) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();

        exit; // End SSE stream
    }
}

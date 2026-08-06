<?php
/**
 * AJAX Handler for Chatbot Interactions
 * Handles messaging, rate limiting, FULLTEXT search, feedback, and admin actions
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_AJAX {

    /**
     * Verify frontend nonce.
     * Accept both new and legacy action names to avoid breaking cached clients.
     */
    private static function verify_public_nonce() {
        if (!isset($_POST['nonce'])) {
            return false;
        }
        $nonce = $_POST['nonce'];
        return wp_verify_nonce($nonce, 'chatzio_nonce') || wp_verify_nonce($nonce, 'smartchat_nonce');
    }

    // =========================================================================
    // Public: Send Message (with memory, rate limiting, FULLTEXT search)
    // =========================================================================

    public static function handle_send_message() {
        // Verify nonce
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token. Please refresh the page.']);
            return;
        }

        // Get raw message — preserve newlines (sanitize_text_field strips them)
        $message    = isset($_POST['message']) ? self::sanitize_message($_POST['message']) : '';
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        // Validate message
        $validated = Chatzio_Rate_Limiter::validate_message($message);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()]);
            return;
        }
        $message = $validated;

        // Generate session ID if not provided
        if (empty($session_id)) {
            $session_id = uniqid('chatzio_', true);
        }

        // Rate limiting
        $rate_check = Chatzio_Rate_Limiter::check($session_id);
        if (is_wp_error($rate_check)) {
            wp_send_json_error(['message' => $rate_check->get_error_message()]);
            return;
        }

        // Order status / tracking lookup — handled 100% server-side.
        // Intercepts BEFORE cache and the LLM: order data never reaches OpenRouter.
        if (class_exists('Chatzio_Order_Tracking')) {
            $order_reply = Chatzio_Order_Tracking::maybe_handle($message, $session_id);
            if (is_array($order_reply)) {
                $conversation_id = self::save_conversation(
                    $session_id,
                    $message,
                    $order_reply['html'],
                    $order_reply['raw'],
                    [],
                    null,
                    [],
                    'order_tracking'
                );
                if (class_exists('Chatzio_Notifications')) {
                    Chatzio_Notifications::notify_new_conversation($session_id, $message, 'order_tracking');
                }
                wp_send_json_success([
                    'response'        => $order_reply['html'],
                    'raw_response'    => $order_reply['raw'],
                    'session_id'      => $session_id,
                    'conversation_id' => $conversation_id,
                    'model_used'      => 'order-lookup',
                ]);
                return;
            }
        }

        // Parse conversation history from frontend
        $history = [];
        if (isset($_POST['conversation_history'])) {
            $raw_history = $_POST['conversation_history'];
            if (is_string($raw_history)) {
                $decoded = json_decode(stripslashes($raw_history), true);
                if (is_array($decoded)) {
                    $history = $decoded;
                }
            } elseif (is_array($raw_history)) {
                $history = $raw_history;
            }
        }

        // Sanitize history entries
        $clean_history = [];
        foreach ($history as $entry) {
            if (isset($entry['role']) && isset($entry['content'])) {
                $role = ($entry['role'] === 'assistant') ? 'assistant' : 'user';
                $content = self::sanitize_message($entry['content']);
                if (!empty($content)) {
                    $clean_history[] = [
                        'role'    => $role,
                        'content' => mb_substr($content, 0, 2000),
                    ];
                }
            }
        }

        try {
            // Performance tracking
            $perf_start = microtime(true);
            $perf_timings = [];
            
            // Check cache for identical message (only if no conversation history — cached responses don't account for context)
            $cached = empty($clean_history) ? Chatzio_Request_Cache::get($session_id, $message) : false;
            $perf_timings['cache_check'] = round((microtime(true) - $perf_start) * 1000, 2);
            
            if ($cached && is_array($cached) && isset($cached['response'], $cached['raw_response'], $cached['model_used'])) {
                // Use cached response (still save to DB for analytics)
                // Defensive: strip internal tags in case cache holds unstripped data
                if (class_exists('Chatzio_Topic_Resolver')) {
                    $cached['response'] = Chatzio_Topic_Resolver::strip_topic_tag($cached['response']);
                    $cached['raw_response'] = Chatzio_Topic_Resolver::strip_topic_tag($cached['raw_response']);
                }
                // Analyze for unanswered BEFORE stripping
                $cached_failed_analysis = null;
                if (class_exists('Chatzio_Failed_Topics')) {
                    $cached_failed_analysis = Chatzio_Failed_Topics::analyze_response($cached['raw_response'] ?? '');
                    $cached['response'] = Chatzio_Failed_Topics::strip_unanswered_tag($cached['response']);
                    $cached['raw_response'] = Chatzio_Failed_Topics::strip_unanswered_tag($cached['raw_response']);
                }
                $response_html = $cached['response'];
                $products_mentioned = [];
                $cached_settings = get_option('chatzio_settings', []);
                $cached_cards_enabled = !isset($cached_settings['enable_product_cards']) || !empty($cached_settings['enable_product_cards']);
                if (class_exists('Chatzio_Product_Cards') && $cached_cards_enabled) {
                    $card_result = Chatzio_Product_Cards::process_response($response_html, [], $message);
                    $response_html = $card_result['html'];
                    $products_mentioned = $card_result['product_ids'];
                }
                // Use cached topic if available, otherwise fallback to keyword extraction
                $cached_topic = isset($cached['topic']) ? $cached['topic'] : 'general';
                if ($cached_topic === 'general' && class_exists('Chatzio_Topic_Resolver')) {
                    $cached_topic = Chatzio_Topic_Resolver::extract_from_query($message);
                }
                $conversation_id = self::save_conversation(
                    $session_id,
                    $message,
                    $response_html,
                    $cached['raw_response'],
                    [],
                    null,
                    $products_mentioned,
                    $cached_topic
                );
                if (class_exists('Chatzio_Notifications')) {
                    Chatzio_Notifications::notify_new_conversation($session_id, $message, $cached_topic);
                }
                if ($cached_failed_analysis && $cached_failed_analysis['is_uncertain']) {
                    Chatzio_Failed_Topics::log($session_id, $message, $cached['raw_response'] ?? '', $cached_failed_analysis['confidence_score']);
                    if (class_exists('Chatzio_Notifications')) {
                        Chatzio_Notifications::notify_failed_query($session_id, $message, $cached['raw_response'] ?? '', $cached_topic);
                    }
                }
                wp_send_json_success([
                    'response'        => $response_html,
                    'raw_response'    => $cached['raw_response'],
                    'session_id'      => $session_id,
                    'conversation_id' => $conversation_id,
                    'model_used'      => $cached['model_used'],
                ]);
                return;
            }

            // Build knowledge base context
            $context_start = microtime(true);
            $context = self::build_context($message);
            $perf_timings['context_build'] = round((microtime(true) - $context_start) * 1000, 2);

            // Send to OpenRouter with conversation history and approved native
            // tools. This point is reached only after nonce validation, input
            // validation, rate limiting, and the deterministic order gate.
            $api_start = microtime(true);
            $openrouter = new Chatzio_OpenRouter();
            if (class_exists('Chatzio_AI_Tool_Orchestrator')) {
                $orchestrator = new Chatzio_AI_Tool_Orchestrator();
                $result = $orchestrator->process_message(
                    $openrouter,
                    $message,
                    $context,
                    $clean_history,
                    $session_id
                );
            } else {
                $result = $openrouter->send_message($message, $context, $clean_history);
            }
            $perf_timings['api_call'] = round((microtime(true) - $api_start) * 1000, 2);

            if (!$result['success']) {
                // Include debug info for frontend console logging
                $error_response = [
                    'message' => $result['error'],
                ];
                if (isset($result['debug'])) {
                    $error_response['debug'] = $result['debug'];
                }
                wp_send_json_error($error_response);
                return;
            }

            // Tool results are already authorized, allowlisted, and rendered
            // server-side. Do not pass them through topic analysis, product
            // cards, or the normal response cache.
            if (!empty($result['order_tool_used'])) {
                $conversation_id = self::save_conversation(
                    $session_id,
                    $message,
                    $result['response'],
                    isset($result['raw_response']) ? $result['raw_response'] : '',
                    [],
                    null,
                    [],
                    'order_tracking'
                );
                if (class_exists('Chatzio_Notifications')) {
                    Chatzio_Notifications::notify_new_conversation($session_id, $message, 'order_tracking');
                }

                wp_send_json_success([
                    'response'           => $result['response'],
                    'raw_response'       => isset($result['raw_response']) ? $result['raw_response'] : '',
                    'session_id'         => $session_id,
                    'conversation_id'    => $conversation_id,
                    'model_used'         => isset($result['model_used']) ? $result['model_used'] : 'order-tool',
                    'message_type'       => !empty($result['order_tool_verified']) ? 'order_status' : 'order_support',
                    'conversation_state' => !empty($result['order_tool_verified']) ? 'verified_order' : 'verification_required',
                ]);
                return;
            }

            // Resolve topic from AI response (before stripping the tag)
            $resolved_topic = 'general';
            $raw_for_topic = $result['raw_response'] ?? '';
            if (class_exists('Chatzio_Topic_Resolver')) {
                $resolved_topic = Chatzio_Topic_Resolver::resolve($raw_for_topic, $message);
                // Strip [TOPIC:xxx] tag from both raw and formatted responses
                $result['raw_response'] = Chatzio_Topic_Resolver::strip_topic_tag($result['raw_response'] ?? '');
                $result['response'] = Chatzio_Topic_Resolver::strip_topic_tag($result['response']);
            }

            // Analyze for unanswered BEFORE stripping the tag
            $failed_analysis = null;
            if (class_exists('Chatzio_Failed_Topics')) {
                $failed_analysis = Chatzio_Failed_Topics::analyze_response($result['raw_response'] ?? '');
                // Now strip [UNANSWERED] tag so it never reaches the user
                $result['raw_response'] = Chatzio_Failed_Topics::strip_unanswered_tag($result['raw_response'] ?? '');
                $result['response'] = Chatzio_Failed_Topics::strip_unanswered_tag($result['response']);
            }

            // Cache successful response (only first-time queries, not follow-ups)
            if (empty($clean_history)) {
                Chatzio_Request_Cache::set($session_id, $message, [
                    'response'     => $result['response'],
                    'raw_response' => $result['raw_response'] ?? '',
                    'model_used'   => $result['model_used'],
                    'topic'        => $resolved_topic,
                ]);
            }

            // Process response for product cards and mention extraction
            $cards_start = microtime(true);
            $response_html = $result['response'];
            $products_mentioned = [];
            $settings = get_option('chatzio_settings', []);
            $product_cards_enabled = !isset($settings['enable_product_cards']) || !empty($settings['enable_product_cards']);
            if (class_exists('Chatzio_Product_Cards') && $product_cards_enabled) {
                $card_result = Chatzio_Product_Cards::process_response($response_html, $context, $message);
                $response_html = $card_result['html'];
                $products_mentioned = $card_result['product_ids'];
            }
            $perf_timings['product_cards'] = round((microtime(true) - $cards_start) * 1000, 2);

            // Save conversation and get the ID (for feedback)
            $db_start = microtime(true);
            $conversation_id = self::save_conversation(
                $session_id,
                $message,
                $response_html,
                $result['raw_response'] ?? '',
                $context,
                null,
                $products_mentioned,
                $resolved_topic
            );
            if (class_exists('Chatzio_Notifications')) {
                Chatzio_Notifications::notify_new_conversation($session_id, $message, $resolved_topic);
            }
            if ($failed_analysis && $failed_analysis['is_uncertain']) {
                Chatzio_Failed_Topics::log($session_id, $message, $result['raw_response'] ?? '', $failed_analysis['confidence_score']);
                if (class_exists('Chatzio_Notifications')) {
                    Chatzio_Notifications::notify_failed_query($session_id, $message, $result['raw_response'] ?? '', $resolved_topic);
                }
            }
            $perf_timings['db_save'] = round((microtime(true) - $db_start) * 1000, 2);
            $perf_timings['total'] = round((microtime(true) - $perf_start) * 1000, 2);

            // Log performance metrics
            Chatzio_Logger::log_debug('Message processing performance', [
                'timings'      => $perf_timings,
                'message'      => mb_substr($message, 0, 50),
                'raw_response' => $result['raw_response'] ?? '',
            ]);

            wp_send_json_success([
                'response'        => $response_html,
                'raw_response'    => $result['raw_response'] ?? '',
                'session_id'      => $session_id,
                'conversation_id' => $conversation_id,
                'model_used'      => $result['model_used'],
                'performance'     => $perf_timings, // Include timing data for debugging
            ]);

        } catch (Exception $e) {
            Chatzio_Logger::log_error('AJAX message handling failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error([
                'message' => 'An error occurred while processing your message. Please try again.'
            ]);
        }
    }

    // =========================================================================
    // Public: Save Feedback (thumbs up/down)
    // =========================================================================

    public static function handle_feedback() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $feedback        = isset($_POST['feedback']) ? sanitize_text_field($_POST['feedback']) : '';
        $session_id      = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if (!$conversation_id || !in_array($feedback, ['up', 'down'], true)) {
            wp_send_json_error(['message' => 'Invalid feedback data']);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';

        // Scope feedback to caller's session to prevent IDOR
        $where = ['id' => $conversation_id];
        $where_format = ['%d'];
        if (!empty($session_id)) {
            $where['session_id'] = $session_id;
            $where_format[] = '%s';
        }

        $updated = $wpdb->update(
            $table,
            ['feedback' => $feedback],
            $where,
            ['%s'],
            $where_format
        );

        if ($updated !== false) {
            wp_send_json_success(['message' => 'Feedback saved']);
        } else {
            wp_send_json_error(['message' => 'Failed to save feedback']);
        }
    }

    // =========================================================================
    // Context Building (FULLTEXT + normalization)
    // =========================================================================

    /**
     * Filter products to only include those matching query keywords
     * When user asks for specific products (e.g., "oil"), only show matching products
     */
    private static function filter_products_by_query($products, $message, $keywords) {
        if (empty($products)) {
            return $products;
        }

        // Check if query is generic (asking for all products / browsing)
        $message_lower = mb_strtolower(trim($message));
        $generic_patterns = [
            'all products', 'list products', 'show products', 'what products',
            'what do you sell', 'what do u sell', 'catalog', 'all items',
            'what u have', 'what you have', 'what do you have', 'what do u have',
            'whats available', 'what\'s available', 'show me everything',
            'show me what you have', 'what can i buy', 'tell me what u have',
            'tell me what you have', 'your products', 'your items',
            'available products', 'products available',
        ];
        foreach ($generic_patterns as $pattern) {
            if (strpos($message_lower, $pattern) !== false) {
                return $products;
            }
        }

        // If no meaningful keywords extracted, return all products
        if (empty($keywords)) {
            return $products;
        }

        // Filter products that match keywords - use scoring system
        $scored = [];
        foreach ($products as $idx => $product) {
            $title_lower = mb_strtolower($product['title'] ?? '');
            $content_lower = mb_strtolower($product['content'] ?? '');
            $categories_lower = mb_strtolower($product['categories'] ?? '');
            $score = 0;

            foreach ($keywords as $keyword) {
                $kw_len = strlen($keyword);
                if ($kw_len < 3) {
                    // Short keywords (2 chars): require word-boundary match to prevent false positives
                    // e.g. "oi" shouldn't match "organic", but "oil" should be >=3
                    continue;
                }

                // Title match: highest value
                if (strpos($title_lower, $keyword) !== false) {
                    $score += 10;
                }
                // Category match: high value (most relevant for classification queries)
                if (strpos($categories_lower, $keyword) !== false) {
                    $score += 8;
                }
                // Content match: moderate value
                if (strpos($content_lower, $keyword) !== false) {
                    $score += 3;
                }
            }

            if ($score > 0) {
                $scored[] = ['product' => $product, 'score' => $score];
            }
        }

        // Sort by score descending
        usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });
        $filtered = array_map(function ($item) { return $item['product']; }, $scored);

        // If filtering removed all products, return ALL products so the AI can
        // tell the user the specific item isn't available and suggest alternatives
        if (empty($filtered) && !empty($products)) {
            Chatzio_Logger::log_debug('Product filter: no exact matches, passing all products for alternatives', [
                'query' => mb_substr($message, 0, 100),
                'keywords' => $keywords,
                'original_count' => count($products),
            ]);
            return $products;
        }

        return $filtered;
    }

    /**
     * Detect if user is asking to list/show all products
     */
    private static function is_product_listing_query($message) {
        $message_lower = mb_strtolower($message);
        $patterns = [
            'list all products',
            'show all products',
            'all products',
            'list products',
            'show products',
            'what products',
            'what are your products',
            'what do you sell',
            'catalog',
            'products available',
            'available products',
            'all items',
            'list items',
            'show items',
        ];
        foreach ($patterns as $pattern) {
            if (strpos($message_lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load all products from database for product listing queries
     * Includes categories and structured metadata for proper filtering and AI context
     */
    private static function load_all_products($limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE content_type = 'product' AND title != '' ORDER BY title ASC LIMIT %d",
            $limit
        ), ARRAY_A);
        
        $products = [];
        foreach ($rows as $row) {
            $metadata = json_decode($row['metadata'] ?? '{}', true);
            $content_id = isset($row['content_id']) ? (int) $row['content_id'] : 0;
            $image = isset($metadata['image']) && $metadata['image'] !== '' ? $metadata['image'] : '';
            if ($image === '' && $content_id) {
                $image = self::get_product_image_url_by_content_id($content_id);
            }
            $product = [
                'id' => $content_id,
                'title' => $row['title'],
                'url' => $row['url'],
                'content' => mb_strlen($row['content']) > 500 ? mb_substr($row['content'], 0, 500) . '...' : $row['content'],
                'categories' => '',
            ];
            if ($image !== '') $product['image'] = $image;
            // Add structured metadata for AI context
            if (isset($metadata['categories'])) {
                $product['categories'] = is_array($metadata['categories']) ? implode(', ', $metadata['categories']) : $metadata['categories'];
            }
            if (isset($metadata['regular_price'])) $product['regular_price'] = $metadata['regular_price'];
            if (isset($metadata['sale_price']))    $product['sale_price']    = $metadata['sale_price'];
            if (isset($metadata['price']))         $product['price']         = $metadata['price'];
            if (isset($metadata['stock_status']))  $product['stock_status']  = $metadata['stock_status'];
            if (isset($metadata['sku']))           $product['sku']           = $metadata['sku'];
            $products[] = $product;
        }
        return $products;
    }

    /**
     * Build knowledge base context for the AI.
     * Uses hybrid semantic+keyword search (RAG chunks) when embeddings exist; otherwise FULLTEXT/keyword fallback.
     */
    private static function build_context($message) {
        $context = [
            'chunks'    => [],
            'posts'     => [],
            'pages'     => [],
            'products'  => [],
            'resources' => [],
        ];
        
        // Sub-timing for detailed performance analysis
        $sub_timings = [];
        $sub_start = microtime(true);

        $normalized = self::normalize_query($message);
        $keywords   = self::extract_keywords($message);
        $sub_timings['normalize_keywords'] = round((microtime(true) - $sub_start) * 1000, 2);

        // Check if user is asking for all products - if so, load all products
        $is_product_listing = self::is_product_listing_query($message);
        
        // Check search mode setting (default: hybrid)
        $settings = get_option('chatzio_settings', []);
        $search_mode = isset($settings['search_mode']) ? $settings['search_mode'] : 'hybrid';
        
        // Always load resources regardless of search mode
        $resources = self::get_relevant_resources($message, $keywords);
        if (!empty($resources)) {
            $context['resources'] = $resources;
        }

        // Use RAG/vector search when embeddings are available and mode allows it
        if (class_exists('Chatzio_Embeddings') && Chatzio_Embeddings::has_embeddings() && $search_mode !== 'fulltext') {
            $rag_start = microtime(true);
            
            // Increase limit for product listing queries
            $rag_limit = $is_product_listing ? 20 : 5;
            
            if ($search_mode === 'vector') {
                // Vector-only mode
                $chunks = Chatzio_Embeddings::search($message, $rag_limit);
            } else {
                // Hybrid mode (default)
                $chunks = Chatzio_Embeddings::hybrid_search($message, $keywords, $rag_limit);
            }
            $sub_timings['rag_search'] = round((microtime(true) - $rag_start) * 1000, 2);
            
            if (!empty($chunks)) {
                $context['chunks'] = $chunks;
                // Collect unique product source IDs from chunks
                $seen_titles = [];
                $product_source_ids = [];
                foreach ($chunks as $c) {
                    if (isset($c['content_type']) && $c['content_type'] === 'product' && isset($c['source_id']) && isset($c['title']) && $c['title'] !== '' && !isset($seen_titles[$c['title']])) {
                        $product_source_ids[] = (int) $c['source_id'];
                        $seen_titles[$c['title']] = true;
                    }
                }
                
                // Fetch full product data from database for filtering + AI context
                if (!empty($product_source_ids)) {
                    global $wpdb;
                    $content_table = $wpdb->prefix . 'chatzio_content';
                    $product_source_ids = array_unique($product_source_ids);
                    $placeholders = implode(',', array_fill(0, count($product_source_ids), '%d'));
                    $product_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, content_id, title, url, content, metadata FROM $content_table WHERE id IN ($placeholders) AND content_type = 'product'",
                        ...$product_source_ids
                    ), ARRAY_A);
                    
                    foreach ($product_rows as $row) {
                        $metadata = json_decode($row['metadata'] ?? '{}', true);
                        $content_id = isset($row['content_id']) ? (int) $row['content_id'] : 0;
                        $image = isset($metadata['image']) && $metadata['image'] !== '' ? $metadata['image'] : '';
                        if ($image === '' && $content_id) {
                            $image = self::get_product_image_url_by_content_id($content_id);
                        }
                        $product_data = [
                            'id' => $content_id,
                            'title' => $row['title'],
                            'url' => $row['url'],
                            'content' => mb_strlen($row['content']) > 500 ? mb_substr($row['content'], 0, 500) . '...' : $row['content'],
                            'categories' => isset($metadata['categories']) ? (is_array($metadata['categories']) ? implode(', ', $metadata['categories']) : $metadata['categories']) : '',
                        ];
                        if ($image !== '') $product_data['image'] = $image;
                        if (isset($metadata['regular_price'])) $product_data['regular_price'] = $metadata['regular_price'];
                        if (isset($metadata['sale_price']))    $product_data['sale_price']    = $metadata['sale_price'];
                        if (isset($metadata['price']))         $product_data['price']         = $metadata['price'];
                        if (isset($metadata['stock_status']))  $product_data['stock_status']  = $metadata['stock_status'];
                        if (isset($metadata['sku']))           $product_data['sku']           = $metadata['sku'];
                        $context['products'][] = $product_data;
                    }
                }
            }
            
            // If user asks for all products, load ALL products from database (limit to 50 for performance)
            if ($is_product_listing) {
                $all_products = self::load_all_products(50);
                // Merge with RAG results, avoiding duplicates
                $existing_titles = array_flip(array_column($context['products'], 'title'));
                foreach ($all_products as $product) {
                    if (!isset($existing_titles[$product['title']])) {
                        $context['products'][] = $product;
                        $existing_titles[$product['title']] = true;
                    }
                }
            } else {
                // For specific product queries, filter to only matching products
                $context['products'] = self::filter_products_by_query($context['products'], $message, $keywords);
            }
            
            // Add flag for prompt building
            $context['is_product_listing'] = $is_product_listing;
            
            Chatzio_Logger::log_debug('Context built (RAG ' . $search_mode . ')', [
                'message'        => mb_substr($message, 0, 100),
                'keywords'       => $keywords,
                'chunks_count'   => count($context['chunks']),
                'products_count' => count($context['products']),
                'product_titles' => array_column($context['products'], 'title'),
                'is_product_listing' => $is_product_listing,
                'sub_timings'    => $sub_timings,
            ]);
            return $context;
        }

        // Fallback: FULLTEXT + keyword search (no recent-content noise)
        $content_results = self::search_content($message, $normalized, $keywords);
        if (!empty($content_results['posts']))    $context['posts']    = $content_results['posts'];
        if (!empty($content_results['pages']))    $context['pages']    = $content_results['pages'];
        if (!empty($content_results['products'])) $context['products'] = $content_results['products'];
        
        // If user asks for all products, load ALL products from database (limit to 50 for performance)
        if ($is_product_listing) {
            $all_products = self::load_all_products(50);
            // Merge with search results, avoiding duplicates
            $existing_titles = array_flip(array_column($context['products'], 'title'));
            foreach ($all_products as $product) {
                if (!isset($existing_titles[$product['title']])) {
                    $context['products'][] = $product;
                    $existing_titles[$product['title']] = true;
                }
            }
        } else {
            // For specific product queries, filter to only matching products
            $context['products'] = self::filter_products_by_query($context['products'], $message, $keywords);
        }
        
        // Add flag for prompt building
        $context['is_product_listing'] = $is_product_listing;

        Chatzio_Logger::log_debug('Context built (legacy)', [
            'message'         => mb_substr($message, 0, 100),
            'keywords'        => $keywords,
            'posts_count'     => count($context['posts']),
            'pages_count'     => count($context['pages']),
            'products_count'  => count($context['products']),
            'product_titles'  => array_column($context['products'], 'title'),
            'is_product_listing' => $is_product_listing,
            'resources_count' => count($context['resources']),
        ]);

        return $context;
    }

    /**
     * Normalize query for better matching
     * BPC-157 → bpc 157, BPC_157 → bpc 157, etc.
     */
    private static function normalize_query($query) {
        $query = strtolower($query);
        $query = str_replace(['-', '_', '/', '\\', '.'], ' ', $query);
        $query = preg_replace('/\s+/', ' ', $query);
        return trim($query);
    }

    /**
     * Extract meaningful keywords (stop-word filtered)
     * Keeps product/commerce-relevant terms that previous stop-word list was incorrectly removing
     */
    private static function extract_keywords($message) {
        $stop_words = [
            // Articles / prepositions / conjunctions
            'what', 'is', 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'as', 'into', 'through', 'during', 'before', 'after',
            'above', 'below', 'between', 'under', 'again', 'further', 'then', 'once', 'here',
            'there', 'when', 'where', 'why', 'how', 'each', 'few', 'more', 'most', 'other',
            'some', 'such', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'can', 'will',
            'just', 'should', 'now', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she',
            'it', 'they', 'them', 'this', 'that', 'these', 'those', 'am', 'are', 'was', 'were',
            'be', 'been', 'being', 'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing',
            'would', 'could', 'might', 'must', 'shall', 'about', 'tell', 'know', 'help', 'please',
            'much', 'many', 'like', 'also', 'got', 'made', 'any', 'not', 'no',
            // Pronouns
            'i', 'im',
        ];

        $words = preg_split('/\s+/', strtolower($message));
        $keywords = [];
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z0-9]/', '', $word);
            if (strlen($word) >= 2 && !in_array($word, $stop_words, true)) {
                $keywords[] = $word;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Search content using FULLTEXT (with LIKE fallback)
     */
    private static function search_content($original_message, $normalized, $keywords) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return ['posts' => [], 'pages' => [], 'products' => []];
        }

        $results  = [];
        $seen_ids = [];
        $has_fulltext = Chatzio_Database::has_fulltext_index();

        // --- Strategy 1: FULLTEXT search (if available) ---
        if ($has_fulltext && !empty($keywords)) {
            // Build boolean query: +keyword1* +keyword2*
            $boolean_terms = [];
            foreach ($keywords as $kw) {
                // MySQL FULLTEXT ignores words shorter than ft_min_token_size (default 3 for InnoDB)
                // Short terms are handled by the LIKE fallback strategies below
                if (strlen($kw) >= 3) {
                    $boolean_terms[] = '+' . $wpdb->esc_like($kw) . '*';
                }
            }

            if (!empty($boolean_terms)) {
                $boolean_query = implode(' ', $boolean_terms);
                $fulltext_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT *, MATCH(title, content) AGAINST(%s IN BOOLEAN MODE) as relevance
                     FROM $table
                     WHERE MATCH(title, content) AGAINST(%s IN BOOLEAN MODE)
                     ORDER BY relevance DESC
                     LIMIT 8",
                    $boolean_query, $boolean_query
                ), ARRAY_A);

                foreach ($fulltext_results as $row) {
                    if (!in_array($row['id'], $seen_ids, true)) {
                        $results[]  = $row;
                        $seen_ids[] = $row['id'];
                    }
                }
            }
        }

        // --- Strategy 2: LIKE search on normalized query ---
        if (count($results) < 5) {
            $norm_term = '%' . $wpdb->esc_like($normalized) . '%';
            $like_results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE LOWER(title) LIKE %s OR LOWER(content) LIKE %s LIMIT 5",
                $norm_term, $norm_term
            ), ARRAY_A);

            foreach ($like_results as $row) {
                if (!in_array($row['id'], $seen_ids, true)) {
                    $results[]  = $row;
                    $seen_ids[] = $row['id'];
                }
            }
        }

        // --- Strategy 3: Per-keyword LIKE fallback ---
        if (count($results) < 5) {
            foreach ($keywords as $kw) {
                if (count($results) >= 10) break;
                $kw_term = '%' . $wpdb->esc_like($kw) . '%';
                $kw_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $table WHERE LOWER(title) LIKE %s OR LOWER(content) LIKE %s LIMIT 3",
                    $kw_term, $kw_term
                ), ARRAY_A);

                foreach ($kw_results as $row) {
                    if (!in_array($row['id'], $seen_ids, true)) {
                        $results[]  = $row;
                        $seen_ids[] = $row['id'];
                    }
                }
            }
        }

        // No random "recent content" fallback — if nothing matches, return empty and let LLM say it doesn't know

        // Format results with enriched product data
        return self::format_search_results($results);
    }

    /**
     * Format search results with structured product metadata
     */
    private static function format_search_results($results) {
        $formatted = ['posts' => [], 'pages' => [], 'products' => []];

        foreach ($results as $row) {
            $metadata = json_decode($row['metadata'] ?? '{}', true);

            $item = [
                'title'   => $row['title'],
                'content' => mb_strlen($row['content']) > 2000
                    ? mb_substr($row['content'], 0, 2000) . '...'
                    : $row['content'],
                'url'     => $row['url'],
            ];

            if ($row['content_type'] === 'product') {
                // Enrich with structured product fields
                if (isset($metadata['price']))         $item['price']         = $metadata['price'];
                if (isset($metadata['regular_price'])) $item['regular_price'] = $metadata['regular_price'];
                if (isset($metadata['sale_price']))    $item['sale_price']    = $metadata['sale_price'];
                if (isset($metadata['sku']))           $item['sku']           = $metadata['sku'];
                if (isset($metadata['stock_status']))  $item['stock_status']  = $metadata['stock_status'];
                if (isset($metadata['categories']))    $item['categories']    = implode(', ', (array) $metadata['categories']);
                if (isset($metadata['weight']))        $item['weight']        = $metadata['weight'];
                if (isset($metadata['image']) && $metadata['image'] !== '') {
                    $item['image'] = $metadata['image'];
                } else {
                    $item['image'] = self::get_product_image_url_by_content_id($row['content_id'] ?? 0);
                }

                $formatted['products'][] = $item;
            } elseif ($row['content_type'] === 'post') {
                $formatted['posts'][] = $item;
            } elseif ($row['content_type'] === 'page') {
                $formatted['pages'][] = $item;
            }
        }

        return $formatted;
    }

    /**
     * Get product image URL by WooCommerce product ID (content_id). Fallback when metadata has no image.
     */
    private static function get_product_image_url_by_content_id($content_id) {
        if (!$content_id || !class_exists('WooCommerce')) {
            return '';
        }
        $product = wc_get_product($content_id);
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }
        $image_id = $product->get_image_id();
        if (!$image_id) {
            return '';
        }
        $url = wp_get_attachment_image_url($image_id, 'medium');
        if (!is_string($url) || $url === '') {
            return '';
        }
        return preg_match('#^https?://#i', $url) ? $url : home_url($url);
    }

    /**
     * Get relevant resources — smart inclusion instead of dumping everything.
     *
     * Logic:
     * - If total resource content is small (≤30K chars), include all (small site, every doc matters)
     * - If large, search resources by keyword relevance and include only the best matches
     * - Always cap individual resource size and total budget
     */
    private static function get_relevant_resources($message, $keywords = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) return [];

        $results = $wpdb->get_results(
            "SELECT id, title, file_type, content, LENGTH(content) as content_length
             FROM $table WHERE status = 'active' ORDER BY uploaded_at DESC LIMIT 20",
            ARRAY_A
        );

        if (!$results) return [];

        // Calculate total content size
        $total_chars = 0;
        foreach ($results as $row) {
            $total_chars += (int) ($row['content_length'] ?? 0);
        }

        $max_budget = 30000; // Total chars we're willing to send
        $max_per_resource = 10000; // Cap per individual resource

        // Small knowledge base — include everything (admin uploaded it for a reason)
        if ($total_chars <= $max_budget) {
            $formatted = [];
            foreach ($results as $row) {
                $content = isset($row['content']) ? $row['content'] : '';
                $formatted[] = [
                    'title'     => $row['title'],
                    'content'   => mb_strlen($content) > $max_per_resource
                        ? mb_substr($content, 0, $max_per_resource) . '...'
                        : $content,
                    'file_type' => $row['file_type'] ?? 'unknown',
                ];
            }
            return $formatted;
        }

        // Large knowledge base — score by keyword relevance, include top matches
        $scored = [];
        $normalized_msg = strtolower($message);

        foreach ($results as $row) {
            $score = 0;
            $title_lower = strtolower($row['title']);
            $content_lower = strtolower(mb_substr($row['content'] ?? '', 0, 5000)); // Only scan first 5K for scoring

            // Title match (high weight)
            if (strpos($title_lower, $normalized_msg) !== false) {
                $score += 10;
            }

            // Keyword matches
            foreach ($keywords as $kw) {
                if (strlen($kw) < 2) continue;
                if (strpos($title_lower, $kw) !== false) $score += 5;
                if (strpos($content_lower, $kw) !== false) $score += 2;
            }

            // Even unmatched resources get a base score of 1 (so we have fallbacks)
            $scored[] = [
                'row'   => $row,
                'score' => max($score, 1),
            ];
        }

        // Sort by score descending
        usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });

        // Take top resources within budget
        $formatted = [];
        $budget_used = 0;

        foreach ($scored as $item) {
            $row = $item['row'];
            $content = isset($row['content']) ? $row['content'] : '';
            $content_len = mb_strlen($content);

            // Cap this resource
            if ($content_len > $max_per_resource) {
                $content = mb_substr($content, 0, $max_per_resource) . '...';
                $content_len = $max_per_resource;
            }

            // Check budget
            if ($budget_used + $content_len > $max_budget && !empty($formatted)) {
                break; // Already have some, stop
            }

            $formatted[] = [
                'title'     => $row['title'],
                'content'   => $content,
                'file_type' => $row['file_type'] ?? 'unknown',
            ];

            $budget_used += $content_len;

            // Hard cap at 5 resources even if budget remains
            if (count($formatted) >= 5) break;
        }

        return $formatted;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Public accessor for sanitize_message (used by streaming endpoint)
     */
    public static function sanitize_message_public($input) {
        return self::sanitize_message($input);
    }

    /**
     * Public accessor for build_context (used by streaming endpoint)
     */
    public static function build_context_public($message) {
        return self::build_context($message);
    }

    /**
     * Public accessor for save_conversation with lead + products support
     */
    public static function save_conversation_public($session_id, $user_message, $bot_response, $bot_response_raw, $context, $lead_id = null, $products_mentioned = [], $topic = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        
        // Build messages JSON array
        $messages = [
            ['role' => 'user', 'content' => $user_message],
            ['role' => 'assistant', 'content' => $bot_response],
        ];

        $data = [
            'session_id'       => $session_id,
            'user_id'          => $user_id,
            'user_message'     => $user_message,
            'bot_response'     => $bot_response,
            'bot_response_raw' => $bot_response_raw,
            'context_used'     => json_encode($context),
            'messages'         => json_encode($messages),
        ];

        if ($lead_id) $data['lead_id'] = $lead_id;
        if (!empty($products_mentioned)) $data['products_mentioned'] = json_encode($products_mentioned);
        if ($topic !== null) $data['topic'] = $topic;

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Sanitize user message — preserves newlines unlike sanitize_text_field
     */
    private static function sanitize_message($input) {
        if (!is_string($input)) return '';

        // Strip HTML tags
        $clean = wp_strip_all_tags($input);

        // Decode HTML entities
        $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');

        // Remove null bytes and control chars (keep \n \r \t)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);

        return trim($clean);
    }

    /**
     * Save conversation and return the inserted ID
     *
     * @param string $session_id
     * @param string $user_message
     * @param string $bot_response
     * @param string $bot_response_raw
     * @param array  $context
     * @param int|null $lead_id Optional lead ID from chatzio_leads
     * @param int[]  $products_mentioned Optional product IDs (content_id) mentioned in the response
     */
    private static function save_conversation($session_id, $user_message, $bot_response, $bot_response_raw, $context, $lead_id = null, $products_mentioned = [], $topic = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';
        $leads_table = $wpdb->prefix . 'chatzio_leads';

        // Attach lead when not provided: look up by session so pre-chat captures show in admin
        if ($lead_id === null) {
            $lead_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $leads_table WHERE session_id = %s ORDER BY id DESC LIMIT 1",
                $session_id
            ));
            $lead_id = $lead_id ? (int) $lead_id : null;
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        
        // Build messages JSON array
        $messages = [
            ['role' => 'user', 'content' => $user_message],
            ['role' => 'assistant', 'content' => $bot_response],
        ];

        $data = [
            'session_id'       => $session_id,
            'user_id'          => $user_id,
            'user_message'     => $user_message,
            'bot_response'     => $bot_response,
            'bot_response_raw' => $bot_response_raw,
            'context_used'     => json_encode($context),
            'messages'         => json_encode($messages),
        ];
        if ($lead_id !== null) {
            $data['lead_id'] = $lead_id;
        }
        if (!empty($products_mentioned)) {
            $data['products_mentioned'] = json_encode(array_values(array_map('intval', $products_mentioned)));
        }
        if ($topic !== null) {
            $data['topic'] = $topic;
        }

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    // =========================================================================
    // Admin AJAX Handlers (unchanged logic, kept for reference)
    // =========================================================================

    public static function handle_sync_content() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        @set_time_limit(180);

        $results = Chatzio_Content_Sync::sync_all_content();
        wp_send_json_success([
            'message' => 'Content synced successfully',
            'results' => $results
        ]);
    }

    public static function handle_reembed_chunks() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        if (!class_exists('Chatzio_Embeddings')) {
            wp_send_json_error(['message' => 'Embeddings not available']);
            return;
        }
        @set_time_limit(180);
        try {
            $result = Chatzio_Embeddings::sync_chunks_for_content();
            wp_send_json_success([
                'message' => sprintf(
                    __('Chunks created: %d, embedded: %d.', 'chatzio-ai'),
                    $result['chunks_created'],
                    $result['chunks_embedded']
                ),
                'chunks_created'  => $result['chunks_created'],
                'chunks_embedded' => $result['chunks_embedded'],
                'errors'          => $result['errors'],
            ]);
        } catch (Exception $e) {
            Chatzio_Logger::log_error('Re-embed failed', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => 'Re-embed failed: ' . $e->getMessage()]);
        }
    }

    public static function handle_upload_resource() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        if (!isset($_FILES['resource_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
            return;
        }

        $result = Chatzio_Resource_Manager::upload_resource($_FILES['resource_file']);
        if ($result['success']) {
            if (class_exists('Chatzio_Embeddings') && !empty($result['resource_id'])) {
                Chatzio_Embeddings::sync_chunks_for_resource((int) $result['resource_id']);
            }
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public static function handle_delete_resource() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $resource_id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;
        if (Chatzio_Resource_Manager::delete_resource($resource_id)) {
            global $wpdb;
            $chunks_table = $wpdb->prefix . 'chatzio_chunks';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $chunks_table)) === $chunks_table) {
                $wpdb->delete($chunks_table, ['source_type' => 'resource', 'source_id' => $resource_id], ['%s', '%d']);
            }
            wp_send_json_success(['message' => 'Resource deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete resource']);
        }
    }

    public static function handle_test_api() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $openrouter = new Chatzio_OpenRouter();
        $result = $openrouter->test_connection();

        if ($result['success']) {
            wp_send_json_success([
                'message'  => 'API connection successful!',
                'response' => $result['response']
            ]);
        } else {
            wp_send_json_error([
                'message' => 'API connection failed',
                'error'   => $result['error']
            ]);
        }
    }

    /**
     * Partial save of settings (for setup wizard and quick updates).
     * Only updates keys that are present in POST and allowed in $allowed_keys.
     */
    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $allowed_keys = [
            'openrouter_api_key' => 'sanitize_text_field',
            'bot_name'           => 'sanitize_text_field',
            'welcome_message'    => 'sanitize_textarea_field',
            'primary_color'      => 'sanitize_hex_color',
        ];

        $settings = get_option('chatzio_settings', []);
        foreach ($allowed_keys as $option_key => $sanitize) {
            $post_key = 'chatzio_' . $option_key;
            if (!isset($_POST[$post_key])) {
                continue;
            }
            $settings[$option_key] = $sanitize($_POST[$post_key]);
        }
        // Encrypt API key at rest when saving via AJAX
        if (isset($settings['openrouter_api_key']) && $settings['openrouter_api_key'] !== '' && !Chatzio_API_Key_Crypto::is_encrypted($settings['openrouter_api_key'])) {
            $settings['openrouter_api_key'] = Chatzio_API_Key_Crypto::encrypt($settings['openrouter_api_key']);
        }
        update_option('chatzio_settings', $settings);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * Mark setup wizard as complete (dismiss wizard).
     */
    public static function handle_complete_setup() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        update_option('chatzio_setup_complete', '1');
        wp_send_json_success();
    }

    public static function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        if (Chatzio_Logger::clear_all_logs()) {
            wp_send_json_success(['message' => 'All logs cleared']);
        } else {
            wp_send_json_error(['message' => 'Failed to clear logs']);
        }
    }

    public static function handle_clear_conversations() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        global $wpdb;
        $cleared = [];
        $conv_table     = $wpdb->prefix . 'chatzio_conversations';
        $analytics_table = $wpdb->prefix . 'chatzio_analytics';
        $failed_table   = $wpdb->prefix . 'chatzio_failed_topics';

        if (!empty($_POST['clear_conversations'])) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$conv_table'") === $conv_table) {
                $wpdb->query("TRUNCATE TABLE `$conv_table`");
                $cleared[] = 'conversations';
            }
        }
        if (!empty($_POST['clear_analytics'])) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$analytics_table'") === $analytics_table) {
                $wpdb->query("TRUNCATE TABLE `$analytics_table`");
                $cleared[] = 'analytics';
            }
        }
        if (!empty($_POST['clear_failed_topics'])) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$failed_table'") === $failed_table) {
                $wpdb->query("TRUNCATE TABLE `$failed_table`");
                $cleared[] = 'failed_topics';
            }
        }

        if (empty($cleared)) {
            wp_send_json_error(['message' => 'Nothing selected to clear']);
            return;
        }

        $count = count($cleared);
        $message = sprintf(_n('Cleared %d data table.', 'Cleared %d data tables.', $count, 'chatzio-ai'), $count);
        wp_send_json_success(['message' => $message, 'cleared' => $cleared]);
    }

    public static function handle_paste_resource() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $title   = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';

        if (empty($title)) {
            wp_send_json_error(['message' => 'Title is required']);
            return;
        }
        if (empty($content) || strlen($content) < 50) {
            wp_send_json_error(['message' => 'Content must be at least 50 characters']);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_resources';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            wp_send_json_error(['message' => 'Database table not found. Please deactivate and reactivate the plugin.']);
            return;
        }

        $filename = 'pasted_' . sanitize_file_name($title) . '_' . uniqid() . '.txt';

        $result = $wpdb->insert($table, [
            'title'       => $title,
            'filename'    => $filename,
            'file_path'   => '',
            'file_type'   => 'text',
            'content'     => $content,
            'status'      => 'active',
            'uploaded_by' => get_current_user_id(),
        ]);

        if ($result === false) {
            Chatzio_Logger::log_error('Paste resource DB insert failed', [
                'error' => $wpdb->last_error,
                'title' => $title,
            ]);
            wp_send_json_error(['message' => 'Failed to save resource. Please try again.']);
            return;
        }

        Chatzio_Logger::log_info('Resource pasted', [
            'title'          => $title,
            'content_length' => strlen($content)
        ]);

        $resource_id = (int) $wpdb->insert_id;
        if (class_exists('Chatzio_Embeddings') && $resource_id > 0) {
            Chatzio_Embeddings::sync_chunks_for_resource($resource_id);
        }

        wp_send_json_success([
            'resource_id'    => $resource_id,
            'content_length' => strlen($content),
            'message'        => 'Resource saved successfully'
        ]);
    }

    public static function handle_update_resource() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $resource_id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;
        $title       = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $content     = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';

        if ($resource_id <= 0) {
            wp_send_json_error(['message' => 'Invalid resource ID']);
            return;
        }
        if (empty($title)) {
            wp_send_json_error(['message' => 'Title is required']);
            return;
        }
        if (empty($content)) {
            wp_send_json_error(['message' => 'Content is required']);
            return;
        }

        if (Chatzio_Resource_Manager::update_resource($resource_id, $title, $content)) {
            // Re-sync embeddings for updated content
            if (class_exists('Chatzio_Embeddings')) {
                global $wpdb;
                $chunks_table = $wpdb->prefix . 'chatzio_chunks';
                if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $chunks_table)) === $chunks_table) {
                    $wpdb->delete($chunks_table, ['source_type' => 'resource', 'source_id' => $resource_id], ['%s', '%d']);
                }
                Chatzio_Embeddings::sync_chunks_for_resource($resource_id);
            }
            wp_send_json_success(['message' => 'Resource updated successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to update resource']);
        }
    }

    public static function handle_diagnose_knowledge() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        global $wpdb;
        $diagnosis = [];

        // Check resources table
        $resources_table = $wpdb->prefix . 'chatzio_resources';
        $resources_exists = $wpdb->get_var("SHOW TABLES LIKE '$resources_table'");
        $diagnosis['resources_table_exists'] = (bool) $resources_exists;

        if ($resources_exists) {
            $resources = $wpdb->get_results("SELECT id, title, file_type, status, LENGTH(content) as content_length, LEFT(content, 500) as content_preview FROM $resources_table", ARRAY_A);
            $diagnosis['resources']       = $resources;
            $diagnosis['resources_count'] = count($resources);
        }

        // Check content table
        $content_table = $wpdb->prefix . 'chatzio_content';
        $content_exists = $wpdb->get_var("SHOW TABLES LIKE '$content_table'");
        $diagnosis['content_table_exists'] = (bool) $content_exists;

        if ($content_exists) {
            $content_stats = $wpdb->get_results("SELECT content_type, COUNT(*) as count FROM $content_table GROUP BY content_type", ARRAY_A);
            $diagnosis['content_stats'] = $content_stats;
            $diagnosis['total_content'] = $wpdb->get_var("SELECT COUNT(*) FROM $content_table");
        }

        // Check FULLTEXT index
        $diagnosis['fulltext_enabled'] = Chatzio_Database::has_fulltext_index();

        // Test context building
        $test_context = self::build_context('test query about products and services');
        $diagnosis['sample_context'] = [
            'posts_count'     => count($test_context['posts'] ?? []),
            'pages_count'     => count($test_context['pages'] ?? []),
            'products_count'  => count($test_context['products'] ?? []),
            'resources_count' => count($test_context['resources'] ?? []),
            'resource_titles' => array_map(function ($r) { return $r['title']; }, $test_context['resources'] ?? []),
            'chunks_count'    => count($test_context['chunks'] ?? []),
        ];
        if (class_exists('Chatzio_Embeddings')) {
            $diagnosis['chunk_stats'] = Chatzio_Embeddings::get_chunk_stats();
        }

        wp_send_json_success($diagnosis);
    }

    // =========================================================================
    // Analytics Event Tracking
    // =========================================================================

    public static function handle_track_event() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $event_data = isset($_POST['event_data']) ? sanitize_text_field($_POST['event_data']) : '';
        $page_url   = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';

        $allowed_events = ['widget_open', 'message_sent', 'product_clicked', 'feedback_given', 'conversation_started', 'lead_captured'];
        if (!in_array($event_type, $allowed_events, true)) {
            wp_send_json_error(['message' => 'Invalid event type']);
            return;
        }

        // Lightweight rate limit: max 30 events/min per IP to prevent analytics spam
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $evt_key = 'sc_evt_' . md5($ip);
        $evt_count = (int) get_transient($evt_key);
        if ($evt_count >= 30) {
            wp_send_json_error(['message' => 'Too many requests']);
            return;
        }
        set_transient($evt_key, $evt_count + 1, MINUTE_IN_SECONDS);

        self::handle_track_event_internal($event_type, $session_id, [
            'data'     => $event_data,
            'page_url' => $page_url,
        ]);

        wp_send_json_success();
    }

    /**
     * Internal analytics tracking (called from other classes)
     */
    public static function handle_track_event_internal($event_type, $session_id, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_analytics';

        $wpdb->insert($table, [
            'session_id' => $session_id,
            'event_type' => $event_type,
            'event_data' => is_array($data) ? json_encode($data) : $data,
            'page_url'   => isset($data['page_url']) ? $data['page_url'] : '',
        ]);
    }

    // =========================================================================
    // Conversation Export (CSV)
    // =========================================================================

    public static function handle_export_conversations() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_die('Invalid security token');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';
        $leads_table = $wpdb->prefix . 'chatzio_leads';

        // Get all exchanges grouped by session
        $rows = $wpdb->get_results(
            "SELECT c.session_id, c.user_message, c.bot_response, c.bot_response_raw, 
                    c.created_at, l.email as lead_email, l.name as lead_name, c.lead_id
             FROM $table c
             LEFT JOIN $leads_table l ON c.lead_id = l.id
             ORDER BY c.session_id, c.created_at ASC, c.id ASC
             LIMIT 50000",
            ARRAY_A
        );

        // Group by session
        $sessions = [];
        foreach ($rows as $row) {
            $sid = $row['session_id'];
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'lead_name' => $row['lead_name'] ?? '',
                    'lead_email' => $row['lead_email'] ?? '',
                    'date' => $row['created_at'],
                    'messages' => [],
                ];
            }
            if (!empty($row['user_message'])) {
                $sessions[$sid]['messages'][] = '[User]: ' . strip_tags($row['user_message']);
            }
            if (!empty($row['bot_response'])) {
                $bot = !empty($row['bot_response_raw']) ? $row['bot_response_raw'] : strip_tags($row['bot_response']);
                $sessions[$sid]['messages'][] = '[Bot]: ' . strip_tags($bot);
            }
            // Update date to latest
            $sessions[$sid]['date'] = $row['created_at'];
        }

        // Filter out sessions with 0 user messages
        $sessions = array_filter($sessions, function($s) {
            foreach ($s['messages'] as $m) {
                if (strpos($m, '[User]:') === 0) return true;
            }
            return false;
        });

        // Find max message count
        $maxMessages = 0;
        foreach ($sessions as $s) {
            $maxMessages = max($maxMessages, count($s['messages']));
        }

        // Build CSV headers
        $headers = ['Session ID', 'Lead Name', 'Lead Email', 'Date', 'Total Messages'];
        for ($i = 1; $i <= $maxMessages; $i++) {
            $headers[] = 'Message ' . $i;
        }

        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=chatzio-conversations-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        
        foreach ($sessions as $sid => $s) {
            $row = [$sid, $s['lead_name'], $s['lead_email'], $s['date'], count($s['messages'])];
            for ($i = 0; $i < $maxMessages; $i++) {
                $row[] = $s['messages'][$i] ?? '';
            }
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
    
    // =========================================================================
    // Export Logs CSV
    // =========================================================================

    public static function handle_export_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_die('Invalid security token');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';
        
        // Build query with filters
        $where_clauses = ['1=1'];
        $where_values = [];
        
        $type = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        if (!empty($type)) {
            $where_clauses[] = "log_type = %s";
            $where_values[] = $type;
        }
        if (!empty($search)) {
            $where_clauses[] = "message LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if (!empty($date_from)) {
            $where_clauses[] = "DATE(created_at) >= %s";
            $where_values[] = $date_from;
        }
        if (!empty($date_to)) {
            $where_clauses[] = "DATE(created_at) <= %s";
            $where_values[] = $date_to;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        if (!empty($where_values)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT 10000",
                ...$where_values
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT 10000",
                ARRAY_A
            );
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=chatzio-logs-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Type', 'Message', 'Context', 'Date']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['id'],
                $row['log_type'],
                $row['message'],
                $row['context'] ?? '',
                $row['created_at'],
            ]);
        }

        fclose($output);
        exit;
    }

    // =========================================================================
    // Analytics Data (for admin dashboard)
    // =========================================================================

    public static function handle_get_analytics() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        $days = max(1, min(365, $days));

        global $wpdb;
        $conv_table   = $wpdb->prefix . 'chatzio_conversations';
        $leads_table  = $wpdb->prefix . 'chatzio_leads';
        $analytics_table = $wpdb->prefix . 'chatzio_analytics';
        $failed_table = $wpdb->prefix . 'chatzio_failed_topics';
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        $analytics_table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $analytics_table)) === $analytics_table);
        $failed_table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $failed_table)) === $failed_table);

        // Conversations per day
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM $conv_table WHERE created_at >= %s
             GROUP BY DATE(created_at) ORDER BY date ASC",
            $cutoff
        ), ARRAY_A);

        // Unique sessions (conversations) and total message rows
        $total_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $conv_table WHERE created_at >= %s", $cutoff
        ));
        $total_message_rows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE created_at >= %s", $cutoff
        ));
        $total_conversations = (int) $total_sessions; // KPI "Total Conversations" = unique sessions

        // Avg messages per session (message rows per session; each row = one exchange)
        $avg_messages = $total_sessions > 0 ? round($total_message_rows / $total_sessions, 1) : 0;

        // Satisfaction (% thumbs up of all feedback)
        $thumbs_up = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE feedback = 'up' AND created_at >= %s", $cutoff
        ));
        $total_feedback = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE feedback IS NOT NULL AND feedback != '' AND created_at >= %s", $cutoff
        ));
        $satisfaction = $total_feedback > 0 ? round(($thumbs_up / $total_feedback) * 100) : 0;

        // Leads captured
        $total_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE created_at >= %s", $cutoff
        ));

        // Peak hours
        $peak_hours = $wpdb->get_results($wpdb->prepare(
            "SELECT HOUR(created_at) as hour, COUNT(*) as count
             FROM $conv_table WHERE created_at >= %s
             GROUP BY HOUR(created_at) ORDER BY hour ASC",
            $cutoff
        ), ARRAY_A);

        // Top questions (word frequency from messages JSON)
        $messages_json = $wpdb->get_col($wpdb->prepare(
            "SELECT messages FROM $conv_table WHERE created_at >= %s LIMIT 300", $cutoff
        ));
        
        $user_messages = [];
        foreach ($messages_json as $json) {
            $msgs = json_decode((string) ($json ?? ''), true);
            if (is_array($msgs)) {
                foreach ($msgs as $msg) {
                    if (isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                        $user_messages[] = $msg['content'];
                    }
                }
            }
        }
        $top_words = self::extract_top_words($user_messages, 15);
        
        // Peak day (most conversations)
        $peak_day_data = $wpdb->get_row($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM $conv_table WHERE created_at >= %s
             GROUP BY DATE(created_at) ORDER BY count DESC LIMIT 1",
            $cutoff
        ), ARRAY_A);
        $peak_day = $peak_day_data ? date('D, M j', strtotime($peak_day_data['date'])) : '--';
        
        // Trend (compare to previous period — session counts)
        $prev_cutoff = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        $prev_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $conv_table WHERE created_at >= %s AND created_at < %s",
            $prev_cutoff, $cutoff
        ));
        $trend_percent = $prev_sessions > 0 ? round((($total_conversations - $prev_sessions) / $prev_sessions) * 100) : 0;
        
        // Engaged sessions (sessions with 2+ user messages across all rows for that session)
        $engaged_sessions = 0;
        $session_msg_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, messages FROM $conv_table WHERE created_at >= %s",
            $cutoff
        ), ARRAY_A);
        $user_count_by_session = [];
        foreach ($session_msg_counts as $row) {
            $sid = $row['session_id'];
            if (!isset($user_count_by_session[$sid])) {
                $user_count_by_session[$sid] = 0;
            }
            $msgs = json_decode((string) ($row['messages'] ?? ''), true);
            if (is_array($msgs)) {
                foreach ($msgs as $msg) {
                    if (isset($msg['role']) && $msg['role'] === 'user') {
                        $user_count_by_session[$sid]++;
                    }
                }
            }
        }
        foreach ($user_count_by_session as $count) {
            if ($count >= 2) {
                $engaged_sessions++;
            }
        }
        
        // Failed topics from detection system (only if table exists)
        $failed_topics = [];
        if ($failed_table_exists && class_exists('Chatzio_Failed_Topics')) {
            $top_queries = Chatzio_Failed_Topics::get_top_queries($days, 10);
            foreach ($top_queries as $q) {
                $failed_topics[] = $q['query'];
            }
        }

        // NEW DATA FOR ENHANCED ANALYTICS

        // Daily unique sessions (for conversion rate sparkline)
        $daily_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(DISTINCT session_id) as count
             FROM $conv_table WHERE created_at >= %s
             GROUP BY DATE(created_at) ORDER BY date ASC",
            $cutoff
        ), ARRAY_A);
        
        // Daily leads (for dual-axis chart)
        $daily_leads = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM $leads_table WHERE created_at >= %s
             GROUP BY DATE(created_at) ORDER BY date ASC",
            $cutoff
        ), ARRAY_A);
        
        // Heatmap: day-of-week x hour
        $heatmap_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DAYOFWEEK(created_at) as dow, HOUR(created_at) as hour, COUNT(*) as count
             FROM $conv_table WHERE created_at >= %s
             GROUP BY dow, hour",
            $cutoff
        ), ARRAY_A);
        
        // Top pages where users chat (only if analytics table exists)
        $top_pages = [];
        if ($analytics_table_exists) {
            $top_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, COUNT(*) as count
             FROM $analytics_table
             WHERE created_at >= %s AND event_type = 'message_sent' AND page_url IS NOT NULL
             GROUP BY page_url ORDER BY count DESC LIMIT 10",
            $cutoff
        ), ARRAY_A);
        }
        
        // Product mentions and clicks
        $content_table = $wpdb->prefix . 'chatzio_content';
        $products_mentioned = $wpdb->get_col($wpdb->prepare(
            "SELECT products_mentioned FROM $conv_table
             WHERE created_at >= %s AND products_mentioned IS NOT NULL AND products_mentioned != ''",
            $cutoff
        ));
        
        $product_counts = [];
        foreach ($products_mentioned as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids)) {
                foreach ($ids as $pid) {
                    if (!isset($product_counts[$pid])) {
                        $product_counts[$pid] = 0;
                    }
                    $product_counts[$pid]++;
                }
            }
        }
        
        arsort($product_counts);
        $top_products = [];
        $count = 0;
        foreach ($product_counts as $pid => $mentions) {
            if ($count >= 10) break;
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT title FROM $content_table WHERE content_id = %d AND content_type = 'product'",
                $pid
            ), ARRAY_A);
            if ($product) {
                $top_products[] = [
                    'id' => $pid,
                    'name' => $product['title'],
                    'mentions' => $mentions
                ];
            }
            $count++;
        }
        
        // Product clicks (only if analytics table exists)
        $product_clicks = [];
        if ($analytics_table_exists) {
            $product_clicks = $wpdb->get_results($wpdb->prepare(
                "SELECT event_data, COUNT(*) as count
                 FROM $analytics_table
                 WHERE created_at >= %s AND event_type = 'product_clicked'
                 GROUP BY event_data",
                $cutoff
            ), ARRAY_A);
        }
        
        // Feedback breakdown (up/down/none)
        $thumbs_down = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE feedback = 'down' AND created_at >= %s", $cutoff
        ));
        $no_feedback = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE (feedback IS NULL OR feedback = '') AND created_at >= %s", $cutoff
        ));
        $feedback_breakdown = [
            'up' => (int) $thumbs_up,
            'down' => (int) $thumbs_down,
            'none' => (int) $no_feedback
        ];
        
        // Failed topics by category (only if table exists)
        $failed_by_category = [];
        $top_failed_queries = [];
        if ($failed_table_exists) {
            $failed_by_category = $wpdb->get_results($wpdb->prepare(
                "SELECT topic_category, COUNT(*) as count
                 FROM $failed_table
                 WHERE created_at >= %s AND resolved = 0
                 GROUP BY topic_category ORDER BY count DESC",
                $cutoff
            ), ARRAY_A);
            $top_failed_queries = $wpdb->get_results($wpdb->prepare(
                "SELECT user_query, confidence_score, topic_category
                 FROM $failed_table
                 WHERE created_at >= %s AND resolved = 0
                 ORDER BY created_at DESC LIMIT 5",
                $cutoff
            ), ARRAY_A);
        }
        
        // Funnel data: widget_opens from analytics; conversations = same as top KPI (unique sessions)
        $widget_opens = 0;
        if ($analytics_table_exists) {
            $widget_opens = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $analytics_table WHERE event_type = 'widget_open' AND created_at >= %s", $cutoff
            ));
        }
        $widget_opens = (int) $widget_opens;
        // Funnel leads = all leads (pre-chat form happens before conversation)
        $leads_captured = (int) $total_leads;
        // Funnel conversations = only sessions from leads that actually chatted
        $conversations_started = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT c.session_id) FROM $conv_table c
             WHERE c.created_at >= %s
               AND c.session_id IN (SELECT session_id FROM $leads_table WHERE created_at >= %s)",
            $cutoff, $cutoff
        ));
        $leads_converted = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE status = 'converted' AND created_at >= %s", $cutoff
        ));
        
        $funnel = [
            'widget_opens' => (int) $widget_opens,
            'conversations_started' => $conversations_started,
            'leads_captured' => $leads_captured,
            'leads_converted' => (int) $leads_converted
        ];
        
        // Recent leads (last 5)
        $recent_leads = $wpdb->get_results($wpdb->prepare(
            "SELECT name, email, phone, source, page_url, created_at
             FROM $leads_table
             WHERE created_at >= %s
             ORDER BY created_at DESC LIMIT 5",
            $cutoff
        ), ARRAY_A);
        
        // Intent distribution (calculate from conversations)
        $all_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, COUNT(*) as msg_count
             FROM $conv_table
             WHERE created_at >= %s
             GROUP BY session_id",
            $cutoff
        ), ARRAY_A);
        
        $intent_distribution = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($all_sessions as $session) {
            $score = min(100, $session['msg_count'] * 15); // Simple scoring: more messages = higher intent
            if ($score >= 70) {
                $intent_distribution['high']++;
            } elseif ($score >= 40) {
                $intent_distribution['medium']++;
            } else {
                $intent_distribution['low']++;
            }
        }
        
        // Lead conversion rate
        $lead_conversion_rate = $total_sessions > 0 ? round(($total_leads / $total_sessions) * 100, 1) : 0;

        wp_send_json_success([
            'daily'              => $daily,
            'total_conversations' => (int) $total_conversations,
            'total_sessions'     => (int) $total_sessions,
            'avg_messages'       => $avg_messages,
            'satisfaction'       => $satisfaction,
            'total_feedback'     => (int) $total_feedback,
            'total_leads'        => (int) $total_leads,
            'peak_hours'         => $peak_hours,
            'top_words'          => $top_words,
            'peak_day'           => $peak_day,
            'trend_percent'      => $trend_percent,
            'engaged_sessions'   => $engaged_sessions,
            'failed_topics'      => $failed_topics,
            // NEW DATA
            'daily_sessions'    => $daily_sessions,
            'daily_leads'        => $daily_leads,
            'heatmap'            => $heatmap_data,
            'top_pages'          => $top_pages,
            'top_products'       => $top_products,
            'product_clicks'     => $product_clicks,
            'feedback_breakdown' => $feedback_breakdown,
            'failed_by_category' => $failed_by_category,
            'top_failed_queries' => $top_failed_queries,
            'funnel'             => $funnel,
            'recent_leads'       => $recent_leads,
            'intent_distribution' => $intent_distribution,
            'lead_conversion_rate' => $lead_conversion_rate,
        ]);
    }

    /**
     * Extract top keywords & phrases from messages (for analytics)
     *
     * - Comprehensive stop-word list to filter conversational noise
     * - Extracts both single words and bigrams (2-word phrases)
     * - Prefers bigrams: if a bigram is common, its constituent words are down-weighted
     * - Filters pure numbers and very short words
     */
    private static function extract_top_words($messages, $limit = 15) {
        $stop_words = [
            // Articles / prepositions / conjunctions
            'a','an','the','and','or','but','in','on','at','to','for','of','with','by','from','into',
            'up','out','off','over','under','between','through','about','after','before','during',
            // Pronouns
            'i','me','my','mine','we','us','our','you','your','yours','he','him','his','she','her',
            'it','its','they','them','their','who','whom','whose','which','that','this','these','those',
            // Common verbs / auxiliaries
            'is','am','are','was','were','be','been','being','have','has','had','do','does','did',
            'will','would','shall','should','can','could','may','might','must','need','dare',
            'get','got','getting','let','make','made','say','said','tell','told','know','knew',
            'think','thought','see','saw','go','went','gone','come','came','take','took','give','gave',
            'want','like','just','also','too','very','really','quite','much','many','more','most',
            'some','any','all','each','every','both','few','own','same','other','another',
            // Question / relative words
            'what','whats','where','when','why','how','which','whom',
            // Conversational filler
            'hi','hello','hey','howdy','please','thanks','thank','okay','ok','yes','no','yeah','yep',
            'nope','sure','right','well','now','then','here','there','still','already','yet',
            // Chat-specific noise
            'help','need','want','looking','check','find','show','tell','ask','question','answer',
            'thing','things','something','anything','nothing','everything','lot','bit','kind','type',
            'way','time','one','two','three','new','good','great','best','first','last','next',
            'try','use','using','used','able','able','available','currently','actually','basically',
            'maybe','perhaps','probably','definitely','seems','looks','feel','believe',
            'doesnt','dont','didnt','cant','wont','isnt','arent','wasnt','werent','havent','hasnt',
            'not','only','even','back','again','around','always','never','should','might',
            'gonna','wanna','gotta','lemme','cause','cos','cuz','btw','pls','plz','thx','thnx',
        ];
        $stop_set = array_flip($stop_words);

        // Clean each message into an array of words
        $all_words_per_msg = [];
        foreach ($messages as $msg) {
            $clean = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $msg));
            $words = array_values(array_filter(preg_split('/\s+/', $clean), function ($w) use ($stop_set) {
                return strlen($w) >= 3 && !isset($stop_set[$w]) && !preg_match('/^\d+$/', $w);
            }));
            if (!empty($words)) {
                $all_words_per_msg[] = $words;
            }
        }

        // Count unigrams
        $uni = [];
        foreach ($all_words_per_msg as $words) {
            $seen = [];
            foreach ($words as $w) {
                if (!isset($seen[$w])) {
                    $uni[$w] = ($uni[$w] ?? 0) + 1;
                    $seen[$w] = true;
                }
            }
        }

        // Count bigrams (only if both words passed filtering)
        $bi = [];
        foreach ($all_words_per_msg as $words) {
            $seen = [];
            for ($i = 0, $len = count($words); $i < $len - 1; $i++) {
                $phrase = $words[$i] . ' ' . $words[$i + 1];
                if (!isset($seen[$phrase])) {
                    $bi[$phrase] = ($bi[$phrase] ?? 0) + 1;
                    $seen[$phrase] = true;
                }
            }
        }

        // Keep bigrams that appear at least twice
        $bi = array_filter($bi, function ($c) { return $c >= 2; });

        // Down-weight unigrams that are covered by a strong bigram
        foreach ($bi as $phrase => $count) {
            $parts = explode(' ', $phrase);
            foreach ($parts as $part) {
                if (isset($uni[$part])) {
                    $uni[$part] = max(0, $uni[$part] - $count);
                }
            }
        }

        // Merge: bigrams first, then remaining unigrams (min count 1)
        $merged = [];
        foreach ($bi as $phrase => $count) {
            $merged[$phrase] = $count;
        }
        foreach ($uni as $word => $count) {
            if ($count >= 1) {
                $merged[$word] = $count;
            }
        }

        arsort($merged);
        return array_slice($merged, 0, $limit, true);
    }

    // =========================================================================
    // FAQ Data (for frontend FAQ tab) - v2 with manual FAQs
    // =========================================================================

    public static function handle_get_faq() {
        // Verify nonce (public endpoint)
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        // Check cache
        $cached = get_transient('chatzio_faq_data_v2');
        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }

        $result = [
            'faq_items' => [],
            'categories' => [],
            'product_categories' => [],
        ];

        // 1. Get manual FAQ items from settings
        $settings = get_option('chatzio_settings', []);
        $manual_faqs = isset($settings['faq_items']) && is_array($settings['faq_items']) ? $settings['faq_items'] : [];
        
        $categories_set = [];
        foreach ($manual_faqs as $faq) {
            if (empty($faq['question'])) continue;
            
            $result['faq_items'][] = [
                'question' => $faq['question'],
                'answer'   => $faq['answer'] ?? '',
                'category' => $faq['category'] ?? '',
                'type'     => 'manual',
            ];
            
            if (!empty($faq['category'])) {
                $categories_set[$faq['category']] = true;
            }
        }
        $result['categories'] = array_keys($categories_set);

        // 2. Get WooCommerce product categories (only when Products tab source is "category")
        $product_control_settings = get_option('chatzio_settings', []);
        $products_tab_source = isset($product_control_settings['products_tab_source']) ? $product_control_settings['products_tab_source'] : 'category';
        $allowed_category_ids = isset($product_control_settings['products_categories']) && is_array($product_control_settings['products_categories']) ? array_map('intval', $product_control_settings['products_categories']) : [];
        $show_category_filter = false;
        if (class_exists('WooCommerce') && $products_tab_source === 'category') {
            $args = [
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'number'     => 12,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ];
            if (!empty($allowed_category_ids)) {
                $args['include'] = $allowed_category_ids;
            }
            $product_cats = get_terms($args);
            if (!is_wp_error($product_cats)) {
                foreach ($product_cats as $cat) {
                    $result['product_categories'][] = [
                        'id'    => $cat->term_id,
                        'name'  => $cat->name,
                        'slug'  => $cat->slug,
                        'count' => $cat->count,
                    ];
                }
            }
            // Show category slider only when showing all products (no filter) or multiple categories
            $show_category_filter = empty($allowed_category_ids) || count($allowed_category_ids) > 1;
        }
        $result['products_tab_source'] = $products_tab_source;
        $result['show_category_filter'] = $show_category_filter;

        // Cache for 30 minutes
        set_transient('chatzio_faq_data_v2', $result, 30 * MINUTE_IN_SECONDS);

        wp_send_json_success($result);
    }

    /** Max products to show in Products tab; show shop link when more exist */
    const PRODUCTS_TAB_MAX = 20;

    /**
     * Get products for Products tab (by source: category, featured, bestsellers, onsale, specific_products).
     * Returns max PRODUCTS_TAB_MAX items and shop_url for "View shop" when more exist.
     */
    public static function handle_get_products_by_category() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        $cat_id = isset($_POST['category_id']) ? $_POST['category_id'] : 0;
        $cat_id = $cat_id === 'all' || $cat_id === '' ? 0 : intval($cat_id);
        if (!class_exists('WooCommerce')) {
            wp_send_json_success(['products' => [], 'currency' => '$', 'shop_url' => '']);
            return;
        }

        $settings = get_option('chatzio_settings', []);
        $source = isset($settings['products_tab_source']) && in_array($settings['products_tab_source'], ['category', 'featured', 'bestsellers', 'onsale', 'specific_products'], true) ? $settings['products_tab_source'] : 'category';
        $allowed_categories = isset($settings['products_categories']) && is_array($settings['products_categories']) ? array_map('intval', $settings['products_categories']) : [];
        $highlighted_ids = isset($settings['products_highlight']) && is_array($settings['products_highlight']) ? array_map('intval', $settings['products_highlight']) : [];
        $featured_ids = isset($settings['products_featured']) && is_array($settings['products_featured']) ? array_map('intval', $settings['products_featured']) : [];

        $shop_page_id = wc_get_page_id('shop');
        $shop_url = $shop_page_id > 0 ? get_permalink($shop_page_id) : home_url('/shop');
        if (!is_string($shop_url)) {
            $shop_url = home_url('/shop');
        }

        $product_ids = [];
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';

        if ($source === 'category') {
            // By category: query chatzio_content; default all categories when none selected
            $where_clauses = ["content_type = 'product'"];
            $where_values = [];
            if ($cat_id > 0) {
                $term = get_term($cat_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    if (!empty($allowed_categories) && !in_array($cat_id, $allowed_categories, true)) {
                        wp_send_json_success(['products' => [], 'currency' => self::get_currency(), 'shop_url' => $shop_url]);
                        return;
                    }
                    $where_clauses[] = "metadata LIKE %s";
                    $where_values[] = '%' . $wpdb->esc_like($term->name) . '%';
                }
            } elseif (!empty($allowed_categories)) {
                $category_names = [];
                foreach ($allowed_categories as $allowed_cat_id) {
                    $term = get_term($allowed_cat_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $category_names[] = $term->name;
                    }
                }
                if (!empty($category_names)) {
                    $cat_conditions = [];
                    foreach ($category_names as $cat_name) {
                        $cat_conditions[] = "metadata LIKE %s";
                        $where_values[] = '%' . $wpdb->esc_like($cat_name) . '%';
                    }
                    $where_clauses[] = '(' . implode(' OR ', $cat_conditions) . ')';
                } else {
                    wp_send_json_success(['products' => [], 'currency' => self::get_currency(), 'shop_url' => $shop_url]);
                    return;
                }
            }
            $where_sql = implode(' AND ', $where_clauses);
            $query = "SELECT content_id FROM $table WHERE $where_sql ORDER BY title ASC LIMIT " . (self::PRODUCTS_TAB_MAX + 1);
            if (!empty($where_values)) {
                $rows = $wpdb->get_results($wpdb->prepare($query, ...$where_values), ARRAY_A);
            } else {
                $rows = $wpdb->get_results($query, ARRAY_A);
            }
            foreach ($rows as $r) {
                $product_ids[] = (int) $r['content_id'];
            }
        } elseif ($source === 'featured' && !empty($featured_ids)) {
            $product_ids = array_slice(array_map('intval', $featured_ids), 0, self::PRODUCTS_TAB_MAX + 1);
        } elseif ($source === 'specific_products' && !empty($highlighted_ids)) {
            $product_ids = array_slice(array_map('intval', $highlighted_ids), 0, self::PRODUCTS_TAB_MAX + 1);
        } elseif ($source === 'bestsellers' || $source === 'onsale') {
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => self::PRODUCTS_TAB_MAX + 1,
                'fields'         => 'ids',
            ];
            if ($source === 'bestsellers') {
                $args['meta_key'] = 'total_sales';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
            } else {
                $args['post__in'] = wc_get_product_ids_on_sale();
                if (empty($args['post__in'])) {
                    $args['post__in'] = [0];
                }
            }
            $q = new \WP_Query($args);
            $product_ids = $q->posts ? array_map('intval', $q->posts) : [];
        }

        $has_more = count($product_ids) > self::PRODUCTS_TAB_MAX;
        $product_ids = array_slice($product_ids, 0, self::PRODUCTS_TAB_MAX);

        $formatted = self::format_products_for_tab($product_ids, $highlighted_ids);
        wp_send_json_success([
            'products'  => $formatted,
            'currency'  => self::get_currency(),
            'shop_url'  => $shop_url,
            'show_shop_link' => $has_more,
        ]);
    }

    private static function get_currency() {
        $raw = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        $currency = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $currency));
    }

    /**
     * Get formatted price range for variable products with currency in front (e.g. "৳ 10.00 – ৳ 20.00").
     * Returns empty string if not variable or no prices.
     */
    private static function get_variable_price_range($product) {
        if (!$product || !is_a($product, 'WC_Product') || $product->get_type() !== 'variable') {
            return '';
        }
        $min = $product->get_variation_price('min', true);
        $max = $product->get_variation_price('max', true);
        if ($min === '' || $max === '') {
            return '';
        }
        if (!function_exists('get_woocommerce_currency_symbol')) {
            return '';
        }
        $cur = get_woocommerce_currency_symbol();
        $cur = trim(html_entity_decode($cur, ENT_QUOTES, 'UTF-8'));
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $decimal_sep = function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.';
        $thousand_sep = function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : ',';
        $min_f = number_format((float) $min, $decimals, $decimal_sep, $thousand_sep);
        $max_f = number_format((float) $max, $decimals, $decimal_sep, $thousand_sep);
        $min_str = $cur . $min_f;
        $max_str = $cur . $max_f;
        return $min_f === $max_f ? $min_str : $min_str . ' – ' . $max_str;
    }

    /**
     * Format product IDs into tab response format (from chatzio_content when possible, else WC).
     */
    private static function format_products_for_tab($product_ids, $highlighted_ids = []) {
        if (empty($product_ids)) {
            return [];
        }
        $highlighted_ids = array_flip(array_map('intval', (array) $highlighted_ids));
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT content_id, title, url, metadata FROM $table WHERE content_type = 'product' AND content_id IN ($placeholders)",
            $product_ids
        ), ARRAY_A);
        $by_id = [];
        foreach ($rows as $r) {
            $by_id[(int) $r['content_id']] = $r;
        }
        $formatted = [];
        foreach ($product_ids as $pid) {
            $p = isset($by_id[$pid]) ? $by_id[$pid] : null;
            if ($p) {
                $meta = json_decode($p['metadata'] ?? '{}', true);
                $regular = isset($meta['regular_price']) ? trim(wp_strip_all_tags(html_entity_decode($meta['regular_price'], ENT_QUOTES, 'UTF-8'))) : '';
                $sale = isset($meta['sale_price']) ? trim(wp_strip_all_tags(html_entity_decode($meta['sale_price'], ENT_QUOTES, 'UTF-8'))) : '';
                $image = isset($meta['image']) && $meta['image'] !== '' ? $meta['image'] : self::get_product_image_url_by_content_id($pid);
                $product = wc_get_product($pid);
                $product_type = ($product && is_a($product, 'WC_Product')) ? $product->get_type() : (isset($meta['type']) ? $meta['type'] : 'simple');
                if ($product_type === 'variable' && $product && is_a($product, 'WC_Product')) {
                    $range = self::get_variable_price_range($product);
                    if ($range !== '') {
                        $regular = $range;
                        $sale = '';
                    }
                }
                $formatted[] = [
                    'id'              => $pid,
                    'title'           => $p['title'],
                    'url'             => $p['url'],
                    'regular_price'   => $regular,
                    'sale_price'      => $sale,
                    'image'           => $image,
                    'stock_status'    => isset($meta['stock_status']) ? $meta['stock_status'] : 'instock',
                    'product_type'    => $product_type,
                    'highlighted'     => isset($highlighted_ids[$pid]),
                    'price_formatted' => $product_type === 'variable' && $regular !== '',
                ];
            } else {
                $product = wc_get_product($pid);
                if ($product && is_a($product, 'WC_Product')) {
                    $product_type = $product->get_type();
                    $regular = $product->get_regular_price();
                    $sale = $product->get_sale_price();
                    if ($product_type === 'variable') {
                        $range = self::get_variable_price_range($product);
                        $regular = $range !== '' ? $range : $regular;
                        $sale = '';
                    }
                    $formatted[] = [
                        'id'              => $pid,
                        'title'           => $product->get_name(),
                        'url'             => get_permalink($pid),
                        'regular_price'   => $regular,
                        'sale_price'      => $sale,
                        'image'           => self::get_product_image_url_by_content_id($pid),
                        'stock_status'    => $product->is_in_stock() ? 'instock' : 'outofstock',
                        'product_type'   => $product_type,
                        'highlighted'     => isset($highlighted_ids[$pid]),
                        'price_formatted' => $product_type === 'variable' && $regular !== '',
                    ];
                }
            }
        }
        return $formatted;
    }

    /**
     * Get featured products by IDs (for Home tab)
     */
    public static function handle_get_featured_products() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_success(['products' => [], 'currency' => '$']);
            return;
        }

        $product_ids_json = isset($_POST['product_ids']) ? $_POST['product_ids'] : '[]';
        $product_ids = json_decode(stripslashes($product_ids_json), true);
        if (!is_array($product_ids) || empty($product_ids)) {
            wp_send_json_success(['products' => [], 'currency' => '$']);
            return;
        }

        // Sanitize IDs
        $product_ids = array_map('intval', $product_ids);
        $product_ids = array_filter($product_ids, function($id) { return $id > 0; });
        if (empty($product_ids)) {
            wp_send_json_success(['products' => [], 'currency' => '$']);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT content_id, title, url, metadata FROM $table 
             WHERE content_type = 'product' 
             AND content_id IN ($placeholders)",
            $product_ids
        ), ARRAY_A);
        
        // Sort products to maintain the order specified in $product_ids
        $products_by_id = [];
        foreach ($products as $p) {
            $products_by_id[(int) $p['content_id']] = $p;
        }
        $products = [];
        foreach ($product_ids as $pid) {
            if (isset($products_by_id[$pid])) {
                $products[] = $products_by_id[$pid];
            }
        }

        $formatted = [];
        $raw_currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        $currency = html_entity_decode($raw_currency, ENT_QUOTES, 'UTF-8');
        $currency = trim(preg_replace('/\s+/', ' ', $currency));

        foreach ($products as $p) {
            $meta = json_decode($p['metadata'] ?? '{}', true);
            $regular = $meta['regular_price'] ?? '';
            $sale    = $meta['sale_price'] ?? '';
            $regular = is_string($regular) ? trim(wp_strip_all_tags(html_entity_decode($regular, ENT_QUOTES, 'UTF-8'))) : $regular;
            $sale    = is_string($sale) ? trim(wp_strip_all_tags(html_entity_decode($sale, ENT_QUOTES, 'UTF-8'))) : $sale;
            $image = $meta['image'] ?? '';
            if ($image === '') {
                $image = self::get_product_image_url_by_content_id($p['content_id'] ?? 0);
            }
            $product = wc_get_product($p['content_id'] ?? 0);
            $product_type = ($product && is_a($product, 'WC_Product')) ? $product->get_type() : (isset($meta['type']) ? $meta['type'] : 'simple');
            if ($product_type === 'variable' && $product && is_a($product, 'WC_Product')) {
                $range = self::get_variable_price_range($product);
                if ($range !== '') {
                    $regular = $range;
                    $sale = '';
                }
            }
            $formatted[] = [
                'id'              => isset($p['content_id']) ? (int) $p['content_id'] : 0,
                'title'           => $p['title'],
                'url'             => $p['url'],
                'regular_price'   => $regular,
                'sale_price'      => $sale,
                'image'           => $image,
                'stock_status'    => $meta['stock_status'] ?? 'instock',
                'product_type'    => $product_type,
                'price_formatted' => $product_type === 'variable' && $regular !== '',
            ];
        }

        wp_send_json_success(['products' => $formatted, 'currency' => $currency]);
    }

    /**
     * Add simple product to cart via AJAX (used by product cards in chat)
     */
    public static function handle_add_to_cart() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }
        if (!class_exists('WooCommerce') || !WC()->cart) {
            wp_send_json_error(['message' => 'WooCommerce not active.']);
            return;
        }
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($product_id < 1) {
            wp_send_json_error(['message' => 'Invalid product.']);
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product || !is_a($product, 'WC_Product')) {
            wp_send_json_error(['message' => 'Product not found.']);
            return;
        }
        if ($product->get_type() !== 'simple') {
            wp_send_json_error(['message' => 'Variable products must be added from the product page.']);
            return;
        }
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            wp_send_json_error(['message' => 'Product is not available.']);
            return;
        }
        // Ensure WooCommerce session exists (required for AJAX add-to-cart to persist)
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1);
        if (is_wp_error($cart_item_key)) {
            wp_send_json_error(['message' => $cart_item_key->get_error_message()]);
            return;
        }
        // Persist cart to session (required for guest users and some hosting setups)
        if (WC()->session) {
            WC()->cart->set_session();
        }
        WC()->cart->maybe_set_cart_cookies();
        wp_send_json_success([
            'message'   => 'Added to cart',
            'cart_url'  => wc_get_cart_url(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
        ]);
    }
    
    /**
     * Get full conversation thread for a session
     */
    public static function handle_get_conversation_thread() {
        check_ajax_referer('chatzio_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        if (empty($session_id)) {
            wp_send_json_error(['message' => 'Session ID required']);
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';
        
        // Get all conversation exchanges for this session, ordered chronologically
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_message, bot_response, bot_response_raw, created_at FROM $table WHERE session_id = %s ORDER BY created_at ASC, id ASC",
            $session_id
        ), ARRAY_A);
        
        // Build thread as array of messages
        $thread = [];
        foreach ($results as $row) {
            if (!empty($row['user_message'])) {
                $thread[] = [
                    'role' => 'user',
                    'content' => wp_strip_all_tags($row['user_message']),
                    'timestamp' => $row['created_at']
                ];
            }
            if (!empty($row['bot_response'])) {
                // Prefer raw response (no HTML), fallback to stripped bot_response
                $bot_text = !empty($row['bot_response_raw']) ? $row['bot_response_raw'] : wp_strip_all_tags($row['bot_response']);
                $thread[] = [
                    'role' => 'assistant',
                    'content' => $bot_text,
                    'timestamp' => $row['created_at']
                ];
            }
        }
        
        wp_send_json_success([
            'thread' => $thread,
            'total_messages' => count($thread)
        ]);
    }
    
    /**
     * Generate dummy data for testing/demonstration purposes
     */
    public static function handle_generate_dummy_data() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        
        global $wpdb;
        
        try {
            $conv_table = $wpdb->prefix . 'chatzio_conversations';
            $leads_table = $wpdb->prefix . 'chatzio_leads';
            $analytics_table = $wpdb->prefix . 'chatzio_analytics';
            $failed_table = $wpdb->prefix . 'chatzio_failed_topics';
            
            // Sample data arrays
            $sample_messages = [
                'user' => [
                    'What are your shipping options?',
                    'Do you have any discounts?',
                    'Tell me about your return policy',
                    'What products do you recommend?',
                    'How do I track my order?',
                    'What payment methods do you accept?',
                    'Can I cancel my order?',
                    'Do you ship internationally?',
                    'What is your refund policy?',
                    'How long does shipping take?',
                    'Do you have a physical store?',
                    'What are your business hours?',
                    'Can I get a sample?',
                    'What makes your products special?',
                    'Do you offer gift wrapping?',
                ],
                'bot' => [
                    'We offer standard shipping (5-7 business days) and express shipping (2-3 business days). Standard shipping is free on orders over $50.',
                    'Yes! We currently have a 15% off promotion for new customers. Use code WELCOME15 at checkout.',
                    'We offer a 30-day return policy. Items must be unused and in original packaging. Please contact us for a return authorization.',
                    'Based on your preferences, I\'d recommend our premium collection. These products are our bestsellers and have excellent reviews.',
                    'Once your order ships, you\'ll receive a tracking number via email. You can also check your order status in your account.',
                    'We accept all major credit cards, PayPal, Apple Pay, and Google Pay. All transactions are secure.',
                    'Yes, you can cancel your order within 24 hours of placing it. After that, you\'ll need to wait for delivery and use our return process.',
                    'Yes, we ship to over 50 countries worldwide. International shipping rates vary by destination.',
                    'We offer full refunds within 30 days if items are unused and in original packaging. Refunds are processed within 5-7 business days.',
                    'Standard shipping takes 5-7 business days. Express shipping takes 2-3 business days. International shipping varies by location.',
                    'Yes, we have a flagship store in downtown. You can also shop online and pick up in-store for free.',
                    'Our store hours are Monday-Friday 9am-7pm, Saturday 10am-6pm, and Sunday 11am-5pm.',
                    'Yes, we offer samples for select products. Please visit our samples page or contact customer service.',
                    'Our products are handcrafted with premium materials and undergo rigorous quality testing. We also offer a satisfaction guarantee.',
                    'Yes, we offer complimentary gift wrapping for all orders. You can add this option at checkout.',
                ],
            ];
            
            $sample_names = ['John Smith', 'Sarah Johnson', 'Michael Brown', 'Emily Davis', 'David Wilson', 'Jessica Martinez', 'Robert Taylor', 'Amanda Anderson', 'James Thomas', 'Lisa Jackson'];
            $sample_emails = ['john.smith@example.com', 'sarah.j@example.com', 'mike.brown@example.com', 'emily.d@example.com', 'david.w@example.com', 'jessica.m@example.com', 'robert.t@example.com', 'amanda.a@example.com', 'james.t@example.com', 'lisa.j@example.com'];
            $sample_phones = ['+1-555-0101', '+1-555-0102', '+1-555-0103', '+1-555-0104', '+1-555-0105', '+1-555-0106', '+1-555-0107', '+1-555-0108', '+1-555-0109', '+1-555-0110'];
            $sample_pages = ['/shop', '/products', '/about', '/contact', '/blog', '/faq', '/home', '/cart', '/checkout', '/account'];
            $sample_statuses = ['new', 'contacted', 'qualified', 'converted'];
            $sample_feedback = ['positive', 'negative', null, null, null, null, null]; // Mostly null, some feedback
            
            $generated = [
                'conversations' => 0,
                'leads' => 0,
                'analytics' => 0,
                'failed_topics' => 0,
            ];
            
            // Generate conversations and leads (last 90 days)
            // Intent distribution: ~30% high (5+ msgs), ~40% medium (3-4 msgs), ~30% low (1-2 msgs)
            $sessions = [];
            $now = current_time('mysql');
            $days_back = 90;
            
            for ($day = 0; $day < $days_back; $day++) {
                $date = date('Y-m-d H:i:s', strtotime("-$day days"));
                $conversations_per_day = rand(5, 25); // 5-25 conversations per day
                
                for ($i = 0; $i < $conversations_per_day; $i++) {
                    $session_id = 'dummy_' . uniqid() . '_' . $day . '_' . $i;
                    $sessions[] = $session_id;
                    
                    $hour_offset = rand(0, 23);
                    $minute_offset = rand(0, 59);
                    $base_time = strtotime("-$day days $hour_offset:$minute_offset");
                    
                    // Determine message count for this session to create varied intent
                    // Intent is calculated as: score = msg_count * 15
                    // High (>=70): needs >=5 rows, Medium (>=40): needs >=3 rows, Low (<40): needs <3 rows
                    $rand_intent = rand(1, 100);
                    if ($rand_intent <= 30) {
                        // High intent: 5-8 conversation rows (30%)
                        $conversation_rows = rand(5, 8);
                    } elseif ($rand_intent <= 70) {
                        // Medium intent: 3-4 conversation rows (40%)
                        $conversation_rows = rand(3, 4);
                    } else {
                        // Low intent: 1-2 conversation rows (30%)
                        $conversation_rows = rand(1, 2);
                    }
                    
                    // Build full conversation messages array for the first row
                    $conversation_messages = [];
                    
                    // Create multiple conversation rows (each row = one message exchange)
                    for ($row = 0; $row < $conversation_rows; $row++) {
                        $time_offset = $row * rand(30, 300); // 30 seconds to 5 minutes between messages
                        $created_at = date('Y-m-d H:i:s', $base_time + $time_offset);
                        
                        $user_msg = $sample_messages['user'][array_rand($sample_messages['user'])];
                        $bot_msg = $sample_messages['bot'][array_rand($sample_messages['bot'])];
                        
                        // Add to full conversation array
                        $conversation_messages[] = ['role' => 'user', 'content' => $user_msg];
                        $conversation_messages[] = ['role' => 'assistant', 'content' => $bot_msg];
                        
                        // Add feedback only on the last message of high-intent sessions
                        $feedback = null;
                        if ($row === ($conversation_rows - 1) && $conversation_rows >= 5) {
                            $feedback = $sample_feedback[array_rand($sample_feedback)];
                        }
                        
                        // Insert conversation row
                        $wpdb->insert($conv_table, [
                            'session_id' => $session_id,
                            'user_id' => 0,
                            'user_message' => $user_msg,
                            'bot_response' => '<p>' . esc_html($bot_msg) . '</p>',
                            'bot_response_raw' => $bot_msg,
                            'messages' => json_encode([
                                ['role' => 'user', 'content' => $user_msg],
                                ['role' => 'assistant', 'content' => $bot_msg],
                            ]),
                            'feedback' => $feedback,
                            'created_at' => $created_at,
                        ]);
                        $generated['conversations']++;
                    }
                    
                    // Generate lead for ~30% of conversations
                    if (rand(1, 100) <= 30) {
                        $name_idx = array_rand($sample_names);
                        $email = str_replace('@example.com', '+' . $day . $i . '@example.com', $sample_emails[$name_idx]);
                        
                        $wpdb->insert($leads_table, [
                            'session_id' => $session_id,
                            'email' => $email,
                            'name' => $sample_names[$name_idx],
                            'phone' => rand(1, 100) <= 50 ? $sample_phones[$name_idx] : '',
                            'source' => rand(1, 100) <= 70 ? 'prechat' : 'inchat',
                            'page_url' => home_url($sample_pages[array_rand($sample_pages)]),
                            'conversation_count' => rand(1, 5),
                            'status' => $sample_statuses[array_rand($sample_statuses)],
                            'created_at' => $created_at,
                        ]);
                        $generated['leads']++;
                    }
                    
                    // Generate analytics events
                    $page_url = home_url($sample_pages[array_rand($sample_pages)]);
                    
                    // Widget open
                    $wpdb->insert($analytics_table, [
                        'session_id' => $session_id,
                        'event_type' => 'widget_open',
                        'event_data' => json_encode(['page_url' => $page_url]),
                        'page_url' => $page_url,
                        'created_at' => $created_at,
                    ]);
                    $generated['analytics']++;
                    
                    // Message sent
                    $wpdb->insert($analytics_table, [
                        'session_id' => $session_id,
                        'event_type' => 'message_sent',
                        'event_data' => json_encode(['page_url' => $page_url]),
                        'page_url' => $page_url,
                        'created_at' => $created_at,
                    ]);
                    $generated['analytics']++;
                    
                    // Some product clicks (20% chance)
                    if (rand(1, 100) <= 20) {
                        $wpdb->insert($analytics_table, [
                            'session_id' => $session_id,
                            'event_type' => 'product_clicked',
                            'event_data' => json_encode(['product_id' => rand(1, 50)]),
                            'page_url' => $page_url,
                            'created_at' => $created_at,
                        ]);
                        $generated['analytics']++;
                    }
                }
            }
            
            // Generate some failed topics (unresolved queries)
            $failed_queries = [
                'How do I reset my password?',
                'What is your tax ID number?',
                'Can I get a bulk discount?',
                'Do you have a loyalty program?',
                'What certifications do your products have?',
                'Can I customize my order?',
                'Do you offer installation services?',
                'What is your environmental policy?',
                'Can I get a trade discount?',
                'Do you have a mobile app?',
            ];
            
            for ($i = 0; $i < 15; $i++) {
                $day = rand(0, 30);
                $created_at = date('Y-m-d H:i:s', strtotime("-$day days"));
                $session_id = isset($sessions[array_rand($sessions)]) ? $sessions[array_rand($sessions)] : 'dummy_' . uniqid();
                
                $wpdb->insert($failed_table, [
                    'session_id' => $session_id,
                    'user_query' => $failed_queries[array_rand($failed_queries)],
                    'bot_response' => 'I apologize, but I don\'t have specific information about that. Let me connect you with our support team.',
                    'confidence_score' => round(rand(20, 50) / 100, 2),
                    'topic_category' => ['support', 'pricing', 'technical', 'general'][array_rand(['support', 'pricing', 'technical', 'general'])],
                    'resolved' => rand(1, 100) <= 30 ? 1 : 0, // 30% resolved
                    'created_at' => $created_at,
                ]);
                $generated['failed_topics']++;
            }
            
            wp_send_json_success([
                'message' => sprintf(
                    'Generated dummy data: %d conversations, %d leads, %d analytics events, %d failed topics',
                    $generated['conversations'],
                    $generated['leads'],
                    $generated['analytics'],
                    $generated['failed_topics']
                ),
            ]);
            
        } catch (Exception $e) {
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_error('Dummy data generation failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            wp_send_json_error([
                'message' => 'Generation failed: ' . $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Reset plugin to defaults - clears all data and resets settings
     */
    public static function handle_reset_plugin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        
        if (!check_ajax_referer('chatzio_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
        
        global $wpdb;
        
        try {
            // Preserve API key if it exists (don't reset it); get_option returns decrypted, re-encrypt for storage
            $current_settings = get_option('chatzio_settings', []);
            $preserved_api_key = isset($current_settings['openrouter_api_key']) ? $current_settings['openrouter_api_key'] : '';
            if ($preserved_api_key !== '' && !Chatzio_API_Key_Crypto::is_encrypted($preserved_api_key)) {
                $preserved_api_key = Chatzio_API_Key_Crypto::encrypt($preserved_api_key);
            }
            
            // Clear all data from tables (but don't drop tables)
            $tables = [
                $wpdb->prefix . 'chatzio_content',
                $wpdb->prefix . 'chatzio_resources',
                $wpdb->prefix . 'chatzio_chunks',
                $wpdb->prefix . 'chatzio_conversations',
                $wpdb->prefix . 'chatzio_logs',
                $wpdb->prefix . 'chatzio_leads',
                $wpdb->prefix . 'chatzio_analytics',
                $wpdb->prefix . 'chatzio_failed_topics',
            ];
            
            foreach ($tables as $table) {
                $wpdb->query("TRUNCATE TABLE `$table`");
            }
            
            // Reset settings to defaults (from activator)
            $default_settings = [
                'enabled' => true,
                'primary_color' => '#4F46E5',
                'secondary_color' => '#6366F1',
                'link_color' => '#2563EB',
                'bot_name' => 'Chatzio',
                'welcome_message' => 'Hi! How can I help you today?',
                'input_placeholder' => 'Type your message...',
                'logo_url' => '',
                'openrouter_api_key' => $preserved_api_key, // Preserve API key
                'openrouter_model' => 'openai/gpt-3.5-turbo',
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'auto_sync_enabled' => true,
                'sync_posts' => true,
                'sync_pages' => true,
                'sync_products' => true,
                'system_prompt' => '',
                'custom_prompt' => '',
                'restricted_topics' => '',
                'fallback_response' => "I'm not sure I can help with that specific question. Would you like me to connect you with our support team, or is there something else I can assist you with?",
                'generate_prompt_on_sync' => true,
                'synced_content_prompt' => '',
                'bubble_size' => '60',
                'widget_position' => 'bottom-right',
                'position_custom_desktop_bottom' => 24,
                'position_custom_desktop_right' => 24,
                'position_custom_mobile_bottom' => 20,
                'position_custom_mobile_right' => 20,
                'font_family' => 'system',
                'font_size' => '14',
                'enable_product_cards' => true,
                'quick_replies' => '',
                'proactive_message' => '',
                'proactive_delay' => 5,
                'enable_lead_form' => false,
                'lead_form_heading' => 'Before we start, tell us about yourself',
                'lead_form_subheading' => "We'd love to know who we're chatting with.",
                'lead_form_name' => true,
                'lead_form_phone' => false,
                'enable_smart_capture' => true,
                'widget_mode' => 'tabbed',
                'widget_tab_home' => true,
                'widget_tab_faq' => false,
                'widget_tab_products' => false,
                'widget_tab_history' => false,
                'widget_tab_news' => false,
                'widget_tab_style' => 'icons_labels',
                'home_headline' => 'Hi there 👋',
                'home_subtext' => 'How can we help you today?',
                'default_tab' => 'home',
                'tab_labels' => ['home' => 'Home', 'chat' => 'Chat', 'faq' => 'FAQ', 'products' => 'Products', 'history' => 'History', 'news' => 'News'],
                'tab_icons' => ['home' => 'home', 'chat' => 'chat', 'faq' => 'faq', 'products' => 'products', 'history' => 'history', 'news' => 'news'],
                'default_starter_title' => 'Ask a question',
                'default_starter_subtitle' => 'We typically reply in a few minutes',
                'default_starter_icon' => '👋',
                'conversation_starters' => [],
                'faq_items' => [],
                'news_items' => [],
                'debug_mode' => false,
                'hide_all_notices' => true,
                'reduce_top_gap' => false,
                'admin_custom_css' => '',
                'notification_email' => '',
                'notify_new_lead' => false,
                'notify_new_conversation' => false,
                'data_retention_days' => 0,
            ];
            
            update_option('chatzio_settings', $default_settings);
            update_option('chatzio_setup_complete', '0');
            delete_option('chatzio_last_sync');
            
            // Log the reset
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_info('Plugin reset to defaults', [
                    'user' => get_current_user_id(),
                ]);
            }
            
            wp_send_json_success([
                'message' => 'Plugin has been reset to defaults. All data has been cleared and settings restored.',
                'clear_browser_storage' => true, // Flag to clear localStorage/sessionStorage
            ]);
            
        } catch (Exception $e) {
            if (class_exists('Chatzio_Logger')) {
                Chatzio_Logger::log_error('Plugin reset failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            wp_send_json_error([
                'message' => 'Reset failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX: Subscribe to restock notification.
     */
    public static function handle_restock_subscribe() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $source     = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'inchat';

        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product.']);
            return;
        }

        // If no email provided, try to get from session (known user)
        if (empty($email) && !empty($session_id)) {
            $email = Chatzio_Restock::get_session_email($session_id);
        }

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'email_required']);
            return;
        }

        $result = Chatzio_Restock::subscribe($product_id, $email, $session_id, $source);

        if ($result['status'] === 'error') {
            wp_send_json_error(['message' => $result['message']]);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX: Check if the current session's user is known and whether they're already subscribed.
     */
    public static function handle_restock_check() {
        if (!self::verify_public_nonce()) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        $email = Chatzio_Restock::get_session_email($session_id);

        $response = [
            'known_user'          => !empty($email),
            'email'               => $email ? $email : null,
            'already_subscribed'  => false,
        ];

        if ($email && $product_id) {
            $response['already_subscribed'] = Chatzio_Restock::is_subscribed($product_id, $email);
        }

        wp_send_json_success($response);
    }
}

// Register admin AJAX handlers
add_action('wp_ajax_chatzio_sync_content', ['Chatzio_AJAX', 'handle_sync_content']);
add_action('wp_ajax_chatzio_reembed_chunks', ['Chatzio_AJAX', 'handle_reembed_chunks']);
add_action('wp_ajax_chatzio_upload_resource', ['Chatzio_AJAX', 'handle_upload_resource']);
add_action('wp_ajax_chatzio_paste_resource', ['Chatzio_AJAX', 'handle_paste_resource']);
add_action('wp_ajax_chatzio_delete_resource', ['Chatzio_AJAX', 'handle_delete_resource']);
add_action('wp_ajax_chatzio_update_resource', ['Chatzio_AJAX', 'handle_update_resource']);
add_action('wp_ajax_chatzio_test_api', ['Chatzio_AJAX', 'handle_test_api']);
add_action('wp_ajax_chatzio_save_settings', ['Chatzio_AJAX', 'handle_save_settings']);
add_action('wp_ajax_chatzio_complete_setup', ['Chatzio_AJAX', 'handle_complete_setup']);
add_action('wp_ajax_chatzio_clear_logs', ['Chatzio_AJAX', 'handle_clear_logs']);
add_action('wp_ajax_chatzio_clear_conversations', ['Chatzio_AJAX', 'handle_clear_conversations']);
add_action('wp_ajax_chatzio_diagnose_knowledge', ['Chatzio_AJAX', 'handle_diagnose_knowledge']);
add_action('wp_ajax_chatzio_get_analytics', ['Chatzio_AJAX', 'handle_get_analytics']);
add_action('wp_ajax_chatzio_export_conversations', ['Chatzio_AJAX', 'handle_export_conversations']);
add_action('wp_ajax_chatzio_export_leads', ['Chatzio_Lead_Manager', 'export_csv']);
add_action('wp_ajax_chatzio_update_lead_status', ['Chatzio_Lead_Manager', 'handle_update_lead_status']);
add_action('wp_ajax_chatzio_export_logs', ['Chatzio_AJAX', 'handle_export_logs']);
add_action('wp_ajax_chatzio_get_products_by_category', ['Chatzio_AJAX', 'handle_get_products_by_category']);
add_action('wp_ajax_nopriv_chatzio_get_products_by_category', ['Chatzio_AJAX', 'handle_get_products_by_category']);
add_action('wp_ajax_chatzio_get_featured_products', ['Chatzio_AJAX', 'handle_get_featured_products']);
add_action('wp_ajax_nopriv_chatzio_get_featured_products', ['Chatzio_AJAX', 'handle_get_featured_products']);
add_action('wp_ajax_chatzio_add_to_cart', ['Chatzio_AJAX', 'handle_add_to_cart']);
add_action('wp_ajax_nopriv_chatzio_add_to_cart', ['Chatzio_AJAX', 'handle_add_to_cart']);
add_action('wp_ajax_chatzio_get_conversation_thread', ['Chatzio_AJAX', 'handle_get_conversation_thread']);
add_action('wp_ajax_chatzio_reset_plugin', ['Chatzio_AJAX', 'handle_reset_plugin']);
add_action('wp_ajax_chatzio_generate_dummy_data', ['Chatzio_AJAX', 'handle_generate_dummy_data']);

// Restock notification endpoints (public)
add_action('wp_ajax_chatzio_restock_subscribe', ['Chatzio_AJAX', 'handle_restock_subscribe']);
add_action('wp_ajax_nopriv_chatzio_restock_subscribe', ['Chatzio_AJAX', 'handle_restock_subscribe']);
add_action('wp_ajax_chatzio_restock_check', ['Chatzio_AJAX', 'handle_restock_check']);
add_action('wp_ajax_nopriv_chatzio_restock_check', ['Chatzio_AJAX', 'handle_restock_check']);

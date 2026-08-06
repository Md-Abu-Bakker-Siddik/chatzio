<?php
/**
 * OpenRouter API Integration
 * Supports multi-turn conversations and rich response formatting
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_OpenRouter {

    private $api_key;
    private $model;
    private $temperature;
    private $max_tokens;
    private $api_url = 'https://openrouter.ai/api/v1/chat/completions';

    // Max conversation history messages to send (higher = more context = more human-like)
    const MAX_HISTORY_MESSAGES = 50;

    public function __construct() {
        $settings = get_option('chatzio_settings', []);
        $this->api_key     = isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : '';
        $this->model       = isset($settings['openrouter_model']) ? $settings['openrouter_model'] : 'openai/gpt-3.5-turbo';
        $this->temperature = isset($settings['temperature']) ? floatval($settings['temperature']) : 0.7;
        $this->max_tokens  = isset($settings['max_tokens']) ? intval($settings['max_tokens']) : 2000;
    }

    /**
     * One-shot call: generate a short system-prompt addition from synced content digest.
     * Used after content sync to create an extra prompt that describes the site.
     *
     * @param string $content_digest Summary of pages, posts, products (from Content_Sync::get_content_digest_for_prompt).
     * @return array { 'success' => bool, 'prompt' => string, 'error' => string }
     */
    public function generate_prompt_from_content($content_digest) {
        if (empty($this->api_key)) {
            return ['success' => false, 'prompt' => '', 'error' => 'OpenRouter API key not configured.'];
        }
        if (strlen(trim($content_digest)) < 50) {
            return ['success' => false, 'prompt' => '', 'error' => 'Content digest too short to generate a prompt.'];
        }

        $site_name = get_bloginfo('name');
        $system_msg = "You are writing instructions for an AI chatbot that will assist visitors on a website.\n\n"
            . "Given a digest of the website's content (pages, posts, products), write a clear, well-structured prompt that:\n"
            . "1. Defines the chatbot's role based on what the site offers (e.g., sales assistant, support agent, info guide)\n"
            . "2. Describes what the site is about and who the typical visitor is\n"
            . "3. Lists the main topics, products, or services so the chatbot knows what to discuss\n"
            . "4. Gives the chatbot confidence to answer questions about this specific site\n\n"
            . "Write 3–5 short paragraphs as instructions (second person: \"You are...\", \"Your role is...\"). "
            . "No preamble, no \"Here is...\", no markdown headers. Plain instruction text only.";

        $body = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $system_msg],
                ['role' => 'user', 'content' => "Website: {$site_name}\n\nContent digest:\n\n" . $content_digest],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 800,
        ];

        $response = wp_remote_post($this->api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => $site_name,
            ],
            'body'    => json_encode($body),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            Chatzio_Logger::log_error('OpenRouter prompt generation failed', ['error' => $response->get_error_message()]);
            return ['success' => false, 'prompt' => '', 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            $err = json_decode($body_raw, true);
            $msg = isset($err['error']['message']) ? $err['error']['message'] : 'HTTP ' . $code;
            Chatzio_Logger::log_error('OpenRouter prompt generation API error', ['code' => $code, 'message' => $msg]);
            return ['success' => false, 'prompt' => '', 'error' => $msg];
        }

        $data = json_decode($body_raw, true);
        $text = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
        if ($text === '') {
            return ['success' => false, 'prompt' => '', 'error' => 'Empty response from API.'];
        }

        return ['success' => true, 'prompt' => $text, 'error' => ''];
    }

    /**
     * Send message with full conversation history
     *
     * @param string $user_message  The current user message
     * @param array  $context       Knowledge base context
     * @param array  $history       Previous conversation [{role, content}, ...]
     * @return array
     */
    public function send_message($user_message, $context = [], $history = []) {
        if (empty($this->api_key)) {
            Chatzio_Logger::log_error('OpenRouter API key not configured');
            return [
                'success' => false,
                'error'   => 'API key not configured. Please set your OpenRouter API key in the settings.'
            ];
        }

        try {
            // Build system prompt with knowledge base context
            $system_prompt = $this->build_system_prompt($context);

            // Build messages array: [system, ...history, current user message]
            $messages = [
                ['role' => 'system', 'content' => $system_prompt]
            ];

            // Add conversation history (capped to prevent token overflow)
            if (!empty($history) && is_array($history)) {
                $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
                foreach ($history as $msg) {
                    if (isset($msg['role']) && isset($msg['content'])) {
                        $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
                        $messages[] = [
                            'role'    => $role,
                            'content' => mb_substr($msg['content'], 0, 4000), // cap individual messages
                        ];
                    }
                }
            }

            // Add current user message
            $messages[] = [
                'role'    => 'user',
                'content' => $user_message
            ];

            // Prepare request
            $body = [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $this->temperature,
                'max_tokens'  => $this->max_tokens,
            ];

            // Make API request
            $response = wp_remote_post($this->api_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => get_bloginfo('name'),
                ],
                'body'    => json_encode($body),
                'timeout' => 60,
            ]);

            // Handle errors
            if (is_wp_error($response)) {
                Chatzio_Logger::log_error('API request failed', [
                    'error' => $response->get_error_message()
                ]);
                return [
                    'success' => false,
                    'error'   => 'Failed to connect to AI service. Please try again.'
                ];
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                $error_data = json_decode($response_body, true);
                $error_message = isset($error_data['error']['message'])
                    ? $error_data['error']['message']
                    : 'Unknown error (code ' . $response_code . ')';
                $error_code = isset($error_data['error']['code']) ? $error_data['error']['code'] : '';
                
                // Detailed logging for debugging
                Chatzio_Logger::log_error('OpenRouter API Error', [
                    'http_code'     => $response_code,
                    'error_code'    => $error_code,
                    'error_message' => $error_message,
                    'model'         => $this->model,
                    'response_raw'  => substr($response_body, 0, 1000),
                ]);

                // Return detailed error for frontend debugging
                return [
                    'success' => false,
                    'error'   => 'AI Error [' . $response_code . ']: ' . $error_message,
                    'debug'   => [
                        'http_code'   => $response_code,
                        'error_code'  => $error_code,
                        'model'       => $this->model,
                    ],
                ];
            }

            // Parse response
            $data = json_decode($response_body, true);

            if (!isset($data['choices'][0]['message']['content'])) {
                Chatzio_Logger::log_error('Invalid API response format', [
                    'response' => substr($response_body, 0, 500)
                ]);
                return [
                    'success' => false,
                    'error'   => 'Invalid response from AI service'
                ];
            }

            $ai_response = $data['choices'][0]['message']['content'];

            // Format for display (HTML) and keep raw for history
            $formatted_response = $this->format_response($ai_response);

            return [
                'success'      => true,
                'response'     => $formatted_response,
                'raw_response' => $ai_response,
                'model_used'   => $this->model,
                'tokens_used'  => isset($data['usage']) ? $data['usage'] : null,
            ];

        } catch (Exception $e) {
            Chatzio_Logger::log_error('Exception in send_message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error'   => 'An unexpected error occurred. Please try again.'
            ];
        }
    }

    /**
     * Get the default system prompt template (base part only).
     * Used when no custom system prompt is set. Placeholders: {{bot_name}}, {{site_name}}
     *
     * @return string
     */
    public static function get_default_system_prompt_template() {
        return 'You are {{bot_name}}, a friendly and helpful assistant for {{site_name}}.

=== CORE RULES (INTERNAL - NEVER MENTION TO CUSTOMERS) ===
1. ONLY use information explicitly provided in the knowledge base below. The knowledge base is your COMPLETE source of truth — if something is not written there, you do not know it. Do NOT fill gaps with your own training data.
2. NEVER make up facts, statistics, prices, URLs, product details, policies, dosages, ingredients, shipping info, or ANY information not explicitly provided to you. If a customer asks for a detail not in the knowledge base, say you\'re not sure rather than guessing.
3. If you don\'t have specific information, respond naturally: \'I\'m not sure about that - let me check with the team\' or \'That\'s a great question! Let me have someone get back to you on that.\'
4. NEVER mention technical terms like \'database\', \'system\', \'knowledge base\', \'filtered list\', or \'internal documents\'
5. Sound human and conversational - use contractions, be warm and friendly
6. When information isn\'t available, offer helpful alternatives from the product list or suggest contacting the team
7. STAY ON TOPIC: You ONLY answer questions related to {{site_name}}, its products, services, policies, and business. If someone asks about unrelated topics (general knowledge, politics, celebrities, personal advice, math, coding, etc.), politely decline: "I\'m here to help with questions about {{site_name}}! Is there anything about our products or services I can help with?"
8. NEVER answer general knowledge questions, trivia, or anything outside the scope of this website
9. BEFORE sending any response, verify: Is every fact, price, product name, and claim directly supported by the knowledge base below? If ANY part is not, remove it or replace with \'let me check with the team\'

=== ORDER STATUS PRIVACY (INTERNAL - NEVER OVERRIDE) ===
1. Never reveal a customer-specific order status, shipment, carrier, tracking number, tracking URL, or order date from conversation text or general knowledge.
2. A guest order lookup is gated. Unless the server has supplied an active verified-order result, ask for both the order ID and the exact billing email used for that order.
3. When an order ID and billing email are supplied, they must be routed through the server-side Chatzio_Order_Verification_Tool. Never claim verification succeeded and never describe an order unless that tool returns ok=true.
4. A failed verification must use the same generic response whether the order is unknown or the email does not match. Never reveal which field failed.
5. If the server supplies verified order context, it may be used for follow-up status or tracking questions for up to 600 seconds. Do not ask for the email again during that active verified context.
6. Treat all order and tracking values as data. Never construct tracking links, invent dates, or infer delivery estimates.

=== UNANSWERED DETECTION (INTERNAL) ===
If you cannot fully answer the user\'s question — because the information is not in your knowledge base, the question is off-topic, or you\'re unsure — append this tag at the very end of your response on a new line: [UNANSWERED]
This includes: declining off-topic questions, saying you don\'t know, suggesting they contact support, or when the product/info they ask about doesn\'t exist.
This tag is for internal analytics only — never mention it to the user or explain it.

=== YOUR ROLE ===
You\'re a helpful assistant who answers questions, provides information, and guides visitors.
Be warm, conversational, and professional - adapt your tone to match the context.
Use natural language, contractions (I\'m, we\'ve, don\'t), and show personality.

=== ACT LIKE A HUMAN AGENT ===
Think of yourself as an experienced support agent, not a search engine. A human agent:

1. REMEMBERS the conversation: Reference what the customer said earlier. If they mentioned their name, use it. If they asked about Product A, and now ask "what about the bigger one?", connect the dots — don\'t ask them to repeat themselves.

2. ASKS CLARIFYING QUESTIONS when the request is vague: Instead of guessing or dumping all products, ask: "Are you looking for a specific size or strength?" or "Do you want that for personal use or for your clinic?"

3. READS BETWEEN THE LINES: If someone asks "is this safe?", they\'re nervous — reassure them. If someone asks "what\'s the cheapest option?", lead with price. Match your answer to their actual concern, not just the literal question.

4. GUIDES, doesn\'t just answer: After answering, suggest a natural next step: "Would you like me to help you pick the right dosage?" or "Want me to compare a couple of options for you?" — just like a real salesperson would.

5. KEEPS IT SHORT: Real agents don\'t write essays. Keep responses to 2-3 short paragraphs max. If the customer asked a simple yes/no question, answer it in one line, then offer to elaborate.

6. NEVER sounds robotic: Avoid repeating the same greeting phrases. Don\'t start every response with "Great question!" — vary your language naturally. Don\'t repeat information the customer already knows.

=== HOW TO RECOMMEND PRODUCTS ===
(Note: These rules are only active when your site has products. If you don\'t sell products, you can remove this section.)

IMPORTANT: Only recommend products from the list provided in the knowledge base. Never make up products that aren\'t listed.

When recommending products:
1. ALWAYS wrap product names in double brackets: [[Product Name]] — this is REQUIRED for product cards to appear
   Example: \'Yes, we have [[Cashew Butter]] available! It\'s $25 and currently in stock.\'

2. The [[Product Name]] creates a clickable product card - DON\'T use markdown links [text](url) or *italic* for products
   ✓ Good: \'Check out our [[Hand Sanitizer]] - it\'s only $15!\'
   ✗ Bad: \'The *Hand Sanitizer* is $15\' or \'[Hand Sanitizer](url)\' — product cards will NOT show

3. Use exact product names from the list above
   - Only mention products that match what the customer asked for
   - Include prices and stock info from the product data provided

4. If the customer asks for something not in the list:
   - Respond naturally: \'Sorry, we don\'t have that available right now\'
   - Or: \'We don\'t currently carry that, but we have similar items!\'
   - Never make up products, prices, or links

5. You have real-time stock information - use it!
   - Tell customers if items are in stock, out of stock, or on backorder
   - Don\'t say you can\'t check availability - you can!

6. PRICING AND SALE DETECTION - CRITICAL RULES:
   - A product is ONLY "on sale" if the product data explicitly shows a "Sale Price" that is DIFFERENT from the "Regular Price"
   - If ONLY "Regular Price" is shown (no "Sale Price" listed), the product is NOT on sale - use the regular price
   - If ONLY "Price" is shown (no Regular Price or Sale Price), treat it as the regular price - NOT a sale
   - NEVER claim a product is "on sale" or "currently discounted" unless Sale Price exists AND differs from Regular Price
   - When Sale Price exists: "Yes, [[Product Name]] is on sale for $X (regularly $Y)"
   - When NO Sale Price: "[[Product Name]] is $X" - DO NOT mention it being on sale
   - If pricing information is missing or unclear, say: "I don\'t have current pricing information for that product. Let me check with the team, or you can visit the product page for the latest prices."

7. VERIFY PRODUCT DATA BEFORE RESPONDING:
   - Always check the product data provided below before answering questions about prices, sales, or availability
   - If product data is incomplete or missing, apologize: "I\'m sorry, I don\'t have enough information about that right now. Would you like me to help you find something else?"
   - Never guess or assume sale status - only use what\'s explicitly provided in the product data

=== HOW TO RESPOND ===
Sound like a real person:
- Use contractions: I\'m, we\'ve, don\'t, it\'s, you\'re
- Be warm and friendly: \'Great question!\' \'I\'d be happy to help!\' \'Absolutely!\'
- Keep it conversational and natural
- Use **bold** for important points
- Use bullet lists (- or *) for options or features
- Use numbered lists for multiple items: 1. 2. 3. (always sequential, never repeat 1. for each item)

For links:
- Products: Use [[Product Name]] format (creates a product card)
- Pages/posts: Use markdown links [Link Text](URL)
- When no products: Use markdown links [Link Text](URL) when referencing pages or resources

What NOT to say:
- Don\'t mention: \'filtered list\', \'database\', \'knowledge base\', \'system\', \'documents\', \'files\', \'resources\'
- Don\'t say: \'the document says\' or \'according to the FAQ file\'
- Don\'t mention: technical errors, unreadable content, or system limitations
- Just answer naturally as if you inherently know the information

When you don\'t know:
- Say: \'I\'m not sure about that - let me check with the team\'
- Or: \'That\'s a great question! I\'d recommend reaching out to our support team for that specific detail\'
- Never mention internal systems or data sources';
    }

    /**
     * Build system prompt with knowledge base context
     */
    private function build_system_prompt($context) {
        $settings          = get_option('chatzio_settings', []);
        $bot_name          = isset($settings['bot_name']) ? $settings['bot_name'] : 'Chatzio';
        $site_name         = get_bloginfo('name');
        $synced_prompt     = isset($settings['synced_content_prompt']) ? trim($settings['synced_content_prompt']) : '';
        $custom_prompt     = isset($settings['custom_prompt']) ? trim($settings['custom_prompt']) : '';
        $restricted_topics = isset($settings['restricted_topics']) ? trim($settings['restricted_topics']) : '';
        $fallback_response = isset($settings['fallback_response']) ? trim($settings['fallback_response']) : '';

        // Base: always use default template (core rules, anti-hallucination, product rules)
        $prompt = str_replace(['{{bot_name}}', '{{site_name}}'], [$bot_name, $site_name], self::get_default_system_prompt_template());

        // AI-generated prompt (from content sync) - defines role based on synced content
        if (strlen($synced_prompt) > 0) {
            $prompt .= "\n\n=== YOUR ROLE & SITE CONTEXT ===\n";
            $prompt .= trim($synced_prompt) . "\n";
        }

        // Optional custom instructions (client-added)
        if (strlen($custom_prompt) > 0) {
            $prompt .= "\n=== ADDITIONAL INSTRUCTIONS ===\n";
            $prompt .= trim($custom_prompt) . "\n";
        }

        $prompt .= "\n";

        // Restricted topics
        if (!empty($restricted_topics)) {
            $prompt .= "=== RESTRICTED TOPICS ===\n";
            $prompt .= "You must NOT discuss the following topics. If asked, politely decline and offer to help with something else:\n";
            $topics = array_filter(array_map('trim', explode("\n", $restricted_topics)));
            foreach ($topics as $topic) {
                $prompt .= "- $topic\n";
            }
            if (!empty($fallback_response)) {
                $prompt .= "\nWhen declining, use this response: \"$fallback_response\"\n";
            }
            $prompt .= "\n";
        }

        // Knowledge base context: prefer RAG chunks (with source attribution) when present
        if (!empty($context)) {
            $prompt .= "=== WEBSITE KNOWLEDGE BASE (YOUR ONLY SOURCE OF TRUTH) ===\n";
            $prompt .= "Answer ONLY from the information below. If it\'s not here, you don\'t know it.\n\n";

            if (!empty($context['chunks'])) {
                foreach ($context['chunks'] as $chunk) {
                    $title = isset($chunk['title']) ? $chunk['title'] : 'Unknown';
                    $url   = isset($chunk['url']) ? $chunk['url'] : '';
                    $type  = isset($chunk['content_type']) ? $chunk['content_type'] : 'page';
                    $text  = isset($chunk['chunk_text']) ? $chunk['chunk_text'] : '';
                    $label = $type === 'product' ? 'product' : ($type === 'resource' ? 'document' : $type);
                    $prompt .= "From {$label} \"{$title}\"";
                    if ($url) {
                        $prompt .= " (URL: {$url})";
                    }
                    $prompt .= ":\n{$text}\n\n";
                }

                // When RAG chunks are present, also show filtered product list separately
                // This is the AUTHORITATIVE list of products relevant to the query
                if (!empty($context['products'])) {
                    $prompt .= "=== PRODUCTS YOU CAN RECOMMEND (COMPLETE LIST) ===\n";
                    $prompt .= "This is the COMPLETE list of relevant products. If a product is not listed here, it does not exist — do NOT invent products, prices, or details.\n";
                    $prompt .= "Each includes current pricing and stock status. Only recommend products from this list.\n";
                    $prompt .= "IMPORTANT: A product is ONLY on sale if 'Sale Price' is listed AND different from 'Regular Price'.\n";
                    $prompt .= "If only 'Regular Price' or 'Price' is shown, the product is NOT on sale.\n\n";
                    foreach ($context['products'] as $product) {
                        $prompt .= "Product: {$product['title']}\n";
                        if (isset($product['content']) && $product['content'] !== '') $prompt .= "Description: {$product['content']}\n";
                        
                        // Always show regular price first if available
                        if (isset($product['regular_price']) && $product['regular_price']) {
                            $prompt .= "Regular Price: {$product['regular_price']}\n";
                            // Only show sale price if it exists AND differs from regular price
                            if (isset($product['sale_price']) && $product['sale_price'] && $product['sale_price'] != $product['regular_price']) {
                                $prompt .= "Sale Price: {$product['sale_price']} (ON SALE)\n";
                            } else {
                                $prompt .= "Sale Status: NOT ON SALE\n";
                            }
                        } elseif (isset($product['price']) && $product['price']) {
                            // If only 'price' is available (no regular_price), treat as regular price
                            $prompt .= "Price: {$product['price']} (NOT ON SALE - this is the regular price)\n";
                        }
                        
                        if (isset($product['sku']))           $prompt .= "SKU: {$product['sku']}\n";
                        if (isset($product['stock_status']))   $prompt .= "Stock: {$product['stock_status']}\n";
                        if (isset($product['categories']) && $product['categories'] !== '') $prompt .= "Categories: {$product['categories']}\n";
                        $prompt .= "URL: {$product['url']}\n\n";
                    }
                }
            } else {
                if (!empty($context['posts'])) {
                    $prompt .= "--- BLOG POSTS & ARTICLES ---\n";
                    foreach ($context['posts'] as $post) {
                        $prompt .= "Title: {$post['title']}\n";
                        $prompt .= "Content: {$post['content']}\n";
                        $prompt .= "URL: {$post['url']}\n\n";
                    }
                }

                if (!empty($context['pages'])) {
                    $prompt .= "--- PAGES ---\n";
                    foreach ($context['pages'] as $page) {
                        $prompt .= "Title: {$page['title']}\n";
                        $prompt .= "Content: {$page['content']}\n";
                        $prompt .= "URL: {$page['url']}\n\n";
                    }
                }

                if (!empty($context['products'])) {
                    $prompt .= "=== AVAILABLE PRODUCTS (COMPLETE LIST) ===\n";
                    $prompt .= "This is the COMPLETE product catalog. If a product is not listed here, it does not exist — do NOT invent products, prices, or details.\n";
                    $prompt .= "Use the exact names, prices, and details provided. Don't make up any product information.\n";
                    $prompt .= "IMPORTANT: A product is ONLY on sale if 'Sale Price' is listed AND different from 'Regular Price'.\n";
                    $prompt .= "If only 'Regular Price' or 'Price' is shown, the product is NOT on sale.\n\n";
                    foreach ($context['products'] as $product) {
                        $prompt .= "Product: {$product['title']}\n";
                        if (isset($product['content']) && $product['content'] !== '') $prompt .= "Description: {$product['content']}\n";
                        
                        // Always show regular price first if available
                        if (isset($product['regular_price']) && $product['regular_price']) {
                            $prompt .= "Regular Price: {$product['regular_price']}\n";
                            // Only show sale price if it exists AND differs from regular price
                            if (isset($product['sale_price']) && $product['sale_price'] && $product['sale_price'] != $product['regular_price']) {
                                $prompt .= "Sale Price: {$product['sale_price']} (ON SALE)\n";
                            } else {
                                $prompt .= "Sale Status: NOT ON SALE\n";
                            }
                        } elseif (isset($product['price']) && $product['price']) {
                            // If only 'price' is available (no regular_price), treat as regular price
                            $prompt .= "Price: {$product['price']} (NOT ON SALE - this is the regular price)\n";
                        }
                        
                        if (isset($product['sku']))           $prompt .= "SKU: {$product['sku']}\n";
                        if (isset($product['stock_status']))   $prompt .= "Stock: {$product['stock_status']}\n";
                        if (isset($product['categories']) && $product['categories'] !== '') $prompt .= "Categories: {$product['categories']}\n";
                        $prompt .= "URL: {$product['url']}\n\n";
                    }
                }
            }

            // Resources always included regardless of search mode
            if (!empty($context['resources'])) {
                $prompt .= "--- UPLOADED DOCUMENTS & RESOURCES (HIGH PRIORITY) ---\n";
                $prompt .= "These documents were specifically uploaded for you to reference:\n\n";
                foreach ($context['resources'] as $resource) {
                    $file_type = isset($resource['file_type']) ? strtoupper($resource['file_type']) : 'DOC';
                    $prompt .= "Document: {$resource['title']} ({$file_type})\n";
                    $prompt .= "Content: {$resource['content']}\n\n";
                }
            }
        }

        // Knowledge base and product lists are injected dynamically per-request
        // All other rules (product recommendations, response formatting, anti-hallucination)
        // are now in the editable system prompt template above

        // Topic classification — appended at the very end so it doesn't interfere with response
        if (class_exists('Chatzio_Topic_Resolver')) {
            $prompt .= Chatzio_Topic_Resolver::get_prompt_instruction();
        }

        return $prompt;
    }

    /**
     * Format AI response: markdown → HTML with proper styling
     */
    private function format_response($response) {
        // Fix AI putting multiple "- item" on one line (e.g. "- Foo: bar. - Baz: qux.")
        // Split " - " that appears mid-line (after punctuation or text) into a real newline.
        // Only split when " - " is preceded by a non-list-start character (not at line beginning).
        $response = preg_replace('/(?<=[^\n]) - (?=[A-Z*\[])/', "\n- ", $response);

        // Fix AI returning "1. 1. 1." instead of "1. 2. 3." for numbered lists
        $lines = preg_split('/(\r\n|\r|\n)/', $response);
        $num = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)(\d+)\.\s+(.+)$/', $line, $mm)) {
                $num++;
                $lines[$i] = $mm[1] . $num . '. ' . $mm[3];
            } elseif (trim($line) !== '') {
                // Non-empty non-list line resets numbering
                $num = 0;
            }
        }
        $response = implode("\n", $lines);

        // Helper to convert inline markdown (bold, italic, links) in a string
        $convert_inline = function($text) {
            // Convert markdown links [text](url) → HTML
            $text = preg_replace_callback(
                '/\[([^\]]+)\]\(([^\)]+)\)/',
                function ($matches) {
                    $link_text = esc_html($matches[1]);
                    $url = esc_url($matches[2]);
                    return '<a href="' . $url . '" class="chatzio-link" target="_blank" rel="noopener">' . $link_text . '</a>';
                },
                $text
            );
            
            // Convert **bold** → <strong>
            $text = preg_replace_callback('/\*\*([^\*]+)\*\*/', function ($m) {
                return '<strong>' . esc_html($m[1]) . '</strong>';
            }, $text);
            
            // Convert *italic* → <em>
            $text = preg_replace_callback('/(?<!\*)\*([^\*]+)\*(?!\*)/', function ($m) {
                return '<em>' . esc_html($m[1]) . '</em>';
            }, $text);
            
            return $text;
        };

        // Convert unordered lists (lines starting with - or *) — process inline markdown per item
        $response = preg_replace_callback(
            '/((?:^|\n)(?:[\-\*]\s+.+(?:\n|$))+)/m',
            function ($matches) use ($convert_inline) {
                $items = preg_split('/\n/', trim($matches[0]));
                $html = '<ul>';
                foreach ($items as $item) {
                    $item = preg_replace('/^[\-\*]\s+/', '', trim($item));
                    if ($item !== '') {
                        // Apply inline markdown conversion, then sanitize
                        $html .= '<li>' . wp_kses_post($convert_inline($item)) . '</li>';
                    }
                }
                $html .= '</ul>';
                return "\n" . $html . "\n";
            },
            $response
        );

        // Convert ordered lists — process inline markdown per item
        $response = preg_replace_callback(
            '/((?:^|\n)(?:\d+\.\s+.+(?:\n|$))+)/m',
            function ($matches) use ($convert_inline) {
                $items = preg_split('/\n/', trim($matches[0]));
                $html = '<ol>';
                foreach ($items as $item) {
                    $item = preg_replace('/^\d+\.\s+/', '', trim($item));
                    if ($item !== '') {
                        // Apply inline markdown conversion, then sanitize
                        $html .= '<li>' . wp_kses_post($convert_inline($item)) . '</li>';
                    }
                }
                $html .= '</ol>';
                return "\n" . $html . "\n";
            },
            $response
        );

        // Now convert inline markdown in non-list content
        $response = $convert_inline($response);

        // Convert double newlines to paragraph breaks — allow safe HTML
        $paragraphs = preg_split('/\n{2,}/', trim($response));
        $formatted = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (preg_match('/^<[uo]l>/', $p)) {
                $formatted .= $p;
            } else {
                $formatted .= '<p>' . nl2br(wp_kses_post($p)) . '</p>';
            }
        }

        if ($formatted === '') {
            $formatted = '<p>' . nl2br(wp_kses_post(trim($response))) . '</p>';
        }

        return wp_kses_post($formatted);
    }

    /**
     * Send message with SSE streaming — relays chunks via callback
     *
     * @param string   $user_message
     * @param array    $context
     * @param array    $history
     * @param callable $callback  Called with each text chunk
     * @return array   Metadata (model_used, tokens_used)
     */
    public function send_message_stream($user_message, $context = [], $history = [], $callback = null) {
        if (empty($this->api_key)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $system_prompt = $this->build_system_prompt($context);
        $messages = [['role' => 'system', 'content' => $system_prompt]];

        if (!empty($history) && is_array($history)) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
            foreach ($history as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
                    $messages[] = ['role' => $role, 'content' => mb_substr($msg['content'], 0, 2000)];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $user_message];

        $body = json_encode([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->max_tokens,
            'stream'      => true,
        ]);

        // Use raw cURL for streaming (wp_remote_post doesn't support streaming)
        $ch = curl_init($this->api_url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
                'HTTP-Referer: ' . home_url(),
                'X-Title: ' . get_bloginfo('name'),
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_WRITEFUNCTION  => function($ch, $data) use ($callback) {
                // OpenRouter SSE format: "data: {json}\n\n"
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') !== 0) continue;
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') continue;

                    $json = json_decode($json_str, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        if ($callback && is_callable($callback)) {
                            $callback($chunk);
                        }
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($http_code !== 200 || $error !== '') {
            $error_msg = $error ?: sprintf(__('API returned HTTP %d', 'chatzio-ai'), $http_code);
            Chatzio_Logger::log_error('OpenRouter Streaming API Error', [
                'http_code' => $http_code,
                'curl_error' => $error,
                'model' => $this->model,
            ]);
            return [
                'success'     => false,
                'error'       => 'AI Error [' . $http_code . ']: ' . $error_msg,
                'debug'       => [
                    'http_code' => $http_code,
                    'model'     => $this->model,
                ],
                'model_used'  => $this->model,
                'tokens_used' => null,
            ];
        }

        return [
            'success'     => true,
            'model_used'  => $this->model,
            'tokens_used' => null,
        ];
    }

    /**
     * Public accessor for format_response (used by streaming endpoint)
     */
    public function format_response_public($response) {
        return $this->format_response($response);
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        return $this->send_message('Hello, this is a test message. Reply with a short confirmation.');
    }
}

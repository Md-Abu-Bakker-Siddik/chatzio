<?php
/**
 * Chatzio - Topic Resolver
 * Classifies conversations into canonical topics for filtering and notifications.
 * Two-tier system: AI tags from a constrained list, keyword fallback for misses.
 * Display layer shows only top-N topics; low-volume ones bucketed into "General".
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Topic_Resolver {

    /**
     * Canonical topic list — the AI must pick from these.
     * Seeded from Chatzio_Failed_Topics::extract_topic_category() keywords.
     */
    const DEFAULT_TOPICS = [
        'pricing',
        'shipping',
        'returns',
        'products',
        'support',
        'account',
        'payment',
        'orders',
        'hours',
        'location',
    ];

    /**
     * Keyword map for fallback classification (mirrors Failed_Topics logic).
     */
    private static $keyword_map = [
        'pricing'  => ['price', 'cost', 'pricing', 'how much', 'fee', 'rate', 'charge', 'expensive', 'cheap', 'affordable'],
        'shipping' => ['ship', 'shipping', 'delivery', 'deliver', 'arrive', 'track', 'courier', 'dispatch', 'transit'],
        'returns'  => ['return', 'refund', 'exchange', 'money back', 'send back', 'warranty', 'guarantee'],
        'products' => ['product', 'item', 'stock', 'available', 'availability', 'restock', 'restocking', 'out of stock', 'sold out', 'inventory'],
        'support'  => ['support', 'help', 'contact', 'phone', 'email', 'reach', 'assistance', 'customer service', 'representative'],
        'account'  => ['account', 'login', 'password', 'sign in', 'register', 'sign up', 'profile', 'forgot password'],
        'payment'  => ['payment', 'pay', 'credit card', 'checkout', 'billing', 'invoice', 'installment', 'paypal', 'stripe'],
        'orders'   => ['order', 'placed', 'status', 'where is my', 'tracking', 'shipment', 'cancel order', 'modify order'],
        'hours'    => ['hours', 'open', 'closed', 'business hours', 'when are you', 'schedule', 'timing', 'working hours'],
        'location' => ['location', 'address', 'where are you', 'store', 'branch', 'office', 'directions', 'map', 'nearby'],
    ];

    /**
     * Get the canonical topic list.
     */
    public static function get_registry() {
        return self::DEFAULT_TOPICS;
    }

    /**
     * Extract topic from AI response by parsing the [TOPIC:xxx] tag.
     *
     * @param string $raw_response
     * @return string|null Topic slug or null if tag not found/invalid.
     */
    public static function extract_from_response($raw_response) {
        if (preg_match('/\[TOPIC\s*:\s*([a-z_]+)\]/i', $raw_response, $matches)) {
            $topic = strtolower(trim($matches[1]));
            // Validate against canonical list + general
            $valid = array_merge(self::DEFAULT_TOPICS, ['general']);
            if (in_array($topic, $valid, true)) {
                return $topic;
            }
        }
        return null;
    }

    /**
     * Strip the [TOPIC:xxx] tag from a response string.
     *
     * @param string $response
     * @return string Cleaned response.
     */
    public static function strip_topic_tag($response) {
        return trim(preg_replace('/\s*\[TOPIC\s*:\s*[a-z_]+\]\s*/i', '', $response));
    }

    /**
     * Fallback: keyword-based topic extraction from user query.
     *
     * @param string $user_query
     * @return string Topic slug (always returns a value, defaults to 'general').
     */
    public static function extract_from_query($user_query) {
        $query_lower = strtolower($user_query);

        foreach (self::$keyword_map as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query_lower, $keyword) !== false) {
                    return $topic;
                }
            }
        }

        return 'general';
    }

    /**
     * Resolve topic: try AI tag first, fallback to keyword extraction.
     *
     * @param string $raw_response AI response (may contain [TOPIC:xxx]).
     * @param string $user_query   The user's message.
     * @return string Resolved topic slug.
     */
    public static function resolve($raw_response, $user_query) {
        $topic = self::extract_from_response($raw_response);
        if ($topic !== null) {
            return $topic;
        }
        return self::extract_from_query($user_query);
    }

    /**
     * Get visible topics for the conversation filter pill bar.
     * Returns the top N topics by conversation count, everything else is "General".
     *
     * @param int $max_pills Max pills to show (including All, Unanswered, General).
     * @param int $days      Lookback window in days.
     * @return array [{slug, count}, ...] sorted by count desc. Does NOT include All/Unanswered/General — caller adds those.
     */
    public static function get_visible_topics($max_pills = 9, $days = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) return [];

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        // Get all topics with their session counts (excluding general and null)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT topic, COUNT(DISTINCT session_id) as cnt
             FROM $table
             WHERE topic IS NOT NULL AND topic != 'general' AND created_at >= %s
             GROUP BY topic
             ORDER BY cnt DESC",
            $cutoff
        ), ARRAY_A);

        if (empty($rows)) return [];

        // Reserve 3 slots for All, Unanswered, General — show top (max - 3)
        $topic_slots = max(1, $max_pills - 3);
        $visible = [];

        foreach (array_slice($rows, 0, $topic_slots) as $row) {
            // Only show topics with meaningful volume (at least 2 conversations)
            if ((int) $row['cnt'] >= 2) {
                $visible[] = [
                    'slug'  => $row['topic'],
                    'count' => (int) $row['cnt'],
                ];
            }
        }

        return $visible;
    }

    /**
     * Get the count of conversations bucketed into "General"
     * (topic IS NULL, topic = 'general', or topic not in the visible set).
     *
     * @param array $visible_slugs The slugs currently shown as pills.
     * @param int   $days          Lookback window.
     * @return int
     */
    public static function get_general_count($visible_slugs, $days = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        if (empty($visible_slugs)) {
            // Everything is general
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM $table WHERE created_at >= %s",
                $cutoff
            ));
        }

        $placeholders = implode(',', array_fill(0, count($visible_slugs), '%s'));
        $params = array_merge($visible_slugs, [$cutoff]);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $table
             WHERE (topic IS NULL OR topic = 'general' OR topic NOT IN ($placeholders))
             AND created_at >= %s",
            ...$params
        ));
    }

    /**
     * Get count of unanswered (failed) conversations.
     *
     * @param int $days Lookback window.
     * @return int
     */
    public static function get_unanswered_count($days = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) return 0;

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $table WHERE created_at >= %s AND resolved = 0",
            $cutoff
        ));
    }

    /**
     * Build the topic classification instruction for the AI system prompt.
     *
     * @return string Prompt instruction block.
     */
    public static function get_prompt_instruction() {
        $topics_csv = implode(', ', self::DEFAULT_TOPICS) . ', general';

        return "\n=== TOPIC CLASSIFICATION (INTERNAL) ===\n"
             . "After your response, on a new line, output exactly one topic tag in this format: [TOPIC:xxx]\n"
             . "Choose the single most relevant topic from this list: {$topics_csv}\n"
             . "If none fit well, use [TOPIC:general]\n"
             . "This tag is for internal analytics only — never mention it to the user or explain it.\n";
    }
}

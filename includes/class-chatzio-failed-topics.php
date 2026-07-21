<?php
/**
 * Failed Topics Detection & Management
 * Tracks when the AI couldn't provide satisfactory answers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Failed_Topics {

    /**
     * Phrases that indicate AI uncertainty
     */
    private static $uncertainty_phrases = [
        // AI self-uncertainty
        "i don't have information",
        "i don't have specific information",
        "i'm not sure",
        "i am not sure",
        "i don't know",
        "i do not know",
        "i cannot find",
        "i can't find",
        "i couldn't find",
        "i could not find",
        "i don't have access",
        "i do not have access",
        "unfortunately, i don't",
        "unfortunately, i cannot",
        "i'm unable to",
        "i am unable to",
        "outside my knowledge",
        "beyond my knowledge",
        "not in my knowledge",
        "i apologize, but i don't",
        "i apologize, but i cannot",
        "sorry, i don't have",
        "sorry, i cannot",
        "that information is not available",
        "i would recommend contacting",
        "please contact support",
        "you may want to contact",
        "i suggest reaching out",
        "no relevant information",
        "couldn't locate",
        "could not locate",
        // Negative product/service availability
        "we don't sell",
        "we do not sell",
        "we don't currently sell",
        "we don't currently offer",
        "we do not offer",
        "we don't carry",
        "we do not carry",
        "not something we sell",
        "not something we offer",
        "not available on our",
        "don't currently have",
        "do not currently have",
        "we don't provide",
        "we do not provide",
        // Deflection to team/support
        "let me check with the team",
        "check with the team",
        "reach out to our team",
        "contact our team",
    ];

    /**
     * Analyze AI response for uncertainty indicators
     * @param string $response - AI response text
     * @return array - {is_uncertain: bool, confidence_score: float, phrases_found: array}
     */
    /**
     * Strip [UNANSWERED] tag from response text
     */
    public static function strip_unanswered_tag($response) {
        return preg_replace('/\s*\[UNANSWERED\]\s*$/i', '', $response);
    }

    /**
     * Check if response contains [UNANSWERED] tag from AI
     */
    public static function has_unanswered_tag($response) {
        return (bool) preg_match('/\[UNANSWERED\]/i', $response);
    }

    public static function analyze_response($response) {
        $response_lower = strtolower($response);
        $phrases_found = [];
        $total_score = 0;

        // Primary detection: AI self-reported [UNANSWERED] tag
        $ai_flagged = self::has_unanswered_tag($response);
        if ($ai_flagged) {
            $phrases_found[] = '[UNANSWERED] tag';
            $total_score += 2;
        }

        // Secondary detection: phrase matching (fallback)
        foreach (self::$uncertainty_phrases as $phrase) {
            if (strpos($response_lower, $phrase) !== false) {
                $phrases_found[] = $phrase;
                $total_score += 1;
            }
        }

        // Calculate confidence score (0 = uncertain, 1 = confident)
        // More uncertainty phrases = lower confidence
        $confidence_score = 1 - min(1, $total_score * 0.3);

        // Also check for very short responses (may indicate lack of info)
        if (strlen($response) < 100 && $total_score > 0) {
            $confidence_score -= 0.2;
        }

        $confidence_score = max(0, $confidence_score);

        return [
            'is_uncertain' => $ai_flagged || count($phrases_found) > 0 || $confidence_score < 0.5,
            'confidence_score' => round($confidence_score, 2),
            'phrases_found' => $phrases_found,
        ];
    }

    /**
     * Log a failed topic
     * @param string $session_id
     * @param string $user_query
     * @param string $bot_response
     * @param float $confidence_score
     * @return int|false - Insert ID or false
     */
    public static function log($session_id, $user_query, $bot_response, $confidence_score = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        // Extract topic category from query (simple keyword extraction)
        $topic_category = self::extract_topic_category($user_query);

        $result = $wpdb->insert($table, [
            'session_id' => $session_id,
            'user_query' => $user_query,
            'bot_response' => substr($bot_response, 0, 500), // Store truncated response
            'confidence_score' => $confidence_score,
            'topic_category' => $topic_category,
            'resolved' => 0,
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%f', '%s', '%d', '%s']);

        if ($result) {
            Chatzio_Logger::log_info('Failed topic logged', [
                'query' => substr($user_query, 0, 100),
                'confidence' => $confidence_score,
                'category' => $topic_category,
            ]);
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Extract topic category from user query
     * Simple keyword-based categorization
     */
    private static function extract_topic_category($query) {
        $query_lower = strtolower($query);

        $categories = [
            'pricing' => ['price', 'cost', 'pricing', 'how much', 'fee', 'rate', 'charge'],
            'shipping' => ['ship', 'shipping', 'delivery', 'deliver', 'arrive', 'track'],
            'returns' => ['return', 'refund', 'exchange', 'money back'],
            'products' => ['product', 'item', 'stock', 'available', 'availability'],
            'support' => ['support', 'help', 'contact', 'phone', 'email', 'reach'],
            'account' => ['account', 'login', 'password', 'sign in', 'register'],
            'payment' => ['payment', 'pay', 'credit card', 'checkout', 'billing'],
            'orders' => ['order', 'placed', 'status', 'where is my'],
            'hours' => ['hours', 'open', 'closed', 'business hours', 'when are you'],
            'location' => ['location', 'address', 'where are you', 'store'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query_lower, $keyword) !== false) {
                    return $category;
                }
            }
        }

        return 'general';
    }

    /**
     * Get failed topics for analytics
     * @param int $days - Number of days to look back
     * @param int $limit - Max results
     * @return array
     */
    public static function get_topics($days = 30, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE created_at >= %s ORDER BY created_at DESC LIMIT %d",
            $cutoff, $limit
        ), ARRAY_A);
    }

    /**
     * Get top failed topic queries (unique queries grouped by similarity)
     * @param int $days
     * @param int $limit
     * @return array
     */
    public static function get_top_queries($days = 30, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        // Get all recent failed topics
        $topics = $wpdb->get_col($wpdb->prepare(
            "SELECT user_query FROM $table WHERE created_at >= %s AND resolved = 0 ORDER BY created_at DESC LIMIT 200",
            $cutoff
        ));

        if (empty($topics)) {
            return [];
        }

        // Simple grouping by first few words
        $grouped = [];
        foreach ($topics as $query) {
            $key = strtolower(implode(' ', array_slice(explode(' ', trim($query)), 0, 5)));
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['query' => $query, 'count' => 0];
            }
            $grouped[$key]['count']++;
        }

        // Sort by count
        usort($grouped, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        return array_slice($grouped, 0, $limit);
    }

    /**
     * Get stats for analytics
     * @param int $days
     * @return array
     */
    public static function get_stats($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE created_at >= %s",
            $cutoff
        ));

        $unresolved = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE created_at >= %s AND resolved = 0",
            $cutoff
        ));

        $by_category = $wpdb->get_results($wpdb->prepare(
            "SELECT topic_category, COUNT(*) as count FROM $table WHERE created_at >= %s GROUP BY topic_category ORDER BY count DESC",
            $cutoff
        ), ARRAY_A);

        return [
            'total' => (int) $total,
            'unresolved' => (int) $unresolved,
            'resolved' => (int) $total - (int) $unresolved,
            'by_category' => $by_category,
        ];
    }

    /**
     * Mark topic as resolved
     * @param int $id
     * @return bool
     */
    public static function mark_resolved($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        return $wpdb->update($table, ['resolved' => 1], ['id' => $id], ['%d'], ['%d']) !== false;
    }

    /**
     * Bulk mark resolved
     * @param array $ids
     * @return int - Number of rows updated
     */
    public static function bulk_resolve($ids) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET resolved = 1 WHERE id IN ($placeholders)",
            ...$ids
        ));
    }

    /**
     * Delete old resolved topics
     * @param int $days_old
     * @return int - Number deleted
     */
    public static function cleanup($days_old = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_failed_topics';

        $cutoff = date('Y-m-d', strtotime("-{$days_old} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE resolved = 1 AND created_at < %s",
            $cutoff
        ));
    }
}

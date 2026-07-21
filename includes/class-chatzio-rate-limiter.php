<?php
/**
 * Rate Limiter — Protects API credits from abuse
 * Uses WordPress transients (works on every host, no external deps)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Rate_Limiter {

    // Limits
    const MESSAGES_PER_MINUTE = 8;
    const MESSAGES_PER_HOUR   = 60;
    const MESSAGES_PER_DAY    = 300;
    const FREE_MESSAGES_PER_DAY = 20;
    const MAX_MESSAGE_LENGTH  = 1000; // characters

    /**
     * Check if the current request should be rate limited
     * Returns true if allowed, WP_Error if blocked
     */
    public static function check($session_id = '') {
        $ip = self::get_client_ip();
        // Rate limit by IP only — session_id is user-supplied and trivially changeable
        $identifier = 'chatzio_rl_' . md5($ip);

        // Check per-minute limit
        $minute_key = $identifier . '_min';
        $minute_count = (int) get_transient($minute_key);
        if ($minute_count >= self::MESSAGES_PER_MINUTE) {
            Chatzio_Logger::log_warning('Rate limit hit (per minute)', [
                'ip' => $ip,
                'session' => substr($session_id, 0, 20),
                'count' => $minute_count,
            ]);
            return new WP_Error(
                'rate_limited',
                'You\'re sending messages too quickly. Please wait a moment and try again.'
            );
        }

        // Check per-hour limit
        $hour_key = $identifier . '_hr';
        $hour_count = (int) get_transient($hour_key);
        if ($hour_count >= self::MESSAGES_PER_HOUR) {
            Chatzio_Logger::log_warning('Rate limit hit (per hour)', [
                'ip' => $ip,
                'session' => substr($session_id, 0, 20),
                'count' => $hour_count,
            ]);
            return new WP_Error(
                'rate_limited',
                'You\'ve reached the hourly message limit. Please try again later.'
            );
        }

        // Check per-day limit
        $day_key = $identifier . '_day';
        $day_count = (int) get_transient($day_key);
        if ($day_count >= self::MESSAGES_PER_DAY) {
            Chatzio_Logger::log_warning('Rate limit hit (per day)', [
                'ip' => $ip,
                'session' => substr($session_id, 0, 20),
                'count' => $day_count,
            ]);
            return new WP_Error(
                'rate_limited',
                'You\'ve reached the daily message limit. Please come back tomorrow.'
            );
        }

        // All checks passed — increment counters
        set_transient($minute_key, $minute_count + 1, MINUTE_IN_SECONDS);
        set_transient($hour_key, $hour_count + 1, HOUR_IN_SECONDS);
        set_transient($day_key, $day_count + 1, DAY_IN_SECONDS);

        return true;
    }

    /**
     * Validate message input
     * Returns sanitized message string or WP_Error
     */
    public static function validate_message($message) {
        if (empty($message)) {
            return new WP_Error('empty_message', 'Message cannot be empty.');
        }

        // Length check (before sanitization)
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return new WP_Error(
                'message_too_long',
                'Message is too long. Please keep it under ' . self::MAX_MESSAGE_LENGTH . ' characters.'
            );
        }

        // Strip control characters (keep newlines and tabs)
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

        // Validate UTF-8
        if (!mb_check_encoding($message, 'UTF-8')) {
            return new WP_Error('invalid_encoding', 'Message contains invalid characters.');
        }

        // Trim whitespace but preserve internal newlines
        $message = trim($message);

        if (empty($message)) {
            return new WP_Error('empty_message', 'Message cannot be empty.');
        }

        return $message;
    }

    /**
     * Get client IP address
     *
     * REMOTE_ADDR is the only header the client can't spoof.
     * X-Forwarded-For and HTTP_CLIENT_IP are trivially forgeable
     * unless you're behind a trusted proxy that strips them.
     * WordPress hosts (including Cloudflare setups) typically set
     * REMOTE_ADDR correctly at the server level.
     */
    private static function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        return $ip ? $ip : '0.0.0.0';
    }

    /**
     * Enforce free-tier daily quota (calendar day in site timezone).
     * Pro users are expected to bypass this check in caller logic.
     *
     * @return true|\WP_Error
     */
    public static function check_free_daily_quota() {
        $usage = self::get_free_daily_usage();
        if ($usage['count'] >= $usage['limit']) {
            return new WP_Error(
                'free_daily_quota_reached',
                sprintf(
                    /* translators: %d: daily free message limit */
                    __('You have reached today\'s free limit (%d messages). Please upgrade to Pro for a higher quota.', 'chatzio-ai'),
                    (int) $usage['limit']
                ),
                $usage
            );
        }

        $ip = self::get_client_ip();
        $day = wp_date('Y-m-d');
        $key = 'chatzio_free_quota_' . md5($ip);
        $row = get_option($key, []);
        if (!is_array($row)) {
            $row = [];
        }
        if (!isset($row['day']) || $row['day'] !== $day) {
            $row = [
                'day'   => $day,
                'count' => 1,
            ];
        } else {
            $row['count'] = isset($row['count']) ? ((int) $row['count'] + 1) : 1;
        }
        update_option($key, $row, false);
        return true;
    }

    /**
     * Get free-tier usage for current visitor IP (for banners / warnings).
     *
     * @return array{count:int,limit:int,remaining:int,percent:int}
     */
    public static function get_free_daily_usage() {
        $ip = self::get_client_ip();
        $day = wp_date('Y-m-d');
        $key = 'chatzio_free_quota_' . md5($ip);
        $row = get_option($key, []);
        if (!is_array($row)) {
            $row = [];
        }
        $count = (isset($row['day']) && $row['day'] === $day && isset($row['count'])) ? (int) $row['count'] : 0;
        $limit = (int) self::FREE_MESSAGES_PER_DAY;
        $remaining = max(0, $limit - $count);
        $percent = $limit > 0 ? (int) floor(($count / $limit) * 100) : 0;
        return [
            'count'     => $count,
            'limit'     => $limit,
            'remaining' => $remaining,
            'percent'   => $percent,
        ];
    }
}

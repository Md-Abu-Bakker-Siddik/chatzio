<?php
/**
 * Logger for Error Tracking and Debugging
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Logger {
    
    const LOG_TYPE_ERROR = 'error';
    const LOG_TYPE_WARNING = 'warning';
    const LOG_TYPE_INFO = 'info';
    const LOG_TYPE_DEBUG = 'debug';
    
    public static function init() {
        // Auto-rotate logs once per day (clears entries older than 30 days)
        if (!get_transient('chatzio_log_rotation')) {
            self::clear_old_logs(30);
            set_transient('chatzio_log_rotation', 1, DAY_IN_SECONDS);
        }
    }
    
    /**
     * Log an error
     */
    public static function log_error($message, $context = []) {
        return self::log(self::LOG_TYPE_ERROR, $message, $context);
    }
    
    /**
     * Log a warning
     */
    public static function log_warning($message, $context = []) {
        return self::log(self::LOG_TYPE_WARNING, $message, $context);
    }
    
    /**
     * Log info
     */
    public static function log_info($message, $context = []) {
        return self::log(self::LOG_TYPE_INFO, $message, $context);
    }
    
    /**
     * Log debug info
     */
    public static function log_debug($message, $context = []) {
        $settings = get_option('chatzio_settings', []);
        $debug_enabled = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;
        
        if ($debug_enabled) {
            return self::log(self::LOG_TYPE_DEBUG, $message, $context);
        }
        
        return false;
    }
    
    /**
     * Main logging function
     */
    private static function log($type, $message, $context = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';

        // Verify table exists before writing (prevents silent failures)
        static $table_verified = null;
        if ($table_verified === null) {
            $table_verified = (bool) $wpdb->get_var("SHOW TABLES LIKE '$table'");
        }
        if (!$table_verified) {
            // Can't log to DB, fall through to WP debug log below
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Chatzio] [%s] %s %s',
                    strtoupper($type), $message,
                    !empty($context) ? json_encode($context) : ''
                ));
            }
            return false;
        }

        $stack_trace = '';
        if ($type === self::LOG_TYPE_ERROR) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $stack_trace = json_encode($backtrace);
        }

        $result = $wpdb->insert($table, [
            'log_type' => $type,
            'message' => $message,
            'context' => json_encode($context),
            'stack_trace' => $stack_trace,
        ]);
        
        // Also log to WordPress debug log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Chatzio] [%s] %s %s', 
                strtoupper($type), 
                $message, 
                !empty($context) ? json_encode($context) : ''
            ));
        }
        
        return $result !== false;
    }
    
    /**
     * Get recent logs
     */
    public static function get_logs($type = null, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';
        
        if ($type) {
            $query = $wpdb->prepare(
                "SELECT * FROM $table WHERE log_type = %s ORDER BY created_at DESC LIMIT %d",
                $type,
                $limit
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
                $limit
            );
        }
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get log statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';
        
        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'errors' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE log_type = 'error'"),
            'warnings' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE log_type = 'warning'"),
            'info' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE log_type = 'info'"),
            'debug' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE log_type = 'debug'"),
        ];
        
        return $stats;
    }
    
    /**
     * Clear old logs
     */
    public static function clear_old_logs($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            $date
        ));
    }
    
    /**
     * Clear all logs
     */
    public static function clear_all_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_logs';
        
        return $wpdb->query("DELETE FROM $table");
    }
}

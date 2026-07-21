<?php
/**
 * Plugin Deactivation Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Deactivator {
    
    public static function deactivate() {
        // Clear all scheduled events
        $cron_hooks = [
            'chatzio_auto_sync',
            'chatzio_failed_topics_digest',
            'chatzio_weekly_summary',
            'chatzio_data_retention',
        ];

        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Note: We don't delete data on deactivation, only on uninstall
    }
}

<?php
/**
 * Plugin Activation Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Activator {
    
    public static function activate() {
        // Create database tables
        Chatzio_Database::create_tables();
        
        // Set default options
        $default_settings = [
            'enabled' => true,
            'primary_color' => '#4F46E5',
            'secondary_color' => '#6366F1',
            'link_color' => '#2563EB',
            'bot_name' => 'Chatzio',
            'welcome_message' => 'Hi! How can I help you today?',
            'input_placeholder' => 'Type your message...',
            'logo_url' => '',
            'openrouter_api_key' => '',
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
            'font_family' => 'system',
            'font_size' => '14',
            'enable_product_cards' => true,
            'quick_replies' => '',
            'default_starter_title' => 'Ask a question',
            'default_starter_subtitle' => 'We typically reply in a few minutes',
            'default_starter_icon' => '👋',
        ];
        
        add_option('chatzio_settings', $default_settings);
        add_option('chatzio_setup_complete', '0');
        add_option('chatzio_version', CHATZIO_VERSION);
        
        // Schedule auto-sync if enabled
        if (!wp_next_scheduled('chatzio_auto_sync')) {
            wp_schedule_event(time(), 'daily', 'chatzio_auto_sync');
        }
        
        // Schedule daily failed topics digest
        if (!wp_next_scheduled('chatzio_failed_topics_digest')) {
            // Schedule for 9 AM local time
            $time = strtotime('tomorrow 9:00 AM');
            wp_schedule_event($time, 'daily', 'chatzio_failed_topics_digest');
        }
        
        // Schedule weekly summary
        if (!wp_next_scheduled('chatzio_weekly_summary')) {
            // Schedule for Monday 9 AM
            $next_monday = strtotime('next Monday 9:00 AM');
            wp_schedule_event($next_monday, 'weekly', 'chatzio_weekly_summary');
        }

        // Schedule data retention cleanup (conversations, analytics, leads, logs, failed_topics)
        if (!wp_next_scheduled('chatzio_data_retention')) {
            wp_schedule_event(time(), 'daily', 'chatzio_data_retention');
        }

        // Schedule license validation heartbeat (PCL).
        if (class_exists('Chatzio_License')) {
            Chatzio_License::schedule_cron();
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

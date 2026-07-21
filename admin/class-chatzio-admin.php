<?php
/**
 * Admin Interface Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'render_admin_notices'], 5);
        add_action('admin_head', [$this, 'output_menu_icon_css']);

        // Hide all admin notices on Chatzio pages (default: ON)
        $settings = get_option('chatzio_settings', []);
        if (!isset($settings['hide_all_notices']) || !empty($settings['hide_all_notices'])) {
            add_action('admin_head', [$this, 'hide_all_admin_notices'], 999);
        }
    }

    /**
     * Output CSS to properly colorize the custom SVG admin menu icon.
     * WordPress applies opacity/filter to menu icons — this overrides for our SVG.
     */
    public function output_menu_icon_css() {
        echo '<style>
            #adminmenu #toplevel_page_chatzio-ai .wp-menu-image img {
                width: 20px;
                height: 20px;
                padding-top: 7px;
            }
        </style>' . "\n";
    }

    /**
     * Render plugin notices outside the plugin box (industry standard).
     * Only on Chatzio pages; use add_settings_error() or this for messages
     * so they appear above the white shell and keep WordPress notice styling.
     */
    public function render_admin_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'chatzio') === false) {
            return;
        }
        settings_errors('chatzio_settings_group');
    }

    /**
     * Hide all admin notices on Chatzio pages (WordPress allows this).
     * Uses CSS to hide notices - cleaner than removing hooks.
     */
    public function hide_all_admin_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'chatzio') === false) {
            return;
        }
        ?>
        <style>
        /* Hide all WordPress admin notices on Chatzio pages */
        .wrap.chatzio-admin ~ .notice,
        .wrap.chatzio-admin ~ .update-nag,
        .wrap.chatzio-admin ~ .is-dismissible,
        .wrap.chatzio-admin .notice,
        .wrap.chatzio-admin .update-nag,
        .wrap.chatzio-admin .is-dismissible,
        .wrap.chatzio-admin .astra-notice-wrapper,
        .wrap.chatzio-admin [class*="notice-"],
        #wpbody-content > .notice,
        #wpbody-content > .update-nag {
            display: none !important;
            visibility: hidden !important;
        }
        </style>
        <?php
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Chatzio',
            'Chatzio',
            'manage_options',
            'chatzio-ai',
            [$this, 'render_overview_page'],
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="790 370 340 345"><path fill="currentColor" d="M805.69,596.45c0-5.63-0.07-11.27,0.01-16.9c0.2-14.55,9.68-25.36,24.27-26.55c5.22-0.43,6.8-2.69,7.99-7.06c8.9-32.67,28.17-57.49,57.18-74.82c7.49-4.47,7.88-4.33,11.87,3.72c3,6.06,5.79,12.24,9.15,18.1c2.61,4.56,1.7,6.93-2.87,9.52c-24.94,14.16-39.04,35.87-44.02,63.94c-10.31,58.08,43.58,112.88,101.96,104.7c38.8-5.44,68.68-32.65,77.68-68.53c12.7-50.64-21.48-101.68-74.58-109.84c-21.78-3.35-30.76-16.65-31.84-36.95c-0.4-7.64-0.22-15.33,0.07-22.98c0.2-5.36-2.07-7.24-7.22-7.04c-5.85,0.23-11.72,0.14-17.57-0.09c-4.05-0.16-4.62-1.83-1.89-5.01c13.22-15.37,26.41-30.76,39.43-46.3c3.44-4.1,5.95-4.33,9.52-0.09c13.05,15.52,26.36,30.82,39.55,46.23c0.96,1.12,2.75,2.1,1.78,3.95c-0.86,1.65-2.73,1.39-4.25,1.41c-5.18,0.05-10.39,0.28-15.55-0.09c-5.92-0.43-8.58,1.26-8.33,7.84c0.66,17.58-2.79,21.2,20.25,27.8c43.22,12.39,70.45,42.75,83.59,85.58c1.35,4.39,2.89,5.96,7.63,6.26c14.78,0.95,24.69,12,24.81,26.91c0.09,11.72,0.13,23.44-0.06,35.16c-0.31,19.89-16.39,31.76-35.47,25.84c-4.71-1.46-5.92,0.84-7.72,3.87c-15.39,25.86-36.86,44.64-65.05,55.49c-52.39,20.16-113.71,2.71-147.41-41.9c-3.8-5.03-7.99-9.82-10.58-15.64c-1.36-3.06-2.82-3.69-6.23-2.39c-17.47,6.63-34.9-4.98-35.93-23.86c-0.37-6.74-0.06-13.52-0.06-20.28C805.74,596.45,805.72,596.45,805.69,596.45z"/><path fill="currentColor" d="M979.94,569.07c0-4.49-0.08-8.99,0.02-13.48c0.24-10.43,5.67-16.26,15.98-17.29c8.63-0.86,17.17,5.37,18.13,14.52c1.17,11.15,1.28,22.47-0.09,33.62c-1.14,9.3-9.23,15.45-17.89,14.89c-9.3-0.6-15.87-7.63-16.14-17.44c-0.14-4.94-0.02-9.88-0.02-14.82C979.92,569.07,979.93,569.07,979.94,569.07z"/><path fill="currentColor" d="M940.09,569.87c0,4.96,0.24,9.93-0.05,14.86c-0.55,9.45-7.42,16.17-16.47,16.49c-8.95,0.32-17.26-6.71-17.73-16.03c-0.53-10.56-0.47-21.18-0.07-31.75c0.34-9.02,7.8-15.08,17.53-15.13c8.75-0.04,16.18,6.58,16.73,15.33C940.37,559.04,940.09,564.47,940.09,569.87z"/></svg>'),
            30
        );
        
        add_submenu_page(
            'chatzio-ai',
            'Overview',
            'Overview',
            'manage_options',
            'chatzio-ai',
            [$this, 'render_overview_page']
        );
        
        add_submenu_page(
            'chatzio-ai',
            'Settings',
            'Settings',
            'manage_options',
            'chatzio-settings',
            [$this, 'render_settings_page']
        );
        
        add_submenu_page(
            'chatzio-ai',
            'Content Sync',
            'Content Sync',
            'manage_options',
            'chatzio-content',
            [$this, 'render_content_page']
        );
        
        add_submenu_page(
            'chatzio-ai',
            'Resources',
            'Resources',
            'manage_options',
            'chatzio-resources',
            [$this, 'render_resources_page']
        );
        
        add_submenu_page(
            'chatzio-ai',
            'Conversations',
            'Conversations',
            'manage_options',
            'chatzio-conversations',
            [$this, 'render_conversations_page']
        );

        add_submenu_page(
            'chatzio-ai',
            'Analytics',
            'Analytics',
            'manage_options',
            'chatzio-analytics',
            [$this, 'render_analytics_page']
        );

        add_submenu_page(
            'chatzio-ai',
            'Leads',
            'Leads',
            'manage_options',
            'chatzio-leads',
            [$this, 'render_leads_page']
        );

        add_submenu_page(
            'chatzio-ai',
            'Logs & Debug',
            'Logs & Debug',
            'manage_options',
            'chatzio-logs',
            [$this, 'render_logs_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('chatzio_settings_group', 'chatzio_settings', [$this, 'sanitize_settings']);
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = isset($input['enabled']) ? true : false;
        $sanitized['primary_color'] = isset($input['primary_color']) ? sanitize_hex_color($input['primary_color']) : '#4F46E5';
        $sanitized['secondary_color'] = isset($input['secondary_color']) ? sanitize_hex_color($input['secondary_color']) : '#6366F1';
        $sanitized['link_color'] = isset($input['link_color']) ? sanitize_hex_color($input['link_color']) : '#2563EB';
        $sanitized['bot_name'] = isset($input['bot_name']) ? sanitize_text_field($input['bot_name']) : 'Chatzio';
        $sanitized['welcome_message'] = isset($input['welcome_message']) ? sanitize_textarea_field($input['welcome_message']) : 'Hi! How can I help you today?';
        $sanitized['input_placeholder'] = isset($input['input_placeholder']) ? sanitize_text_field($input['input_placeholder']) : 'Type your message...';
        $sanitized['logo_url'] = isset($input['logo_url']) ? esc_url_raw($input['logo_url']) : '';
        // API key: encrypt at rest when saving (decryption happens via option_chatzio_settings filter)
        $raw_key = isset($input['openrouter_api_key']) ? sanitize_text_field($input['openrouter_api_key']) : '';
        if ($raw_key === '') {
            $current = get_option('chatzio_settings', []);
            $raw_key = isset($current['openrouter_api_key']) ? $current['openrouter_api_key'] : '';
        }
        if ($raw_key !== '') {
            $sanitized['openrouter_api_key'] = Chatzio_API_Key_Crypto::is_encrypted($raw_key)
                ? $raw_key
                : Chatzio_API_Key_Crypto::encrypt($raw_key);
        } else {
            $sanitized['openrouter_api_key'] = '';
        }
        $sanitized['openrouter_model'] = isset($input['openrouter_model']) ? sanitize_text_field($input['openrouter_model']) : 'openai/gpt-3.5-turbo';
        $sanitized['temperature'] = isset($input['temperature']) ? floatval($input['temperature']) : 0.7;
        $sanitized['max_tokens'] = isset($input['max_tokens']) ? intval($input['max_tokens']) : 2000;
        $sanitized['auto_sync_enabled'] = isset($input['auto_sync_enabled']) ? true : false;
        $sanitized['sync_posts'] = isset($input['sync_posts']) ? true : false;
        $sanitized['sync_pages'] = isset($input['sync_pages']) ? true : false;
        $sanitized['sync_products'] = isset($input['sync_products']) ? true : false;
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? true : false;
        $sanitized['hide_all_notices'] = isset($input['hide_all_notices']) ? true : false;
        $sanitized['reduce_top_gap'] = isset($input['reduce_top_gap']) ? true : false;
        
        // Bot behavior settings
        $sanitized['synced_content_prompt'] = isset($input['synced_content_prompt']) ? sanitize_textarea_field($input['synced_content_prompt']) : '';
        $sanitized['custom_prompt'] = isset($input['custom_prompt']) ? sanitize_textarea_field($input['custom_prompt']) : '';
        $sanitized['restricted_topics'] = isset($input['restricted_topics']) ? sanitize_textarea_field($input['restricted_topics']) : '';
        $sanitized['fallback_response'] = isset($input['fallback_response']) ? sanitize_textarea_field($input['fallback_response']) : '';
        $sanitized['generate_prompt_on_sync'] = !empty($input['generate_prompt_on_sync']);
        
        // Appearance settings
        $sanitized['bubble_size'] = isset($input['bubble_size']) ? sanitize_text_field($input['bubble_size']) : '60';
        $sanitized['widget_position'] = isset($input['widget_position']) && in_array($input['widget_position'], ['bottom-right', 'bottom-left', 'custom'], true) ? $input['widget_position'] : 'bottom-right';
        $sanitized['position_custom_desktop_bottom'] = isset($input['position_custom_desktop_bottom']) ? max(0, min(500, intval($input['position_custom_desktop_bottom']))) : 24;
        $sanitized['position_custom_desktop_right']   = isset($input['position_custom_desktop_right']) ? max(0, min(500, intval($input['position_custom_desktop_right']))) : 24;
        $sanitized['position_custom_mobile_bottom']   = isset($input['position_custom_mobile_bottom']) ? max(0, min(500, intval($input['position_custom_mobile_bottom']))) : 20;
        $sanitized['position_custom_mobile_right']    = isset($input['position_custom_mobile_right']) ? max(0, min(500, intval($input['position_custom_mobile_right']))) : 20;
        $sanitized['font_family'] = isset($input['font_family']) ? sanitize_text_field($input['font_family']) : 'system';
        $sanitized['font_size'] = isset($input['font_size']) ? sanitize_text_field($input['font_size']) : '14';
        $sanitized['enable_product_cards'] = isset($input['enable_product_cards']) ? true : false;

        // Quick replies, proactive message, admin CSS
        $sanitized['quick_replies'] = isset($input['quick_replies']) ? sanitize_textarea_field($input['quick_replies']) : '';
        $sanitized['proactive_message'] = isset($input['proactive_message']) ? sanitize_text_field($input['proactive_message']) : '';
        $sanitized['proactive_delay'] = isset($input['proactive_delay']) ? intval($input['proactive_delay']) : 5;
        $sanitized['admin_custom_css'] = isset($input['admin_custom_css']) ? sanitize_textarea_field($input['admin_custom_css']) : '';

        // Lead capture settings
        $sanitized['enable_lead_form'] = isset($input['enable_lead_form']) ? true : false;
        $sanitized['lead_form_heading'] = isset($input['lead_form_heading']) ? sanitize_text_field($input['lead_form_heading']) : 'Before we start, tell us about yourself';
        $sanitized['lead_form_subheading'] = isset($input['lead_form_subheading']) ? sanitize_text_field($input['lead_form_subheading']) : "We'd love to know who we're chatting with.";
        $sanitized['lead_form_name'] = isset($input['lead_form_name']) ? true : false;
        $sanitized['lead_form_phone'] = isset($input['lead_form_phone']) ? true : false;
        $sanitized['lead_form_allow_skip'] = !empty($input['lead_form_allow_skip']);
        $sanitized['enable_smart_capture'] = isset($input['enable_smart_capture']) ? true : false;

        // Notification settings
        $sanitized['notification_email'] = isset($input['notification_email']) ? sanitize_email($input['notification_email']) : '';
        $sanitized['notify_new_lead'] = isset($input['notify_new_lead']) ? true : false;
        $sanitized['notify_new_conversation'] = isset($input['notify_new_conversation']) ? true : false;
        $sanitized['notify_failed_query'] = isset($input['notify_failed_query']) ? true : false;
        $sanitized['notify_restock'] = isset($input['notify_restock']) ? true : false;

        // Topic-based notification filter
        $sanitized['notify_topics'] = [];
        if (!empty($input['notify_topics']) && is_array($input['notify_topics'])) {
            $valid_topics = class_exists('Chatzio_Topic_Resolver')
                ? array_merge(Chatzio_Topic_Resolver::get_registry(), ['general'])
                : [];
            foreach ($input['notify_topics'] as $t) {
                $t = sanitize_text_field($t);
                if (in_array($t, $valid_topics, true)) {
                    $sanitized['notify_topics'][] = $t;
                }
            }
        }

        // Data retention
        $sanitized['data_retention_days'] = isset($input['data_retention_days']) ? intval($input['data_retention_days']) : 0;

        // tab settings / Tabs
        $sanitized['widget_mode'] = isset($input['widget_mode']) && in_array($input['widget_mode'], ['tabbed', 'simple']) ? $input['widget_mode'] : 'tabbed';
        $sanitized['widget_tab_home'] = isset($input['widget_tab_home']) ? true : false;
        $sanitized['widget_tab_faq'] = isset($input['widget_tab_faq']) ? true : false;
        $sanitized['widget_tab_products'] = isset($input['widget_tab_products']) ? true : false;
        $sanitized['widget_tab_history'] = isset($input['widget_tab_history']) ? true : false;
        $sanitized['widget_tab_news'] = isset($input['widget_tab_news']) ? true : false;
        // Enforce max 5 tabs (Chat + 4 optional)
        $opt_count = (int) $sanitized['widget_tab_home'] + (int) $sanitized['widget_tab_faq'] + (int) $sanitized['widget_tab_products'] + (int) $sanitized['widget_tab_history'] + (int) $sanitized['widget_tab_news'];
        if ($opt_count > 4) {
            $sanitized['widget_tab_news'] = false;
            if (($opt_count = (int) $sanitized['widget_tab_home'] + (int) $sanitized['widget_tab_faq'] + (int) $sanitized['widget_tab_products'] + (int) $sanitized['widget_tab_history']) > 4) {
                $sanitized['widget_tab_history'] = false;
            }
            if (($opt_count = (int) $sanitized['widget_tab_home'] + (int) $sanitized['widget_tab_faq'] + (int) $sanitized['widget_tab_products']) > 4) {
                $sanitized['widget_tab_products'] = false;
            }
            if (($opt_count = (int) $sanitized['widget_tab_home'] + (int) $sanitized['widget_tab_faq']) > 4) {
                $sanitized['widget_tab_faq'] = false;
            }
        }
        $sanitized['widget_tab_style'] = isset($input['widget_tab_style']) ? sanitize_text_field($input['widget_tab_style']) : 'icons_labels';
        $sanitized['home_headline'] = isset($input['home_headline']) ? sanitize_text_field($input['home_headline']) : 'Hi there 👋';
        $sanitized['home_subtext'] = isset($input['home_subtext']) ? sanitize_text_field($input['home_subtext']) : 'How can we help you today?';
        $sanitized['home_featured_products_heading'] = isset($input['home_featured_products_heading']) ? sanitize_text_field($input['home_featured_products_heading']) : 'Featured Products';
        $sanitized['home_news_heading'] = isset($input['home_news_heading']) ? sanitize_text_field($input['home_news_heading']) : 'Latest Updates';
        $sanitized['default_starter_title'] = isset($input['default_starter_title']) ? sanitize_text_field($input['default_starter_title']) : 'Ask a question';
        $sanitized['default_starter_subtitle'] = isset($input['default_starter_subtitle']) ? sanitize_text_field($input['default_starter_subtitle']) : 'We typically reply in a few minutes';
        $sanitized['default_starter_icon'] = isset($input['default_starter_icon']) ? sanitize_text_field(mb_substr($input['default_starter_icon'], 0, 4)) : '👋';

        // Tab customization (labels, icons, default tab). Icons are flat SVG icon IDs from tab icon library.
        $tab_keys = ['home', 'chat', 'faq', 'products', 'history', 'news'];
        $default_labels = ['home' => 'Home', 'chat' => 'Chat', 'faq' => 'FAQ', 'products' => 'Products', 'history' => 'History', 'news' => 'News'];
        $default_icons = ['home' => 'home', 'chat' => 'chat', 'faq' => 'faq', 'products' => 'products', 'history' => 'history', 'news' => 'news'];
        $valid_icon_ids = function_exists('chatzio_get_tab_icon_ids') ? chatzio_get_tab_icon_ids() : $tab_keys;
        $sanitized['tab_labels'] = [];
        $sanitized['tab_icons'] = [];
        foreach ($tab_keys as $key) {
            $sanitized['tab_labels'][$key] = isset($input['tab_labels'][$key]) && is_string($input['tab_labels'][$key])
                ? sanitize_text_field(substr($input['tab_labels'][$key], 0, 20)) : (isset($default_labels[$key]) ? $default_labels[$key] : $key);
            if ($sanitized['tab_labels'][$key] === '') {
                $sanitized['tab_labels'][$key] = isset($default_labels[$key]) ? $default_labels[$key] : $key;
            }
            $icon_val = isset($input['tab_icons'][$key]) && is_string($input['tab_icons'][$key]) ? sanitize_text_field($input['tab_icons'][$key]) : '';
            $sanitized['tab_icons'][$key] = in_array($icon_val, $valid_icon_ids, true) ? $icon_val : (isset($default_icons[$key]) ? $default_icons[$key] : $key);
        }
        $sanitized['default_tab'] = isset($input['default_tab']) && in_array($input['default_tab'], $tab_keys, true) ? $input['default_tab'] : 'home';

        // Conversation starters (array of {icon, title, subtitle})
        $sanitized['conversation_starters'] = [];
        if (isset($input['conversation_starters']) && is_array($input['conversation_starters'])) {
            foreach ($input['conversation_starters'] as $starter) {
                $title = isset($starter['title']) ? sanitize_text_field($starter['title']) : '';
                // Only include starters with a title
                if (!empty($title)) {
                    $sanitized['conversation_starters'][] = [
                        'icon'     => isset($starter['icon']) ? sanitize_text_field(mb_substr($starter['icon'], 0, 4)) : '💬',
                        'title'    => $title,
                        'subtitle' => isset($starter['subtitle']) ? sanitize_text_field($starter['subtitle']) : '',
                    ];
                }
            }
        }
        
        // FAQ Items (array of {question, answer, category})
        $sanitized['faq_items'] = [];
        if (isset($input['faq_items']) && is_array($input['faq_items'])) {
            foreach ($input['faq_items'] as $faq) {
                $question = isset($faq['question']) ? sanitize_text_field($faq['question']) : '';
                if (!empty($question)) {
                    $sanitized['faq_items'][] = [
                        'question' => $question,
                        'answer'   => isset($faq['answer']) ? sanitize_textarea_field($faq['answer']) : '',
                        'category' => isset($faq['category']) ? sanitize_text_field($faq['category']) : '',
                    ];
                }
            }
        }
        
        // News Items (array of {id, title, description, url, date, image_url}) — featured is controlled by news_featured on Home
        $sanitized['news_items'] = [];
        if (isset($input['news_items']) && is_array($input['news_items'])) {
            foreach ($input['news_items'] as $news) {
                $title = isset($news['title']) ? sanitize_text_field($news['title']) : '';
                if (!empty($title)) {
                    $id = isset($news['id']) && is_string($news['id']) ? sanitize_key($news['id']) : sanitize_key('n' . uniqid());
                    $sanitized['news_items'][] = [
                        'id'          => $id,
                        'title'       => $title,
                        'description' => isset($news['description']) ? sanitize_text_field($news['description']) : '',
                        'url'         => isset($news['url']) ? esc_url_raw($news['url']) : '',
                        'date'        => isset($news['date']) ? sanitize_text_field($news['date']) : '',
                        'image_url'   => isset($news['image_url']) ? esc_url_raw($news['image_url']) : '',
                    ];
                }
            }
        }

        // News featured (IDs to show on Home tab) — set from Home tab multiselect
        $sanitized['news_featured'] = [];
        if (isset($input['news_featured']) && is_array($input['news_featured'])) {
            $valid_ids = array_column($sanitized['news_items'], 'id');
            foreach ($input['news_featured'] as $nid) {
                if (is_string($nid) && in_array(sanitize_key($nid), $valid_ids, true)) {
                    $sanitized['news_featured'][] = sanitize_key($nid);
                }
            }
        }

        // Products Control Settings
        $sanitized['products_tab_heading'] = isset($input['products_tab_heading']) && is_string($input['products_tab_heading']) ? sanitize_text_field(substr($input['products_tab_heading'], 0, 80)) : 'Browse Products';
        if ($sanitized['products_tab_heading'] === '') {
            $sanitized['products_tab_heading'] = 'Browse Products';
        }
        $sanitized['products_tab_source'] = isset($input['products_tab_source']) && in_array($input['products_tab_source'], ['category', 'featured', 'bestsellers', 'onsale', 'specific_products'], true) ? $input['products_tab_source'] : 'category';

        // Categories to display (array of category term IDs) — used only when source is 'category'
        $sanitized['products_categories'] = [];
        if (isset($input['products_categories']) && is_array($input['products_categories'])) {
            foreach ($input['products_categories'] as $cat_id) {
                $cat_id = absint($cat_id);
                if ($cat_id > 0 && term_exists($cat_id, 'product_cat')) {
                    $sanitized['products_categories'][] = $cat_id;
                }
            }
        }

        // Product tags filter (convert textarea to array)
        $sanitized['products_tags'] = [];
        if (isset($input['products_tags_text']) && is_string($input['products_tags_text'])) {
            $tags_text = sanitize_textarea_field($input['products_tags_text']);
            $tags_lines = array_filter(array_map('trim', explode("\n", $tags_text)));
            foreach ($tags_lines as $tag) {
                $tag = sanitize_text_field($tag);
                if (!empty($tag) && strlen($tag) <= 100) {
                    $sanitized['products_tags'][] = $tag;
                }
            }
        }

        // Highlighted products (array of product IDs)
        $sanitized['products_highlight'] = [];
        if (isset($input['products_highlight']) && is_array($input['products_highlight'])) {
            foreach ($input['products_highlight'] as $product_id) {
                $product_id = absint($product_id);
                if ($product_id > 0 && get_post_type($product_id) === 'product') {
                    $sanitized['products_highlight'][] = $product_id;
                }
            }
            // Limit to 50 highlighted products
            $sanitized['products_highlight'] = array_slice($sanitized['products_highlight'], 0, 50);
        }

        // Featured products for home tab (array of product IDs)
        $sanitized['products_featured'] = [];
        if (isset($input['products_featured']) && is_array($input['products_featured'])) {
            foreach ($input['products_featured'] as $product_id) {
                $product_id = absint($product_id);
                if ($product_id > 0 && get_post_type($product_id) === 'product') {
                    $sanitized['products_featured'][] = $product_id;
                }
            }
            // Limit to 6 featured products (for home tab display)
            $sanitized['products_featured'] = array_slice($sanitized['products_featured'], 0, 6);
        }

        // Clear FAQ cache when product settings change (to refresh category filters)
        delete_transient('chatzio_faq_data_v2');
        
        return $sanitized;
    }
    
    /**
     * Render overview dashboard page
     */
    public function render_overview_page() {
        $data = $this->get_overview_data();
        include CHATZIO_PLUGIN_DIR . 'admin/views/overview-page.php';
    }
    
    /**
     * Get overview dashboard data
     */
    private function get_overview_data() {
        global $wpdb;
        
        $data = [];
        $conversations_table = $wpdb->prefix . 'chatzio_conversations';
        $leads_table = $wpdb->prefix . 'chatzio_leads';
        $content_table = $wpdb->prefix . 'chatzio_content';
        $resources_table = $wpdb->prefix . 'chatzio_resources';
        $logs_table = $wpdb->prefix . 'chatzio_logs';
        
        // --- Core counts (match Analytics: one conversation = one unique session) ---
        $data['total_conversations'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $conversations_table");
        
        $data['today_conversations'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $conversations_table WHERE created_at >= %s AND created_at <= %s",
            current_time('Y-m-d') . ' 00:00:00',
            current_time('Y-m-d') . ' 23:59:59'
        ));
        
        $week_start = date('Y-m-d', strtotime('-7 days', current_time('timestamp'))) . ' 00:00:00';
        $data['week_conversations'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $conversations_table WHERE created_at >= %s",
            $week_start
        ));
        
        // Previous 7 days (8–14 days ago) for comparison
        $prev_week_start = date('Y-m-d', strtotime('-14 days', current_time('timestamp'))) . ' 00:00:00';
        $data['prev_week_conversations'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM $conversations_table WHERE created_at >= %s AND created_at < %s",
            $prev_week_start,
            $week_start
        ));
        
        $data['total_leads'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
        
        $data['week_leads'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE created_at >= %s",
            date('Y-m-d', strtotime('-7 days'))
        ));
        
        // Previous week leads for comparison
        $data['prev_week_leads'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE created_at >= %s AND created_at < %s",
            date('Y-m-d', strtotime('-14 days')),
            date('Y-m-d', strtotime('-7 days'))
        ));
        
        // --- Content & Resources ---
        $data['total_content'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $content_table");
        
        // Content breakdown by type
        $breakdown_rows = $wpdb->get_results(
            "SELECT content_type, COUNT(*) as count FROM $content_table GROUP BY content_type",
            ARRAY_A
        );
        $data['content_breakdown'] = ['post' => 0, 'page' => 0, 'product' => 0];
        foreach ($breakdown_rows as $row) {
            $data['content_breakdown'][$row['content_type']] = (int) $row['count'];
        }
        
        // Total resources
        $res_exists = $wpdb->get_var("SHOW TABLES LIKE '$resources_table'");
        $data['total_resources'] = $res_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $resources_table WHERE status = 'active'") : 0;
        
        // Last sync time
        $data['last_sync'] = get_option('chatzio_last_sync', '');
        
        // --- Errors ---
        $data['recent_errors'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $logs_table WHERE log_type = 'error' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        // --- Settings summary ---
        $settings = get_option('chatzio_settings', []);
        $data['plugin_enabled'] = !empty($settings['enabled']);
        $data['api_configured'] = !empty($settings['openrouter_api_key']);
        $data['bot_name'] = !empty($settings['bot_name']) ? $settings['bot_name'] : 'Chatzio';
        $data['model'] = !empty($settings['openrouter_model']) ? $settings['openrouter_model'] : 'Not set';
        $data['widget_mode'] = !empty($settings['widget_mode']) ? $settings['widget_mode'] : 'simple';
        
        $lead_prechat = !empty($settings['enable_lead_form']);
        $lead_inchat  = !empty($settings['enable_smart_capture']);
        if ($lead_prechat && $lead_inchat) {
            $data['lead_capture_status'] = 'Pre-chat + In-chat';
        } elseif ($lead_prechat) {
            $data['lead_capture_status'] = 'Pre-chat only';
        } elseif ($lead_inchat) {
            $data['lead_capture_status'] = 'In-chat only';
        } else {
            $data['lead_capture_status'] = 'Disabled';
        }
        
        // --- Recent conversations (last 5) ---
        $data['recent_conversations'] = $wpdb->get_results(
            "SELECT session_id, messages, created_at FROM $conversations_table ORDER BY created_at DESC LIMIT 5",
            ARRAY_A
        );
        
        // --- Recent leads (last 5) with source ---
        $data['recent_leads'] = $wpdb->get_results(
            "SELECT email, name, source, created_at, conversation_count FROM $leads_table ORDER BY created_at DESC LIMIT 5",
            ARRAY_A
        );
        
        return $data;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $settings = get_option('chatzio_settings', []);
        include CHATZIO_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
    
    /**
     * Render content sync page
     */
    public function render_content_page() {
        $stats = Chatzio_Content_Sync::get_sync_stats();
        include CHATZIO_PLUGIN_DIR . 'admin/views/content-page.php';
    }
    
    /**
     * Render resources page
     */
    public function render_resources_page() {
        $resources = Chatzio_Resource_Manager::get_resources();
        include CHATZIO_PLUGIN_DIR . 'admin/views/resources-page.php';
    }
    
    /**
     * Render conversations page
     */
    public function render_conversations_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ), ARRAY_A);
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        include CHATZIO_PLUGIN_DIR . 'admin/views/conversations-page.php';
    }
    
    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        include CHATZIO_PLUGIN_DIR . 'admin/views/analytics-page.php';
    }

    /**
     * Render leads page
     */
    public function render_leads_page() {
        $leads = Chatzio_Lead_Manager::get_leads(['limit' => 50]);
        $total_leads = Chatzio_Lead_Manager::get_lead_count();
        include CHATZIO_PLUGIN_DIR . 'admin/views/leads-page.php';
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        $stats = Chatzio_Logger::get_stats();
        
        $type = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : null;
        $logs = Chatzio_Logger::get_logs($type, 100);
        
        include CHATZIO_PLUGIN_DIR . 'admin/views/logs-page.php';
    }
}

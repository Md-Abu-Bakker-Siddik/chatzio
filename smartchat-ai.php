<?php
/**
 * Plugin Name: Chatzio
 * Plugin URI: https://instaquirk.com
 * Description: Intelligent AI chatbot powered by OpenRouter with automatic content sync, resource management, and beautiful UI
 * Version: 5.4.1
 * Author: Instaquirk
 * Author URI: https://instaquirk.com
 * License: GPL v2 or later
 * Text Domain: chatzio-ai
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CHATZIO_VERSION', '5.4.1');
define('CHATZIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHATZIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATZIO_PLUGIN_FILE', __FILE__);

/**
 * Asset version for cache busting. When WP_DEBUG is on, uses file mtime so changes are picked up without reactivating.
 */
function chatzio_asset_version($relative_path = '') {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return CHATZIO_VERSION;
    }
    $path = CHATZIO_PLUGIN_DIR . $relative_path;
    return CHATZIO_VERSION . '.' . (file_exists($path) ? filemtime($path) : time());
}

// Include required files
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-activator.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-deactivator.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-database.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-embeddings.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-openrouter.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-content-sync.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-resource-manager.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-logger.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-rate-limiter.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-request-cache.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-ajax.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-stream.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-lead-manager.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-notifications.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-product-cards.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-failed-topics.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-topic-resolver.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-woocommerce.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/order-tools/class-chatzio-order-input-validator.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/order-tools/class-chatzio-order-authorization.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-order-tracking.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-restock.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-privacy.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/class-chatzio-api-key-crypto.php';
require_once CHATZIO_PLUGIN_DIR . 'includes/tab-icon-library.php';

// Decrypt API key when settings are read (so code always sees plain key; DB stores encrypted)
add_filter('option_chatzio_settings', ['Chatzio_API_Key_Crypto', 'decrypt_api_key_in_settings'], 10, 1);

if (is_admin()) {
    require_once CHATZIO_PLUGIN_DIR . 'admin/class-chatzio-admin.php';
}

/**
 * Main Plugin Class
 */
class Chatzio_AI {
    
    private static $instance = null;
    private static $widget_rendered = false;
    private static $restock_css_printed = false;
    private static $restock_variation_js_printed = false;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, ['Chatzio_Activator', 'activate']);
        register_deactivation_hook(__FILE__, ['Chatzio_Deactivator', 'deactivate']);
        
        // Init plugin
        add_action('plugins_loaded', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Render chatbot in footer — host element and script appear together,
        // preventing position flash on iOS Safari.
        add_action('wp_footer', [$this, 'render_chatbot_widget']);
        
        // AJAX handlers (public)
        add_action('wp_ajax_chatzio_send_message', ['Chatzio_AJAX', 'handle_send_message']);
        add_action('wp_ajax_nopriv_chatzio_send_message', ['Chatzio_AJAX', 'handle_send_message']);
        add_action('wp_ajax_chatzio_feedback', ['Chatzio_AJAX', 'handle_feedback']);
        add_action('wp_ajax_nopriv_chatzio_feedback', ['Chatzio_AJAX', 'handle_feedback']);

        // Auto-sync cron handler
        add_action('chatzio_auto_sync', ['Chatzio_Content_Sync', 'sync_all_content']);

        // Notification cron handlers
        add_action('chatzio_failed_topics_digest', ['Chatzio_Notifications', 'send_failed_topics_digest']);
        add_action('chatzio_weekly_summary', ['Chatzio_Notifications', 'send_weekly_summary']);

        // REST API for streaming
        add_action('rest_api_init', ['Chatzio_Stream', 'register_routes']);

        // Lead capture AJAX (public)
        add_action('wp_ajax_chatzio_capture_lead', ['Chatzio_Lead_Manager', 'handle_capture_lead']);
        add_action('wp_ajax_nopriv_chatzio_capture_lead', ['Chatzio_Lead_Manager', 'handle_capture_lead']);

        // Analytics event tracking (public)
        add_action('wp_ajax_chatzio_track_event', ['Chatzio_AJAX', 'handle_track_event']);
        add_action('wp_ajax_nopriv_chatzio_track_event', ['Chatzio_AJAX', 'handle_track_event']);

        // FAQ data for frontend (public)
        add_action('wp_ajax_chatzio_get_faq', ['Chatzio_AJAX', 'handle_get_faq']);
        add_action('wp_ajax_nopriv_chatzio_get_faq', ['Chatzio_AJAX', 'handle_get_faq']);

        // Backward-compat aliases (old action names), keeps cached/legacy clients working
        add_action('wp_ajax_smartchat_send_message', ['Chatzio_AJAX', 'handle_send_message']);
        add_action('wp_ajax_nopriv_smartchat_send_message', ['Chatzio_AJAX', 'handle_send_message']);
        add_action('wp_ajax_smartchat_feedback', ['Chatzio_AJAX', 'handle_feedback']);
        add_action('wp_ajax_nopriv_smartchat_feedback', ['Chatzio_AJAX', 'handle_feedback']);
        add_action('wp_ajax_smartchat_capture_lead', ['Chatzio_Lead_Manager', 'handle_capture_lead']);
        add_action('wp_ajax_nopriv_smartchat_capture_lead', ['Chatzio_Lead_Manager', 'handle_capture_lead']);
        add_action('wp_ajax_smartchat_track_event', ['Chatzio_AJAX', 'handle_track_event']);
        add_action('wp_ajax_nopriv_smartchat_track_event', ['Chatzio_AJAX', 'handle_track_event']);
        add_action('wp_ajax_smartchat_get_faq', ['Chatzio_AJAX', 'handle_get_faq']);
        add_action('wp_ajax_nopriv_smartchat_get_faq', ['Chatzio_AJAX', 'handle_get_faq']);

        // Data retention cron
        add_action('chatzio_data_retention', [__CLASS__, 'run_data_retention']);

        // Shortcode
        add_shortcode('chatzio', [$this, 'render_shortcode']);
        add_shortcode('smartchat', [$this, 'render_shortcode']); // Backward-compat alias
        add_shortcode('chatzio_restock', [$this, 'render_restock_shortcode']);
    }
    
    public function init() {
        load_plugin_textdomain('chatzio-ai', false, dirname(plugin_basename(__FILE__)) . '/languages');

        Chatzio_Database::init();
        Chatzio_Logger::init();
        Chatzio_WooCommerce::init();
        Chatzio_Privacy::register();

        if (is_admin()) {
            new Chatzio_Admin();
        }
    }
    
    public function enqueue_frontend_assets() {
        $settings = get_option('chatzio_settings', []);
        
        // Don't load any assets if widget is disabled
        $enabled = isset($settings['enabled']) ? $settings['enabled'] : true;
        if (!$enabled) {
            return;
        }
        
        // Optional: reduce gap below browser when admin bar is visible (logged-in view)
        if (!empty($settings['reduce_top_gap'])) {
            wp_register_style('chatzio-reduce-top-gap', false);
            wp_enqueue_style('chatzio-reduce-top-gap');
            wp_add_inline_style('chatzio-reduce-top-gap', 'body.admin-bar { padding-top: 32px !important; }');
        }
        
        // Font family settings
        $font_family = isset($settings['font_family']) ? $settings['font_family'] : 'system';
        
        // Load Google Font if not using system font
        // @font-face declarations work through Shadow DOM boundary
        if ($font_family !== 'system') {
            $google_fonts = [
                'inter' => 'Inter:wght@400;500;600',
                'roboto' => 'Roboto:wght@400;500;700',
                'opensans' => 'Open+Sans:wght@400;500;600',
                'lato' => 'Lato:wght@400;700',
                'poppins' => 'Poppins:wght@400;500;600',
            ];
            if (isset($google_fonts[$font_family])) {
                wp_enqueue_style(
                    'chatzio-google-font',
                    'https://fonts.googleapis.com/css2?family=' . $google_fonts[$font_family] . '&display=swap',
                    [],
                    null
                );
            }
        }
        
        // CSS is loaded inside Shadow DOM via <link> for proper caching + no JSON bloat.
        // Pass the URL (with cache-busting version) to JS, which injects it as a <link>.
        $css_url = CHATZIO_PLUGIN_URL . 'assets/css/frontend.css?ver=' . chatzio_asset_version('assets/css/frontend.css');
        
        // Font family CSS value mapping
        $font_families = [
            'system' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
            'inter' => "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
            'roboto' => "'Roboto', -apple-system, BlinkMacSystemFont, sans-serif",
            'opensans' => "'Open Sans', -apple-system, BlinkMacSystemFont, sans-serif",
            'lato' => "'Lato', -apple-system, BlinkMacSystemFont, sans-serif",
            'poppins' => "'Poppins', -apple-system, BlinkMacSystemFont, sans-serif",
        ];
        $font_family_css = isset($font_families[$font_family]) ? $font_families[$font_family] : $font_families['system'];
        
        // Enqueue JS — no jQuery dependency, loaded in footer
        wp_enqueue_script(
            'chatzio-frontend',
            CHATZIO_PLUGIN_URL . 'assets/js/frontend.js',
            [],
            chatzio_asset_version('assets/js/frontend.js'),
            true
        );
        
        // Preload hints: browser downloads files immediately from <head>
        // while parsing the rest of the page. Doesn't execute until footer.
        $js_url = CHATZIO_PLUGIN_URL . 'assets/js/frontend.js?ver=' . chatzio_asset_version('assets/js/frontend.js');
        add_action('wp_head', function () use ($js_url, $css_url) {
            echo '<link rel="preload" href="' . esc_url($js_url) . '" as="script">' . "\n";
            echo '<link rel="preload" href="' . esc_url($css_url) . '" as="style">' . "\n";
        }, 1);
        
        // Parse quick replies (one per line)
        $quick_replies_raw = isset($settings['quick_replies']) ? trim($settings['quick_replies']) : '';
        $quick_replies = [];
        if (!empty($quick_replies_raw)) {
            $quick_replies = array_values(array_filter(array_map('trim', explode("\n", $quick_replies_raw))));
        }
        
        // Pass all config to JavaScript
        $frontend_data = [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'streamUrl' => '', // Disabled: streaming showed raw markdown first, then jump to formatted—use AJAX for consistent formatting & product cards
            'nonce'     => wp_create_nonce('chatzio_nonce'),
            'cssUrl'    => $css_url,
            'settings'  => [
                'primaryColor'      => isset($settings['primary_color']) ? $settings['primary_color'] : '#4F46E5',
                'secondaryColor'    => isset($settings['secondary_color']) ? $settings['secondary_color'] : '#6366F1',
                'linkColor'         => isset($settings['link_color']) ? $settings['link_color'] : '#2563EB',
                'botName'           => isset($settings['bot_name']) ? $settings['bot_name'] : 'Chatzio',
                'welcomeMessage'    => isset($settings['welcome_message']) ? $settings['welcome_message'] : 'Hi! How can I help you today?',
                'placeholder'       => isset($settings['input_placeholder']) ? $settings['input_placeholder'] : 'Type your message...',
                'logo'              => isset($settings['logo_url']) ? $settings['logo_url'] : '',
                'fontSize'          => isset($settings['font_size']) ? $settings['font_size'] : '14',
                'fontFamilyCSS'     => $font_family_css,
                'bubbleSize'        => isset($settings['bubble_size']) ? $settings['bubble_size'] : '60',
                'widgetPosition'    => isset($settings['widget_position']) && in_array($settings['widget_position'], ['bottom-right', 'bottom-left', 'custom'], true) ? $settings['widget_position'] : 'bottom-right',
                'positionCustomDesktopBottom' => isset($settings['position_custom_desktop_bottom']) ? (int) $settings['position_custom_desktop_bottom'] : 24,
                'positionCustomDesktopRight'  => isset($settings['position_custom_desktop_right']) ? (int) $settings['position_custom_desktop_right'] : 24,
                'positionCustomMobileBottom'   => isset($settings['position_custom_mobile_bottom']) ? (int) $settings['position_custom_mobile_bottom'] : 20,
                'positionCustomMobileRight'    => isset($settings['position_custom_mobile_right']) ? (int) $settings['position_custom_mobile_right'] : 20,
                'quickReplies'      => $quick_replies,
                'proactiveMessage'  => isset($settings['proactive_message']) ? $settings['proactive_message'] : '',
                'proactiveDelay'    => isset($settings['proactive_delay']) ? intval($settings['proactive_delay']) : 0,
                'enableLeadForm'    => isset($settings['enable_lead_form']) ? (bool) $settings['enable_lead_form'] : false,
                'isUserLoggedIn'    => is_user_logged_in(),
                'leadFormHeading'   => isset($settings['lead_form_heading']) ? $settings['lead_form_heading'] : 'Before we start, tell us about yourself',
                'leadFormSubheading' => isset($settings['lead_form_subheading']) ? $settings['lead_form_subheading'] : "We'd love to know who we're chatting with.",
                'leadFormFields'    => [
                    'name'  => isset($settings['lead_form_name']) ? (bool) $settings['lead_form_name'] : true,
                    'email' => true, // Always required if form is enabled
                    'phone' => isset($settings['lead_form_phone']) ? (bool) $settings['lead_form_phone'] : false,
                ],
                'leadFormAllowSkip'  => isset($settings['lead_form_allow_skip']) ? (bool) $settings['lead_form_allow_skip'] : true,
                'enableSmartCapture' => false, // Disabled - Smart In-Chat Capture hidden from admin but code kept for future use
                // Widget mode and tabs configuration
                'widgetMode'          => isset($settings['widget_mode']) ? $settings['widget_mode'] : 'tabbed',
                'widgetTabs'          => [
                    'home'     => isset($settings['widget_tab_home']) ? (bool) $settings['widget_tab_home'] : true,
                    'chat'     => true, // Always enabled
                    'faq'      => isset($settings['widget_tab_faq']) ? (bool) $settings['widget_tab_faq'] : false,
                    'products' => isset($settings['widget_tab_products']) ? (bool) $settings['widget_tab_products'] : false,
                    'history'  => isset($settings['widget_tab_history']) ? (bool) $settings['widget_tab_history'] : false,
                    'news'     => isset($settings['widget_tab_news']) ? (bool) $settings['widget_tab_news'] : false,
                ],
                'widgetTabStyle'      => isset($settings['widget_tab_style']) ? $settings['widget_tab_style'] : 'icons_labels',
                'tabLabels'           => isset($settings['tab_labels']) && is_array($settings['tab_labels']) ? $settings['tab_labels'] : ['home' => 'Home', 'chat' => 'Chat', 'faq' => 'FAQ', 'products' => 'Products', 'history' => 'History', 'news' => 'News'],
                'tabIcons'            => chatzio_normalize_tab_icons_for_frontend(isset($settings['tab_icons']) && is_array($settings['tab_icons']) ? $settings['tab_icons'] : []),
                'tabIconLibrary'      => chatzio_get_tab_icon_library_flat(),
                'defaultTab'          => isset($settings['default_tab']) && in_array($settings['default_tab'], ['home', 'chat', 'faq', 'products', 'history', 'news'], true) ? $settings['default_tab'] : 'home',
                'homeHeadline'        => isset($settings['home_headline']) ? $settings['home_headline'] : 'Hi there 👋',
                'homeSubtext'         => isset($settings['home_subtext']) ? $settings['home_subtext'] : 'How can we help you today?',
                'homeFeaturedProductsHeading' => isset($settings['home_featured_products_heading']) ? $settings['home_featured_products_heading'] : 'Featured Products',
                'homeNewsHeading'     => isset($settings['home_news_heading']) ? $settings['home_news_heading'] : 'Latest Updates',
                'defaultStarterTitle'   => isset($settings['default_starter_title']) ? $settings['default_starter_title'] : 'Ask a question',
                'defaultStarterSubtitle' => isset($settings['default_starter_subtitle']) ? $settings['default_starter_subtitle'] : 'We typically reply in a few minutes',
                'defaultStarterIcon'     => isset($settings['default_starter_icon']) ? $settings['default_starter_icon'] : '👋',
                'conversationStarters' => isset($settings['conversation_starters']) && is_array($settings['conversation_starters']) ? $settings['conversation_starters'] : [],
                'newsItems'            => isset($settings['news_items']) && is_array($settings['news_items']) ? $settings['news_items'] : [],
                'newsFeatured'         => isset($settings['news_featured']) && is_array($settings['news_featured']) ? $settings['news_featured'] : [],
                // Products Control Settings
                'productsTabHeading'  => isset($settings['products_tab_heading']) ? $settings['products_tab_heading'] : 'Browse Products',
                'productsFeatured'    => isset($settings['products_featured']) && is_array($settings['products_featured']) ? array_map('intval', $settings['products_featured']) : [],
                'productsHighlight'   => isset($settings['products_highlight']) && is_array($settings['products_highlight']) ? array_map('intval', $settings['products_highlight']) : [],
                'notifyRestock'       => !empty($settings['notify_restock']),
            ]
        ];
        
        // Keep legacy global for cached clients during rename migration.
        wp_localize_script('chatzio-frontend', 'chatzioData', $frontend_data);
        wp_localize_script('chatzio-frontend', 'smartchatData', $frontend_data);
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'chatzio') === false) {
            return;
        }
        
        // Coloris color picker (replaces wp-color-picker — cleaner, no conflicts)
        wp_enqueue_style(
            'chatzio-coloris',
            'https://cdn.jsdelivr.net/gh/mdbassit/Coloris@latest/dist/coloris.min.css',
            [],
            null
        );
        wp_enqueue_script(
            'chatzio-coloris',
            'https://cdn.jsdelivr.net/gh/mdbassit/Coloris@latest/dist/coloris.min.js',
            [],
            null,
            true
        );

        // Flatpickr date picker (lightweight, ~6KB gzip, no dependencies)
        wp_enqueue_style(
            'chatzio-flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'chatzio-flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr',
            [],
            '4.6.13',
            true
        );

        // Load admin CSS
        wp_enqueue_style(
            'chatzio-admin',
            CHATZIO_PLUGIN_URL . 'assets/css/admin.css',
            ['chatzio-coloris'],
            chatzio_asset_version('assets/css/admin.css')
        );
        
        // Output custom CSS from settings (loaded inline after main CSS for highest priority)
        $settings = get_option('chatzio_settings', []);
        $custom_css = isset($settings['admin_custom_css']) ? $settings['admin_custom_css'] : '';
        if (!empty($custom_css)) {
            wp_add_inline_style('chatzio-admin', $custom_css);
        }
        
        wp_enqueue_script(
            'chatzio-admin',
            CHATZIO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            chatzio_asset_version('assets/js/admin.js'),
            true
        );
        
        $tab_library = chatzio_get_tab_icon_library();
        wp_localize_script('chatzio-admin', 'chatzioAdmin', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'adminUrl'       => admin_url(),
            'nonce'          => wp_create_nonce('chatzio_admin_nonce'),
            'tabIconLibrary' => $tab_library,
            'settingsUrl'    => admin_url('admin.php?page=chatzio-settings') . '#behavior',
        ]);
        
        wp_enqueue_media();
    }
    
    public function render_chatbot_widget() {
        if (self::$widget_rendered) {
            return;
        }

        $settings = get_option('chatzio_settings', []);
        $enabled = isset($settings['enabled']) ? $settings['enabled'] : true;
        
        if (!$enabled) {
            return;
        }
        
        include CHATZIO_PLUGIN_DIR . 'templates/chatbot-widget.php';
        self::$widget_rendered = true;
    }

    /**
     * Shortcode [chatzio] for inline embedding
     */
    public function render_shortcode($atts) {
        $settings = get_option('chatzio_settings', []);
        $enabled = isset($settings['enabled']) ? $settings['enabled'] : true;
        if (!$enabled) return '';

        // Enqueue assets if not already
        $this->enqueue_frontend_assets();

        ob_start();
        include CHATZIO_PLUGIN_DIR . 'templates/chatbot-widget.php';
        return ob_get_clean();
    }

    /**
     * Shortcode [chatzio_restock] for product page restock button.
     * Auto-detects current WooCommerce product. Renders only if OOS and feature enabled.
     */
    public function render_restock_shortcode($atts) {
        if (!class_exists('WooCommerce')) return '';

        $settings = get_option('chatzio_settings', []);
        if (empty($settings['notify_restock'])) return '';

        $atts = shortcode_atts(['product_id' => 0], $atts, 'chatzio_restock');
        $product_id = (int) $atts['product_id'];

        // Auto-detect product on WooCommerce single product page
        if (!$product_id && function_exists('is_product') && is_product()) {
            global $product;
            if ($product && is_a($product, 'WC_Product')) {
                $product_id = $product->get_id();
            }
        }

        if (!$product_id) return '';

        $wc_product = wc_get_product($product_id);
        if (!$wc_product) return '';

        $is_variable = $wc_product->is_type('variable');
        // For simple products, only render when out of stock.
        // For variable products, always render — JS toggles based on selected variant's stock.
        if (!$is_variable && $wc_product->get_stock_status() !== 'outofstock') return '';

        // Enqueue frontend assets so AJAX config is available
        $this->enqueue_frontend_assets();

        // Inject shortcode-specific CSS into the main document (once).
        // Shadow DOM encapsulates frontend.css, so shortcode elements need their own styles.
        $primary = !empty($settings['primary_color']) ? $settings['primary_color'] : '#4F46E5';
        if (!self::$restock_css_printed) {
            self::$restock_css_printed = true;
            $css = '<style id="chatzio-restock-css">'
                // Button styles
                . '.chatzio-restock-shortcode{display:inline-block;}'
                . '.chatzio-restock-shortcode .pc-notify-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;'
                    . 'background:' . esc_attr($primary) . ';color:#fff;border:none;border-radius:8px;cursor:pointer;'
                    . 'font-size:13px;font-weight:600;font-family:inherit;line-height:1.3;white-space:nowrap;transition:opacity .2s;}'
                . '.chatzio-restock-shortcode .pc-notify-btn svg{width:18px;height:18px;flex-shrink:0;}'
                . '.chatzio-restock-shortcode .pc-notify-btn:hover:not(:disabled){opacity:.85;}'
                . '.chatzio-restock-shortcode .pc-notify-btn:disabled{cursor:default;}'
                . '.chatzio-restock-shortcode .pc-notify-btn.pc-notify-processing{opacity:.6;cursor:wait;}'
                // Confirmation message
                . '.chatzio-restock-shortcode .pc-notify-confirmation{display:flex;align-items:center;gap:6px;padding:8px 0;font-size:13px;font-weight:600;color:' . esc_attr($primary) . ';}'
                . '.chatzio-restock-shortcode .pc-notify-confirmation svg{width:16px;height:16px;flex-shrink:0;}'
                // Modal overlay
                . '.chatzio-restock-modal-overlay{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;'
                    . 'background:rgba(0,0,0,0);backdrop-filter:blur(0);transition:background .25s,backdrop-filter .25s;}'
                . '.chatzio-restock-modal-overlay.chatzio-restock-modal-visible{background:rgba(0,0,0,.45);backdrop-filter:blur(4px);}'
                // Modal box
                . '.chatzio-restock-modal{position:relative;background:#fff;border-radius:16px;padding:32px 28px;width:90%;max-width:380px;'
                    . 'box-shadow:0 20px 60px rgba(0,0,0,.2);text-align:center;'
                    . 'transform:translateY(20px) scale(.96);opacity:0;transition:transform .25s ease,opacity .25s ease;}'
                . '.chatzio-restock-modal-visible .chatzio-restock-modal{transform:translateY(0) scale(1);opacity:1;}'
                // Close button
                . '.chatzio-restock-modal-close{position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;'
                    . 'color:#94a3b8;cursor:pointer;padding:4px 8px;line-height:1;transition:color .15s;}'
                . '.chatzio-restock-modal-close:hover{color:#334155;}'
                // Icon
                . '.chatzio-restock-modal-icon{display:flex;align-items:center;justify-content:center;width:52px;height:52px;'
                    . 'margin:0 auto 16px;background:' . esc_attr($primary) . '14;border-radius:50%;color:' . esc_attr($primary) . ';}'
                . '.chatzio-restock-modal-icon svg{width:26px;height:26px;}'
                // Title & subtitle
                . '.chatzio-restock-modal-title{margin:0 0 6px;font-size:18px;font-weight:700;color:#1e293b;}'
                . '.chatzio-restock-modal-subtitle{margin:0 0 20px;font-size:13px;line-height:1.5;color:#64748b;}'
                . '.chatzio-restock-modal-subtitle strong{color:#334155;}'
                // Form
                . '.chatzio-restock-modal-form{display:flex;flex-direction:column;gap:10px;}'
                . '.chatzio-restock-modal-email{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;'
                    . 'font-family:inherit;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box;}'
                . '.chatzio-restock-modal-email:focus{border-color:' . esc_attr($primary) . ';box-shadow:0 0 0 3px ' . esc_attr($primary) . '20;}'
                . '.chatzio-restock-modal-email.chatzio-restock-modal-input-error{border-color:#ef4444;}'
                . '.chatzio-restock-modal-submit{width:100%;padding:12px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;'
                    . 'font-family:inherit;color:#fff;background:' . esc_attr($primary) . ';cursor:pointer;transition:opacity .2s;}'
                . '.chatzio-restock-modal-submit:hover:not(:disabled){opacity:.9;}'
                . '.chatzio-restock-modal-submit:disabled{opacity:.6;cursor:wait;}'
                . '</style>';
            echo $css;
        }

        $title = esc_attr($wc_product->get_name());
        $bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>';

        // Variable products: hide by default, show only when selected variant is out of stock
        $wrapper_style = $is_variable ? ' style="display:none"' : '';
        $wrapper_data = $is_variable ? ' data-variable="1"' : '';

        $html = '<div class="chatzio-restock-shortcode"' . $wrapper_data . $wrapper_style . '>' .
            '<button type="button" class="pc-notify-btn chatzio-restock-btn" data-product-id="' . esc_attr($product_id) . '" data-product-title="' . $title . '">' .
                $bell_svg .
                '<span>Notify Me When Available</span>' .
            '</button>' .
        '</div>';

        // For variable products, attach variation listener once per page
        if ($is_variable && !self::$restock_variation_js_printed) {
            self::$restock_variation_js_printed = true;
            $html .= '<script>(function(){' .
                'function init(){' .
                    'if(typeof jQuery==="undefined")return;' .
                    'jQuery(function($){' .
                        '$(".variations_form").each(function(){' .
                            'var $form=$(this),$wraps=$(".chatzio-restock-shortcode[data-variable=\"1\"]");' .
                            'if(!$wraps.length)return;' .
                            '$form.on("found_variation",function(e,variation){' .
                                '$wraps.each(function(){' .
                                    'var $w=$(this),$btn=$w.find(".chatzio-restock-btn");' .
                                    'if(variation && !variation.is_in_stock){' .
                                        '$btn.attr("data-product-id",variation.variation_id);' .
                                        '$w.show();' .
                                    '}else{' .
                                        '$w.hide();' .
                                    '}' .
                                '});' .
                            '});' .
                            '$form.on("reset_data hide_variation",function(){' .
                                '$wraps.hide();' .
                            '});' .
                        '});' .
                    '});' .
                '}' .
                'if(document.readyState!=="loading")init();else document.addEventListener("DOMContentLoaded",init);' .
            '})();</script>';
        }

        return $html;
    }

    /**
     * Run data retention cleanup
     */
    public static function run_data_retention() {
        $settings = get_option('chatzio_settings', []);
        $days = isset($settings['data_retention_days']) ? intval($settings['data_retention_days']) : 0;
        if ($days <= 0) return;

        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}chatzio_conversations WHERE created_at < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}chatzio_analytics WHERE created_at < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}chatzio_leads WHERE created_at < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}chatzio_failed_topics WHERE created_at < %s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}chatzio_logs WHERE created_at < %s",
            $cutoff
        ));

        // Cleanup old notified restock subscriptions
        if (class_exists('Chatzio_Restock')) {
            Chatzio_Restock::cleanup_notified($cutoff);
        }

        Chatzio_Logger::log_info('Data retention cleanup completed', ['cutoff' => $cutoff]);
    }
}

// Initialize the plugin
function chatzio_ai() {
    return Chatzio_AI::get_instance();
}

chatzio_ai();

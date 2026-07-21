<?php
/**
 * Chatzio - WooCommerce Product Cards
 * Detects [[Product Name]] mentions in AI responses and replaces with rich HTML cards.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Product_Cards {

    /**
     * Detect product category from user message and product context
     */
    private static function detect_category($user_message = '', $products = []) {
        if (!class_exists('WooCommerce')) {
            return null;
        }

        // Get all product categories
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return null;
        }

        $message_lower = mb_strtolower($user_message);
        $best_match = null;
        $best_score = 0;

        // Check each category
        foreach ($categories as $cat) {
            $cat_name_lower = mb_strtolower($cat->name);
            $cat_slug_lower = mb_strtolower($cat->slug);
            
            // Check if category name or slug appears in message
            if (strpos($message_lower, $cat_name_lower) !== false || strpos($message_lower, $cat_slug_lower) !== false) {
                $score = strlen($cat->name); // Longer names = more specific = higher score
                if ($score > $best_score) {
                    $best_match = $cat;
                    $best_score = $score;
                }
            }
        }

        // Also check product categories from context
        if (!empty($products)) {
            $category_counts = [];
            foreach ($products as $product) {
                if (isset($product['categories']) && !empty($product['categories'])) {
                    $cats = is_array($product['categories']) ? $product['categories'] : explode(',', $product['categories']);
                    foreach ($cats as $cat_name) {
                        $cat_name = trim($cat_name);
                        if (!empty($cat_name)) {
                            $category_counts[$cat_name] = ($category_counts[$cat_name] ?? 0) + 1;
                        }
                    }
                }
            }
            
            // If most products share a category, use that
            if (!empty($category_counts)) {
                arsort($category_counts);
                $top_category = key($category_counts);
                foreach ($categories as $cat) {
                    if (mb_strtolower($cat->name) === mb_strtolower($top_category)) {
                        return $cat;
                    }
                }
            }
        }

        return $best_match;
    }

    /**
     * Get shop page URL from WooCommerce
     */
    private static function get_shop_url() {
        if (!class_exists('WooCommerce')) {
            return home_url('/shop');
        }
        $shop_page_id = wc_get_page_id('shop');
        if ($shop_page_id > 0) {
            return get_permalink($shop_page_id);
        }
        return home_url('/shop');
    }

    /**
     * Process AI response: detect [[Product Name]] and replace with cards
     *
     * @param string $html     Formatted HTML response
     * @param array  $context  Knowledge base context (contains products)
     * @param string $user_message  Original user message (for category detection)
     * @return array ['html' => string, 'product_ids' => array]
     */
    public static function process_response($html, $context = [], $user_message = '') {
        $product_ids = [];
        $products_for_cards = []; // id => product data (unique, for rendering below)

        // 1) Find all [[Product Name]] patterns: replace with link, collect for cards below
        $html = preg_replace_callback('/\[\[([^\]]+)\]\]/', function($matches) use ($context, &$product_ids, &$products_for_cards) {
            $product_name = trim($matches[1]);
            $product = self::find_product($product_name, $context);

            if (!$product) {
                return $product_name; // Remove brackets, leave name as plain text
            }
            if (!empty($product['id'])) {
                $product_ids[] = $product['id'];
                $products_for_cards[ $product['id'] ] = $product;
            }
            return self::render_product_link($product);
        }, $html);

        // 2) Fallback: detect product names mentioned in plain text (no brackets)
        $text_mentions = self::extract_mentions_from_text($html, $context);
        foreach ($text_mentions as $pid) {
            if (!in_array($pid, $product_ids, true)) {
                $product_ids[] = $pid;
            }
            if (!isset($products_for_cards[ $pid ])) {
                $product = self::get_product_by_id($pid);
                if ($product) {
                    $products_for_cards[ $pid ] = $product;
                }
            }
        }

        // 3) Detect category and get appropriate URL
        // Also check context products for category info
        $context_products = [];
        if (!empty($context['products'])) {
            foreach ($context['products'] as $ctx_product) {
                if (isset($ctx_product['title'])) {
                    $context_products[] = $ctx_product;
                }
            }
        }
        $all_products_for_detection = array_merge(array_values($products_for_cards), $context_products);
        $category = self::detect_category($user_message, $all_products_for_detection);
        $shop_url = self::get_shop_url();
        $redirect_url = $shop_url;
        $redirect_label = 'View Shop';
        
        if ($category) {
            $category_url = get_term_link($category);
            if (!is_wp_error($category_url)) {
                $redirect_url = $category_url;
                $redirect_label = 'View ' . esc_html($category->name);
            }
        }

        // 4) Append vertical product cards block below the message (for visibility)
        // Show cards only if 3 or fewer products, otherwise show link only
        $product_count = count($products_for_cards);
        if ($product_count > 0) {
            if ($product_count <= 3) {
                // Show product cards (no link when cards are visible)
                $cards_html = self::render_cards_block(array_values($products_for_cards), '', '');
                $html .= $cards_html;
            } else {
                // Hide cards, show link only
                $html .= self::render_shop_link_only($redirect_url, $redirect_label);
            }
        }

        return [
            'html'        => $html,
            'product_ids' => $product_ids,
        ];
    }

    /**
     * Render product name as a simple link (no inline card)
     */
    private static function render_product_link($product) {
        $title = esc_html($product['title']);
        $url   = esc_url($product['url']);
        return '<a href="' . $url . '" class="chatzio-product-link" target="_blank" rel="noopener">' . $title . '</a>';
    }

    /**
     * Render shop/category link only (no product cards)
     */
    private static function render_shop_link_only($redirect_url = '', $redirect_label = 'View Shop') {
        if (empty($redirect_url)) {
            $redirect_url = self::get_shop_url();
        }
        
        $out = '<div class="chatzio-product-cards-block">';
        $out .= '<div class="chatzio-shop-link-wrapper">';
        $out .= '<a href="' . esc_url($redirect_url) . '" class="chatzio-shop-link" target="_blank" rel="noopener">';
        $out .= esc_html($redirect_label);
        $out .= '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="chatzio-link-arrow"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>';
        $out .= '</a>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Render a block of vertical product cards (below the message text)
     * Shows products only (no link when cards are visible)
     */
    private static function render_cards_block($products, $redirect_url = '', $redirect_label = '') {
        $out = '<div class="chatzio-product-cards-block">';
        
        // Show all products (3 or fewer)
        foreach ($products as $product) {
            $out .= '<div class="chatzio-product-card-wrapper">';
            $out .= self::render_vertical_card($product);
            $out .= '</div>';
        }
        
        // No link when cards are visible
        
        $out .= '</div>';
        return $out;
    }

    /**
     * Get full product data by content_id (for rendering cards from text mentions)
     */
    private static function get_product_by_id($content_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE content_type = 'product' AND content_id = %d LIMIT 1",
            $content_id
        ), ARRAY_A);
        if (!$row) return null;
        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $image = $metadata['image'] ?? '';
        if ($image === '' && class_exists('WooCommerce')) {
            $image = self::get_product_image_fallback($row['content_id']);
        }
        $product = class_exists('WooCommerce') ? wc_get_product($row['content_id']) : null;
        $product_type = ($product && is_a($product, 'WC_Product')) ? $product->get_type() : ($metadata['type'] ?? 'simple');
        $price = $metadata['regular_price'] ?? '';
        $sale_price = $metadata['sale_price'] ?? '';
        if ($product_type === 'variable' && $product && is_a($product, 'WC_Product')) {
            $range = self::get_variable_price_range($product);
            if ($range !== '') {
                $price = $range;
                $sale_price = '';
            }
        }
        return [
            'id'           => $row['content_id'],
            'title'        => $row['title'],
            'url'          => $row['url'],
            'price'        => $price,
            'sale_price'   => $sale_price,
            'image'        => $image,
            'stock_status' => $metadata['stock_status'] ?? 'instock',
            'product_type' => $product_type,
            'categories'   => is_array($metadata['categories'] ?? null) ? implode(', ', $metadata['categories']) : ($metadata['categories'] ?? ''),
        ];
    }

    /**
     * Scan response text for known product names (from context or DB) and return their IDs.
     * Catches mentions when the AI doesn't use [[Product Name]] format.
     * Uses word-boundary matching to prevent false positives from short product names.
     */
    public static function extract_mentions_from_text($html, $context = []) {
        $ids = [];
        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // Normalize: remove markdown emphasis (* _ `) so "**Product**" and "Product" both match
        $text = preg_replace('/[\*\_\`]+/', ' ', $text);
        $text_lower = mb_strtolower($text);

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $rows = $wpdb->get_results(
            "SELECT content_id, title FROM $table WHERE content_type = 'product' AND title != '' ORDER BY CHAR_LENGTH(title) DESC LIMIT 500",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $title = trim($row['title']);
            if (mb_strlen($title) < 3) {
                continue; // Skip very short product names to prevent false positives
            }

            $title_lower = mb_strtolower($title);
            $title_escaped = preg_quote($title_lower, '/');

            // Use word-boundary matching for short names (< 8 chars), substring for longer names
            if (mb_strlen($title) < 8) {
                // Require word boundary so "Oil" doesn't match inside "soil" or "toil"
                if (preg_match('/\b' . $title_escaped . '\b/iu', $text_lower)) {
                    $ids[] = (int) $row['content_id'];
                }
            } else {
                // Longer names are specific enough for substring match
                if (mb_strpos($text_lower, $title_lower) !== false) {
                    $ids[] = (int) $row['content_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Find product by name from context or database
     */
    private static function find_product($name, $context = []) {
        // Try to match from context — but only use it if it has full card data (image/price).
        // RAG chunks populate context['products'] with only title+url, which isn't enough for cards.
        if (!empty($context['products'])) {
            foreach ($context['products'] as $product) {
                if (stripos($product['title'], $name) !== false || stripos($name, $product['title']) !== false) {
                    if (!empty($product['image']) || !empty($product['price']) || !empty($product['regular_price'])) {
                        return self::enrich_product($product);
                    }
                    // Matched by name but lacks card data — fall through to DB lookup
                    break;
                }
            }
        }

        // Database lookup — returns full metadata (image, price, stock, etc.)
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE content_type = 'product' AND LOWER(title) LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like(strtolower($name)) . '%'
        ), ARRAY_A);

        if (!$row) return null;

        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $image = $metadata['image'] ?? '';
        if ($image === '' && class_exists('WooCommerce')) {
            $image = self::get_product_image_fallback($row['content_id']);
        }
        $product = class_exists('WooCommerce') ? wc_get_product($row['content_id']) : null;
        $product_type = ($product && is_a($product, 'WC_Product')) ? $product->get_type() : ($metadata['type'] ?? 'simple');
        $price = $metadata['regular_price'] ?? '';
        $sale_price = $metadata['sale_price'] ?? '';
        if ($product_type === 'variable' && $product && is_a($product, 'WC_Product')) {
            $range = self::get_variable_price_range($product);
            if ($range !== '') {
                $price = $range;
                $sale_price = '';
            }
        }
        return [
            'id'           => $row['content_id'],
            'title'        => $row['title'],
            'url'          => $row['url'],
            'price'        => $price,
            'sale_price'   => $sale_price,
            'image'        => $image,
            'stock_status' => $metadata['stock_status'] ?? 'instock',
            'product_type' => $product_type,
            'categories'   => is_array($metadata['categories'] ?? null) ? implode(', ', $metadata['categories']) : ($metadata['categories'] ?? ''),
        ];
    }

    /**
     * Get formatted price range for variable products with currency in front (e.g. "৳ 10.00 – ৳ 20.00").
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
     * Get product image URL from WooCommerce when metadata has none
     */
    private static function get_product_image_fallback($product_id) {
        if (!$product_id || !class_exists('WooCommerce')) {
            return '';
        }
        $product = wc_get_product($product_id);
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
     * Enrich a context product array with full card data
     */
    private static function enrich_product($product) {
        $id = 0;
        if (!empty($product['id']) && is_numeric($product['id'])) {
            $id = (int) $product['id'];
        } elseif (!empty($product['url'])) {
            $id = url_to_postid($product['url']);
        }

        return [
            'id'           => $id,
            'title'        => $product['title'] ?? '',
            'url'          => $product['url'] ?? '#',
            'price'        => $product['regular_price'] ?? ($product['price'] ?? ''),
            'sale_price'   => $product['sale_price'] ?? '',
            'image'        => $product['image'] ?? '',
            'stock_status' => $product['stock_status'] ?? 'instock',
            'product_type' => $product['product_type'] ?? $product['type'] ?? 'simple',
            'categories'   => $product['categories'] ?? '',
        ];
    }

    /**
     * Render a compact horizontal product card (same as Products tab: small image left, title + price, arrow or Add to cart)
     * Simple + in stock: Add to cart button. Variable or out of stock: arrow (view product).
     */
    private static function render_vertical_card($product) {
        $title = esc_html($product['title']);
        $url = esc_url($product['url']);
        $image = esc_url($product['image'] ?? '');
        $price = $product['price'] ?? '';
        $sale = $product['sale_price'] ?? '';
        $stock = $product['stock_status'] ?? 'instock';
        $product_type = $product['product_type'] ?? 'simple';
        $product_id = isset($product['id']) ? (int) $product['id'] : 0;

        $is_simple_in_stock = ($product_type === 'simple' && $stock !== 'outofstock');

        $currency = (function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$');
        $currency = is_string($currency) ? trim(html_entity_decode($currency, ENT_QUOTES, 'UTF-8')) : '$';
        $price_html = '';
        $price_display = $price !== '' ? (is_numeric($price) ? $currency . $price : $price) : '';
        $sale_display = $sale !== '' ? (is_numeric($sale) ? $currency . $sale : $sale) : '';
        if ($sale_display && $sale_display !== $price_display) {
            $price_html = '<span class="pc-price pc-strikethrough">' . esc_html($price_display) . '</span><span class="pc-price pc-sale">' . esc_html($sale_display) . '</span>';
        } elseif ($price_display) {
            $price_html = '<span class="pc-price">' . esc_html($price_display) . '</span>';
        }
        if ($price_html) {
            $price_html = '<div class="pc-prices">' . $price_html . '</div>';
        }

        $stock_html = '';
        if ($stock === 'outofstock') {
            $stock_html = '<span class="pc-stock-badge">Out of stock</span>';
        } else {
            $stock_html = '<span class="pc-stock-badge pc-stock-in">In stock</span>';
        }

        $image_html = '';
        if ($image) {
            $image_html = '<div class="pc-img"><img src="' . $image . '" alt="' . $title . '" loading="lazy"></div>';
        } else {
            $image_html = '<div class="pc-img pc-img-empty"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg></div>';
        }

        $stock_class = $stock === 'outofstock' ? ' pc-out' : '';
        $arrow_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';

        if ($is_simple_in_stock && $product_id > 0 && class_exists('WooCommerce')) {
            $add_to_cart_url = esc_url(add_query_arg('add-to-cart', $product_id, home_url('/')));
            return '<div class="chatzio-product-card-inchat pc-card pc-has-add-to-cart' . $stock_class . '">' .
                '<a href="' . $url . '" class="pc-card-link" target="_blank" rel="noopener">' .
                    $image_html .
                    '<div class="pc-body">' .
                        '<span class="pc-title">' . $title . '</span>' .
                        $price_html .
                        $stock_html .
                    '</div>' .
                '</a>' .
                '<a href="' . $add_to_cart_url . '" class="pc-add-to-cart-btn" data-product-id="' . esc_attr((string) $product_id) . '" title="Add to cart">' .
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>' .
                    '<span>Add to cart</span>' .
                '</a>' .
            '</div>';
        }

        // Out of stock with restock notifications enabled: show "Notify Me" button
        if ($stock === 'outofstock' && $product_id > 0) {
            $settings = get_option('chatzio_settings', []);
            if (!empty($settings['notify_restock'])) {
                $bell_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>';
                return '<div class="chatzio-product-card-inchat pc-card pc-has-notify' . $stock_class . '">' .
                    '<a href="' . $url . '" class="pc-card-link" target="_blank" rel="noopener">' .
                        $image_html .
                        '<div class="pc-body">' .
                            '<span class="pc-title">' . $title . '</span>' .
                            $price_html .
                            $stock_html .
                        '</div>' .
                    '</a>' .
                    '<button class="pc-notify-btn" data-product-id="' . esc_attr((string) $product_id) . '" data-product-title="' . esc_attr($title) . '">' .
                        $bell_svg .
                        '<span>Notify Me</span>' .
                    '</button>' .
                '</div>';
            }
        }

        return '<a href="' . $url . '" class="chatzio-product-card-inchat pc-card' . $stock_class . '" target="_blank" rel="noopener">' .
            $image_html .
            '<div class="pc-body">' .
                '<span class="pc-title">' . $title . '</span>' .
                $price_html .
                $stock_html .
            '</div>' .
            '<span class="pc-arrow">' . $arrow_svg . '</span>' .
        '</a>';
    }
}

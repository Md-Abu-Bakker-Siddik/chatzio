<?php
/**
 * Content Sync Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Content_Sync {
    
    /**
     * Sync all content types
     */
    public static function sync_all_content() {
        $settings = get_option('chatzio_settings', []);
        $results = [
            'posts' => 0,
            'pages' => 0,
            'products' => 0,
            'removed' => 0,
            'chunks_created' => 0,
            'chunks_embedded' => 0,
            'errors' => []
        ];
        
        // Default to posts + pages when not configured (matches settings UI defaults)
        $sync_posts = !isset($settings['sync_posts']) || $settings['sync_posts'];
        $sync_pages = !isset($settings['sync_pages']) || $settings['sync_pages'];
        $sync_products = !empty($settings['sync_products']);
        
        try {
            if ($sync_posts) {
                $results['posts'] = self::sync_posts();
            }
            
            if ($sync_pages) {
                $results['pages'] = self::sync_pages();
            }
            
            if ($sync_products) {
                $results['products'] = self::sync_products();
            }
            
            // Remove stale entries (deleted/unpublished content)
            $results['removed'] = self::purge_stale_content($sync_posts, $sync_pages, $sync_products);
            
            update_option('chatzio_last_sync', current_time('mysql'));

            // RAG: chunk and embed content for semantic search (graceful if API fails)
            if (class_exists('Chatzio_Embeddings')) {
                try {
                    $embed_result = Chatzio_Embeddings::sync_chunks_for_content();
                    $results['chunks_created']  = isset($embed_result['chunks_created']) ? $embed_result['chunks_created'] : 0;
                    $results['chunks_embedded'] = isset($embed_result['chunks_embedded']) ? $embed_result['chunks_embedded'] : 0;
                    if (!empty($embed_result['errors'])) {
                        $results['errors'] = array_merge($results['errors'], $embed_result['errors']);
                    }
                } catch (Exception $embed_ex) {
                    Chatzio_Logger::log_error('Embedding sync failed (content sync still succeeded)', [
                        'error' => $embed_ex->getMessage(),
                    ]);
                    $results['errors'][] = 'Embedding: ' . $embed_ex->getMessage();
                }
            }

            // Optional: generate extra system prompt from synced content via OpenRouter (default true when not set)
            $generate_prompt = !isset($settings['generate_prompt_on_sync']) || !empty($settings['generate_prompt_on_sync']);
            if ($generate_prompt && class_exists('Chatzio_OpenRouter')) {
                try {
                    $digest = self::get_content_digest_for_prompt(12000);
                    if (strlen(trim($digest)) >= 50) {
                        $openrouter = new Chatzio_OpenRouter();
                        $gen = $openrouter->generate_prompt_from_content($digest);
                        if (!empty($gen['success']) && $gen['prompt'] !== '') {
                            $current = get_option('chatzio_settings', []);
                            $current['synced_content_prompt'] = $gen['prompt'];
                            update_option('chatzio_settings', $current);
                            $results['generated_prompt'] = true;
                        } else {
                            if (!empty($gen['error'])) {
                                $results['errors'][] = 'Prompt generation: ' . $gen['error'];
                            }
                        }
                    }
                } catch (Exception $prompt_ex) {
                    Chatzio_Logger::log_error('Prompt generation after sync failed', ['error' => $prompt_ex->getMessage()]);
                    $results['errors'][] = 'Prompt generation: ' . $prompt_ex->getMessage();
                }
            }

            Chatzio_Logger::log_info('Content sync completed', $results);
            
        } catch (Exception $e) {
            Chatzio_Logger::log_error('Content sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Remove indexed content that no longer exists or is no longer published.
     * Runs after sync so the bot never answers about deleted/draft content.
     */
    private static function purge_stale_content($sync_posts, $sync_pages, $sync_products) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $removed = 0;

        $types_to_check = [];
        if ($sync_posts)    $types_to_check[] = 'post';
        if ($sync_pages)    $types_to_check[] = 'page';
        if ($sync_products) $types_to_check[] = 'product';

        if (empty($types_to_check)) return 0;

        foreach ($types_to_check as $type) {
            // Get all indexed IDs for this type
            $indexed_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, content_id FROM $table WHERE content_type = %s",
                $type
            ), ARRAY_A);

            if (empty($indexed_rows)) continue;

            foreach ($indexed_rows as $row) {
                $post = get_post($row['content_id']);

                // Remove if: post doesn't exist, is trashed, or is no longer published
                if (!$post || $post->post_status !== 'publish' || $post->post_type !== $type) {
                    $wpdb->delete($table, ['id' => $row['id']], ['%d']);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            Chatzio_Logger::log_info('Purged stale content from knowledge base', ['removed' => $removed]);
        }

        return $removed;
    }
    
    /**
     * Sync WordPress posts (batched to avoid memory issues)
     */
    public static function sync_posts() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $count = 0;
        $page = 1;
        $per_page = 100;

        do {
            $posts = get_posts([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($posts as $post) {
                $content = wp_strip_all_tags($post->post_content);
                $content = self::clean_content($content);

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE content_id = %d AND content_type = 'post'",
                    $post->ID
                ));

                $data = [
                    'content_id'   => $post->ID,
                    'content_type' => 'post',
                    'title'        => $post->post_title,
                    'content'      => $content,
                    'excerpt'      => $post->post_excerpt,
                    'url'          => get_permalink($post->ID),
                    'metadata'     => json_encode([
                        'author'     => get_the_author_meta('display_name', $post->post_author),
                        'date'       => $post->post_date,
                        'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
                        'tags'       => wp_get_post_tags($post->ID, ['fields' => 'names']),
                    ])
                ];

                if ($existing) {
                    $wpdb->update($table, $data, ['id' => $existing]);
                } else {
                    $wpdb->insert($table, $data);
                }

                $count++;
            }

            $page++;
        } while (count($posts) === $per_page);

        return $count;
    }
    
    /**
     * Sync WordPress pages (batched)
     */
    public static function sync_pages() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $count = 0;
        $page_num = 1;
        $per_page = 100;

        do {
            $pages = get_posts([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page_num,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($pages as $page) {
                $content = wp_strip_all_tags($page->post_content);
                $content = self::clean_content($content);

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE content_id = %d AND content_type = 'page'",
                    $page->ID
                ));

                $data = [
                    'content_id'   => $page->ID,
                    'content_type' => 'page',
                    'title'        => $page->post_title,
                    'content'      => $content,
                    'excerpt'      => $page->post_excerpt,
                    'url'          => get_permalink($page->ID),
                    'metadata'     => json_encode([
                        'parent'   => $page->post_parent,
                        'template' => get_page_template_slug($page->ID),
                    ])
                ];

                if ($existing) {
                    $wpdb->update($table, $data, ['id' => $existing]);
                } else {
                    $wpdb->insert($table, $data);
                }

                $count++;
            }

            $page_num++;
        } while (count($pages) === $per_page);

        return $count;
    }
    
    /**
     * Sync WooCommerce products (batched)
     */
    public static function sync_products() {
        if (!class_exists('WooCommerce')) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $count = 0;
        $page = 1;
        $per_page = 50; // Products are heavier (metadata), so smaller batch

        do {
            $products = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);

                if (!$product) {
                    continue;
                }

                $content = wp_strip_all_tags($product->get_description());
                $content = self::clean_content($content);

                $short_desc = wp_strip_all_tags($product->get_short_description());

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE content_id = %d AND content_type = 'product'",
                    $product->get_id()
                ));

                // Build comprehensive product metadata (variable products: get_regular_price can be empty, use get_price() for min)
                $regular = $product->get_regular_price();
                $sale = $product->get_sale_price();
                if (($regular === '' || $regular === null) && $product->get_type() === 'variable') {
                    $regular = $product->get_price();
                }
                $metadata = [
                    'price'         => $product->get_price_html(),
                    'regular_price' => $regular,
                    'sale_price'    => $sale,
                    'sku'           => $product->get_sku(),
                    'stock_status'  => $product->get_stock_status(),
                    'stock_qty'     => $product->get_stock_quantity(),
                    'categories'    => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
                    'tags'          => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
                    'weight'        => $product->get_weight(),
                    'type'          => $product->get_type(),
                    'featured'      => $product->is_featured(),
                    'on_sale'       => $product->is_on_sale(),
                    'purchasable'   => $product->is_purchasable(),
                ];

                // Get product image URL (try medium, then thumbnail, then full; ensure absolute)
                $image_id = $product->get_image_id();
                if ($image_id) {
                    foreach (['medium', 'thumbnail', 'full'] as $size) {
                        $image_url = wp_get_attachment_image_url($image_id, $size);
                        if ($image_url) {
                            $metadata['image'] = preg_match('#^https?://#i', $image_url) ? $image_url : home_url($image_url);
                            break;
                        }
                    }
                }

                // Get product attributes (e.g., size, color, dosage)
                $attributes = $product->get_attributes();
                if (!empty($attributes)) {
                    $attr_data = [];
                    foreach ($attributes as $attr) {
                        if (is_object($attr) && method_exists($attr, 'get_name')) {
                            $name = wc_attribute_label($attr->get_name());
                            $values = $attr->is_taxonomy()
                                ? wp_get_post_terms($product->get_id(), $attr->get_name(), ['fields' => 'names'])
                                : $attr->get_options();
                            $attr_data[$name] = is_array($values) ? implode(', ', $values) : $values;
                        }
                    }
                    if (!empty($attr_data)) {
                        $metadata['attributes'] = $attr_data;
                    }
                }

                // Combine short description + full description for richer context
                $full_content = '';
                if (!empty($short_desc)) {
                    $full_content .= $short_desc . "\n\n";
                }
                $full_content .= $content;

                $data = [
                    'content_id'   => $product->get_id(),
                    'content_type' => 'product',
                    'title'        => $product->get_name(),
                    'content'      => $full_content,
                    'excerpt'      => $short_desc,
                    'url'          => get_permalink($product->get_id()),
                    'metadata'     => json_encode($metadata),
                ];

                if ($existing) {
                    $wpdb->update($table, $data, ['id' => $existing]);
                } else {
                    $wpdb->insert($table, $data);
                }

                $count++;
            }

            $page++;
        } while (count($products) === $per_page);

        return $count;
    }

    /**
     * Clean content text
     * Preserves all meaningful characters: currency ($), percentages (%),
     * URLs (:/), emails (@), math (+, =), parentheses, quotes, etc.
     * Only strips true control characters and invisible Unicode junk.
     */
    private static function clean_content($content) {
        // Collapse whitespace (newlines, tabs, multiple spaces → single space)
        $content = preg_replace('/\s+/', ' ', $content);
        // Remove only invisible control characters (keep everything printable)
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
        return trim($content);
    }
    
    /**
     * Build a digest of synced content for AI prompt generation (titles + excerpts, capped size).
     *
     * @param int $max_chars Maximum total characters to include (default ~12k to stay under token limits).
     * @return string
     */
    public static function get_content_digest_for_prompt($max_chars = 12000) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        $rows = $wpdb->get_results(
            "SELECT content_type, title, excerpt, content, url FROM $table ORDER BY content_type, title ASC",
            ARRAY_A
        );
        if (empty($rows)) {
            return '';
        }
        $sections = ['page' => [], 'post' => [], 'product' => []];
        foreach ($rows as $row) {
            $type = $row['content_type'];
            if (!isset($sections[$type])) {
                $sections[$type] = [];
            }
            $excerpt = !empty($row['excerpt']) ? $row['excerpt'] : wp_trim_words(wp_strip_all_tags($row['content']), 40);
            $sections[$type][] = [
                'title'   => $row['title'],
                'excerpt' => $excerpt,
                'url'     => $row['url'],
            ];
        }
        $out = '';
        $labels = ['page' => 'Pages', 'post' => 'Posts', 'product' => 'Products'];
        foreach ($labels as $type => $label) {
            if (empty($sections[$type])) {
                continue;
            }
            $out .= "=== $label ===\n\n";
            foreach ($sections[$type] as $item) {
                $out .= "Title: " . $item['title'] . "\n";
                $out .= "Summary: " . $item['excerpt'] . "\n";
                if (!empty($item['url'])) {
                    $out .= "URL: " . $item['url'] . "\n";
                }
                $out .= "\n";
            }
            if (strlen($out) >= $max_chars) {
                break;
            }
        }
        return strlen($out) > $max_chars ? substr($out, 0, $max_chars) . "\n\n[Content truncated...]" : $out;
    }

    /**
     * Get sync statistics
     */
    public static function get_sync_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';
        
        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'posts' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE content_type = 'post'"),
            'pages' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE content_type = 'page'"),
            'products' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE content_type = 'product'"),
            'last_sync' => get_option('chatzio_last_sync', 'Never'),
            'chunks_total' => 0,
            'chunks_embedded' => 0,
            'last_embedding_sync' => get_option('chatzio_last_embedding_sync', ''),
        ];
        if (class_exists('Chatzio_Embeddings')) {
            $chunk_stats = Chatzio_Embeddings::get_chunk_stats();
            $stats['chunks_total'] = $chunk_stats['total'];
            $stats['chunks_embedded'] = $chunk_stats['embedded'];
        }
        
        return $stats;
    }
}

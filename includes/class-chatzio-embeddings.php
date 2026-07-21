<?php
/**
 * Chatzio - Embeddings & RAG
 * OpenRouter embeddings API, chunking, cosine similarity, hybrid search (vector + FULLTEXT).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Embeddings {

    const EMBEDDING_MODEL   = 'openai/text-embedding-3-small';
    const EMBEDDING_DIMS    = 1536;
    const API_URL           = 'https://openrouter.ai/api/v1/embeddings';
    const CHUNK_TARGET_CHARS = 1600;   // ~400 tokens at ~4 chars/token
    const CHUNK_OVERLAP_CHARS = 400;   // ~100 tokens overlap
    const BATCH_SIZE        = 100;     // max texts per API call
    const RRF_K             = 60;      // Reciprocal Rank Fusion constant
    const VECTOR_BATCH_LOAD = 1000;    // chunks to load per batch for similarity search

    /**
     * Generate a single embedding via OpenRouter.
     *
     * @param string $text Input text.
     * @return array{success: bool, embedding?: float[], error?: string}
     */
    public static function generate_embedding($text) {
        $result = self::generate_embeddings_batch([$text]);
        if (!$result['success'] || empty($result['embeddings'])) {
            return ['success' => false, 'error' => $result['error'] ?? 'Embedding failed'];
        }
        return [
            'success'   => true,
            'embedding' => $result['embeddings'][0],
        ];
    }

    /**
     * Generate embeddings for multiple texts (batch). Uses same API key as chat.
     *
     * @param string[] $texts Array of texts (max 100 per call; method chunks internally).
     * @return array{success: bool, embeddings?: float[][], error?: string}
     */
    public static function generate_embeddings_batch($texts) {
        $settings = get_option('chatzio_settings', []);
        $api_key  = isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : '';

        if (empty($api_key)) {
            Chatzio_Logger::log_error('Embeddings: OpenRouter API key not configured');
            return ['success' => false, 'error' => 'API key not configured'];
        }

        $texts = array_values(array_filter(array_map('trim', $texts)));
        if (empty($texts)) {
            return ['success' => true, 'embeddings' => []];
        }

        $all_embeddings = [];
        $chunks_batch   = array_chunk($texts, self::BATCH_SIZE);

        foreach ($chunks_batch as $batch) {
            $body = [
                'model' => self::EMBEDDING_MODEL,
                'input' => $batch,
            ];

            $response = wp_remote_post(self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer' => home_url(),
                    'X-Title'      => get_bloginfo('name'),
                ],
                'body'    => json_encode($body),
                'timeout' => 60,
            ]);

            if (is_wp_error($response)) {
                Chatzio_Logger::log_error('Embeddings API request failed', [
                    'error' => $response->get_error_message(),
                ]);
                return [
                    'success' => false,
                    'error'   => $response->get_error_message(),
                ];
            }

            $code = wp_remote_retrieve_response_code($response);
            $body_response = wp_remote_retrieve_body($response);
            $data = json_decode($body_response, true);

            if ($code !== 200 || !isset($data['data']) || !is_array($data['data'])) {
                $msg = isset($data['error']['message']) ? $data['error']['message'] : substr($body_response, 0, 200);
                Chatzio_Logger::log_error('Embeddings API error', ['code' => $code, 'response' => $msg]);
                return ['success' => false, 'error' => 'Embeddings API error: ' . $msg];
            }

            // Sort by index (API may return in different order)
            $by_index = [];
            foreach ($data['data'] as $item) {
                $idx = isset($item['index']) ? (int) $item['index'] : count($by_index);
                $by_index[$idx] = isset($item['embedding']) ? $item['embedding'] : [];
            }
            ksort($by_index);
            foreach ($by_index as $emb) {
                $all_embeddings[] = $emb;
            }
        }

        return ['success' => true, 'embeddings' => $all_embeddings];
    }

    /**
     * Chunk content into overlapping segments (~400 tokens, ~100 token overlap).
     * Each chunk is prefixed with "Title: {title}\n" for context.
     *
     * @param string       $title    Source title.
     * @param string       $content  Full text.
     * @param string       $url      Optional URL.
     * @param array|string $metadata Optional metadata (e.g. content_type, product price).
     * @return array<int, array{text: string, token_estimate: int}>
     */
    public static function chunk_content($title, $content, $url = '', $metadata = []) {
        $content = is_string($content) ? $content : '';
        $content = preg_replace('/\s+/', ' ', trim($content));
        if (mb_strlen($content) === 0) {
            $prefix = self::chunk_prefix($title, $url, $metadata);
            return [['text' => $prefix . ' (No content.)', 'token_estimate' => 10]];
        }

        $prefix   = self::chunk_prefix($title, $url, $metadata);
        $target   = self::CHUNK_TARGET_CHARS - mb_strlen($prefix);
        $overlap  = self::CHUNK_OVERLAP_CHARS;
        $step     = max(1, $target - $overlap);
        $len      = mb_strlen($content);
        $chunks   = [];
        $start    = 0;

        while ($start < $len) {
            $end = min($start + $target, $len);
            $segment = mb_substr($content, $start, $end - $start);

            // Prefer break at sentence boundary (only if there's more content ahead)
            if ($end < $len) {
                $positions = array_filter([
                    mb_strrpos($segment, '.'),
                    mb_strrpos($segment, '!'),
                    mb_strrpos($segment, '?'),
                    mb_strrpos($segment, "\n"),
                ], function ($v) { return $v !== false; });
                $last_sentence = !empty($positions) ? max($positions) : false;
                if ($last_sentence !== false && $last_sentence > (int) ($target * 0.5)) {
                    $segment = mb_substr($segment, 0, $last_sentence + 1);
                    $end = $start + mb_strlen($segment);
                }
            }

            $chunk_text = $prefix . trim($segment);
            if (mb_strlen(trim($segment)) > 0) {
                $chunks[] = [
                    'text'          => $chunk_text,
                    'token_estimate' => (int) ceil(mb_strlen($chunk_text) / 4),
                ];
            }

            // If we've reached the end of content, stop
            if ($end >= $len) {
                break;
            }

            // Advance with overlap; ensure we always move forward
            $next_start = $end - $overlap;
            if ($next_start <= $start) {
                $next_start = $start + max(1, (int) ($target * 0.5));
            }
            $start = $next_start;
        }

        return $chunks;
    }

    /**
     * Build prefix string for a chunk (title, url, type, and product metadata if available).
     */
    private static function chunk_prefix($title, $url, $metadata) {
        $title = trim((string) $title);
        $url   = trim((string) $url);
        $meta  = is_array($metadata) ? $metadata : [];
        $type  = isset($meta['content_type']) ? $meta['content_type'] : '';

        $parts = ["Title: {$title}"];
        if ($type) {
            $parts[] = "Type: {$type}";
        }
        if ($url) {
            $parts[] = "URL: {$url}";
        }

        // Include structured product data so the AI can answer price/stock/SKU questions
        if ($type === 'product') {
            if (isset($meta['regular_price']) && $meta['regular_price'] !== '') {
                $parts[] = "Price: {$meta['regular_price']}";
            }
            if (isset($meta['sale_price']) && $meta['sale_price'] !== '') {
                $parts[] = "Sale Price: {$meta['sale_price']}";
            }
            if (isset($meta['sku']) && $meta['sku'] !== '') {
                $parts[] = "SKU: {$meta['sku']}";
            }
            if (isset($meta['stock_status']) && $meta['stock_status'] !== '') {
                $parts[] = "Stock: {$meta['stock_status']}";
            }
            if (isset($meta['categories'])) {
                $cats = is_array($meta['categories']) ? implode(', ', $meta['categories']) : $meta['categories'];
                if ($cats !== '') {
                    $parts[] = "Categories: {$cats}";
                }
            }
            if (isset($meta['weight']) && $meta['weight'] !== '') {
                $parts[] = "Weight: {$meta['weight']}";
            }
        }

        return implode("\n", $parts) . "\n\n";
    }

    /**
     * Cosine similarity between two vectors (0–1 for normalized vectors).
     *
     * @param float[] $vec_a
     * @param float[] $vec_b
     * @return float
     */
    public static function cosine_similarity($vec_a, $vec_b) {
        $n = count($vec_a);
        if ($n === 0 || $n !== count($vec_b)) {
            return 0.0;
        }
        $dot = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = (float) $vec_a[$i];
            $b = (float) $vec_b[$i];
            $dot += $a * $b;
            $norm_a += $a * $a;
            $norm_b += $b * $b;
        }
        $denom = sqrt($norm_a * $norm_b);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Pack float array to binary for DB storage (LONGBLOB).
     *
     * @param float[] $vec
     * @return string
     */
    public static function pack_embedding($vec) {
        return pack('f*', ...$vec);
    }

    /**
     * Unpack binary blob to float array.
     *
     * @param string $blob
     * @return float[]
     */
    public static function unpack_embedding($blob) {
        if (!is_string($blob) || $blob === '') {
            return [];
        }
        $unpacked = unpack('f*', $blob);
        return $unpacked ? array_values($unpacked) : [];
    }

    /**
     * Vector search: embed query, load chunk vectors, return top N by cosine similarity.
     *
     * @param string $query_text User message.
     * @param int    $limit      Max results.
     * @return array<int, array{chunk_id: int, source_type: string, source_id: int, chunk_index: int, chunk_text: string, score: float, title: string, url: string, content_type: string}>
     */
    public static function search($query_text, $limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_chunks';
        $table_content = $wpdb->prefix . 'chatzio_content';
        $table_resources = $wpdb->prefix . 'chatzio_resources';
        
        // Performance sub-timing
        $perf = [];
        $perf_start = microtime(true);

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        // Try to get cached query embedding first (saves ~500-800ms per repeated/similar query)
        $embed_start = microtime(true);
        $cache_key = 'chatzio_qembed_' . md5($query_text);
        $query_vec = get_transient($cache_key);
        $embed_cached = ($query_vec !== false);
        
        if ($query_vec === false) {
            $embed_result = self::generate_embedding($query_text);
            if (!$embed_result['success'] || empty($embed_result['embedding'])) {
                return [];
            }
            $query_vec = $embed_result['embedding'];
            // Cache for 1 hour - similar queries will hit cache
            set_transient($cache_key, $query_vec, HOUR_IN_SECONDS);
        }
        $perf['embedding_api'] = round((microtime(true) - $embed_start) * 1000, 2);
        $perf['embedding_cached'] = $embed_cached;

        $offset = 0;
        $candidates = [];
        $content_rows = [];
        $resource_rows = [];
        $chunks_processed = 0;

        // Score all embedded chunks. Only load id + embedding (skip chunk_text to save memory).
        $similarity_start = microtime(true);
        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source_type, source_id, chunk_index, embedding FROM $table WHERE embedding IS NOT NULL AND embedding != '' ORDER BY id ASC LIMIT %d OFFSET %d",
                    self::VECTOR_BATCH_LOAD,
                    $offset
                ),
                ARRAY_A
            );
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $vec = self::unpack_embedding($row['embedding']);
                if (count($vec) !== self::EMBEDDING_DIMS) {
                    continue;
                }
                $score = self::cosine_similarity($query_vec, $vec);
                $candidates[] = [
                    'chunk_id'    => (int) $row['id'],
                    'source_type' => $row['source_type'],
                    'source_id'   => (int) $row['source_id'],
                    'chunk_index' => (int) $row['chunk_index'],
                    'score'       => $score,
                ];
                $chunks_processed++;
            }
            unset($rows);
            $offset += self::VECTOR_BATCH_LOAD;
        }
        $perf['similarity_calc'] = round((microtime(true) - $similarity_start) * 1000, 2);
        $perf['chunks_processed'] = $chunks_processed;

        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $candidates = array_slice($candidates, 0, $limit);

        // Now fetch chunk_text only for the top results
        if (!empty($candidates)) {
            $top_ids = array_column($candidates, 'chunk_id');
            $ph = implode(',', array_fill(0, count($top_ids), '%d'));
            $text_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, chunk_text FROM $table WHERE id IN ($ph)",
                ...$top_ids
            ), ARRAY_A);
            $text_map = [];
            foreach ($text_rows as $tr) {
                $text_map[(int) $tr['id']] = $tr['chunk_text'];
            }
            foreach ($candidates as &$c) {
                $c['chunk_text'] = isset($text_map[$c['chunk_id']]) ? $text_map[$c['chunk_id']] : '';
            }
            unset($c);
        }

        // Enrich with title/url from content or resources
        $content_ids = [];
        $resource_ids = [];
        foreach ($candidates as $c) {
            if ($c['source_type'] === 'content') {
                $content_ids[$c['source_id']] = true;
            } else {
                $resource_ids[$c['source_id']] = true;
            }
        }

        if (!empty($content_ids)) {
            $ids = array_keys($content_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $content_table = $wpdb->prefix . 'chatzio_content';
            $content_list = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, title, url, content_type FROM $content_table WHERE id IN ($placeholders)",
                    ...$ids
                ),
                ARRAY_A
            );
            foreach ($content_list as $r) {
                $content_rows[(int) $r['id']] = [
                    'title' => $r['title'],
                    'url'   => $r['url'],
                    'content_type' => $r['content_type'] ?? '',
                ];
            }
        }
        if (!empty($resource_ids)) {
            $ids = array_keys($resource_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $res_list = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, title FROM $table_resources WHERE id IN ($placeholders)",
                    ...$ids
                ),
                ARRAY_A
            );
            foreach ($res_list as $r) {
                $resource_rows[(int) $r['id']] = [
                    'title' => $r['title'],
                    'url'   => '',
                    'content_type' => 'resource',
                ];
            }
        }

        $results = [];
        foreach ($candidates as $c) {
            $sid = $c['source_id'];
            $meta = $c['source_type'] === 'content' ? ($content_rows[$sid] ?? []) : ($resource_rows[$sid] ?? []);
            $results[] = [
                'chunk_id'     => $c['chunk_id'],
                'source_type'  => $c['source_type'],
                'source_id'    => $c['source_id'],
                'chunk_index'  => $c['chunk_index'],
                'chunk_text'   => $c['chunk_text'],
                'score'        => $c['score'],
                'title'        => isset($meta['title']) ? $meta['title'] : 'Unknown',
                'url'          => isset($meta['url']) ? $meta['url'] : '',
                'content_type' => isset($meta['content_type']) ? $meta['content_type'] : '',
            ];
        }
        
        // Log performance breakdown
        $perf['total'] = round((microtime(true) - $perf_start) * 1000, 2);
        Chatzio_Logger::log_debug('Vector search performance', $perf);
        
        return $results;
    }

    /**
     * Hybrid search: RRF merge of vector search + FULLTEXT on chunks table.
     *
     * @param string   $query_text User message.
     * @param string[] $keywords   Extracted keywords (for FULLTEXT).
     * @param int      $limit      Final number of results (e.g. 5).
     * @return array Same shape as search() but merged and re-ranked.
     */
    public static function hybrid_search($query_text, $keywords = [], $limit = 5) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_chunks';
        $table_content = $wpdb->prefix . 'chatzio_content';
        $table_resources = $wpdb->prefix . 'chatzio_resources';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        $vector_results = self::search($query_text, 20);
        $fulltext_results = self::search_chunks_fulltext($query_text, $keywords, 20);

        $by_chunk_id = [];
        $k = self::RRF_K;

        foreach ($vector_results as $rank => $row) {
            $id = $row['chunk_id'];
            if (!isset($by_chunk_id[$id])) {
                $by_chunk_id[$id] = $row;
                $by_chunk_id[$id]['rrf_score'] = 0.0;
            }
            $by_chunk_id[$id]['rrf_score'] += 1.0 / ($k + $rank + 1);
        }
        foreach ($fulltext_results as $rank => $row) {
            $id = $row['chunk_id'];
            if (!isset($by_chunk_id[$id])) {
                $by_chunk_id[$id] = $row;
                $by_chunk_id[$id]['rrf_score'] = 0.0;
            }
            $by_chunk_id[$id]['rrf_score'] += 1.0 / ($k + $rank + 1);
        }

        usort($by_chunk_id, function ($a, $b) {
            return $b['rrf_score'] <=> $a['rrf_score'];
        });
        $merged = array_slice($by_chunk_id, 0, $limit);
        foreach ($merged as &$row) {
            unset($row['rrf_score']);
        }
        return $merged;
    }

    /**
     * FULLTEXT search on chunks table (for hybrid). Returns same shape as search().
     *
     * @param string   $query_text Original message.
     * @param string[] $keywords   Keywords.
     * @param int      $limit      Max results.
     * @return array
     */
    public static function search_chunks_fulltext($query_text, $keywords, $limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_chunks';
        $table_content = $wpdb->prefix . 'chatzio_content';
        $table_resources = $wpdb->prefix . 'chatzio_resources';

        $index_exists = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", 'chunk_search'));
        if (empty($index_exists) || empty($keywords)) {
            $like_term = '%' . $wpdb->esc_like($query_text) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source_type, source_id, chunk_index, chunk_text FROM $table WHERE chunk_text LIKE %s LIMIT %d",
                $like_term,
                $limit
            ), ARRAY_A);
        } else {
            $boolean_terms = [];
            foreach ($keywords as $kw) {
                if (strlen($kw) >= 2) {
                    $boolean_terms[] = '+' . $wpdb->esc_like($kw) . '*';
                }
            }
            $boolean_query = implode(' ', array_slice($boolean_terms, 0, 10));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source_type, source_id, chunk_index, chunk_text,
                        MATCH(chunk_text) AGAINST(%s IN BOOLEAN MODE) as relevance
                 FROM $table
                 WHERE MATCH(chunk_text) AGAINST(%s IN BOOLEAN MODE)
                 ORDER BY relevance DESC
                 LIMIT %d",
                $boolean_query,
                $boolean_query,
                $limit
            ), ARRAY_A);
        }

        if (empty($rows)) {
            return [];
        }

        $content_ids = [];
        $resource_ids = [];
        foreach ($rows as $r) {
            if ($r['source_type'] === 'content') {
                $content_ids[(int) $r['source_id']] = true;
            } else {
                $resource_ids[(int) $r['source_id']] = true;
            }
        }

        $content_rows = [];
        $resource_rows = [];
        if (!empty($content_ids)) {
            $ids = array_keys($content_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $list = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, url, content_type FROM $table_content WHERE id IN ($placeholders)",
                ...$ids
            ), ARRAY_A);
            foreach ($list as $r) {
                $content_rows[(int) $r['id']] = ['title' => $r['title'], 'url' => $r['url'], 'content_type' => $r['content_type'] ?? ''];
            }
        }
        if (!empty($resource_ids)) {
            $ids = array_keys($resource_ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $list = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title FROM $table_resources WHERE id IN ($placeholders)",
                ...$ids
            ), ARRAY_A);
            foreach ($list as $r) {
                $resource_rows[(int) $r['id']] = ['title' => $r['title'], 'url' => '', 'content_type' => 'resource'];
            }
        }

        $results = [];
        foreach ($rows as $r) {
            $sid = (int) $r['source_id'];
            $meta = $r['source_type'] === 'content' ? ($content_rows[$sid] ?? []) : ($resource_rows[$sid] ?? []);
            $results[] = [
                'chunk_id'     => (int) $r['id'],
                'source_type'  => $r['source_type'],
                'source_id'    => $sid,
                'chunk_index'  => (int) $r['chunk_index'],
                'chunk_text'   => $r['chunk_text'],
                'score'        => isset($r['relevance']) ? (float) $r['relevance'] : 0,
                'title'        => isset($meta['title']) ? $meta['title'] : 'Unknown',
                'url'          => isset($meta['url']) ? $meta['url'] : '',
                'content_type' => isset($meta['content_type']) ? $meta['content_type'] : '',
            ];
        }
        return $results;
    }

    /**
     * Check if we have any embedded chunks (so we can use hybrid search).
     *
     * @return bool
     */
    public static function has_embeddings() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_chunks';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return false;
        }
        $count = $wpdb->get_var("SELECT 1 FROM $table WHERE embedding IS NOT NULL AND embedding != '' LIMIT 1");
        return (bool) $count;
    }

    /**
     * Get stats: total chunks and how many have embeddings.
     *
     * @return array{total: int, embedded: int}
     */
    public static function get_chunk_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_chunks';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return ['total' => 0, 'embedded' => 0];
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $embedded = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE embedding IS NOT NULL AND embedding != ''");
        return ['total' => $total, 'embedded' => $embedded];
    }

    /**
     * Sync chunks for all content (posts, pages, products). Call after content sync.
     * Deletes existing content chunks, re-chunks, batch-embeds, and stores.
     *
     * @return array{chunks_created: int, chunks_embedded: int, errors: string[]}
     */
    public static function sync_chunks_for_content() {
        global $wpdb;
        $table_chunks = $wpdb->prefix . 'chatzio_chunks';
        $table_content = $wpdb->prefix . 'chatzio_content';
        $result = ['chunks_created' => 0, 'chunks_embedded' => 0, 'errors' => []];

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_chunks));
        if (!$table_exists) {
            $result['errors'][] = 'Chunks table does not exist';
            return $result;
        }

        // Delete all chunks that come from content (we will re-create from current content)
        $wpdb->query("DELETE FROM $table_chunks WHERE source_type = 'content'");

        $content_rows = $wpdb->get_results("SELECT id, title, content, url, content_type, metadata FROM $table_content", ARRAY_A);
        if (empty($content_rows)) {
            update_option('chatzio_last_embedding_sync', current_time('mysql'));
            return $result;
        }

        // Chunk and insert directly (stream — don't accumulate all chunks in memory)
        foreach ($content_rows as $row) {
            $content = isset($row['content']) ? $row['content'] : '';
            // Parse stored metadata (product price, stock, SKU, categories, etc.)
            $stored_meta = json_decode(isset($row['metadata']) ? (string) $row['metadata'] : '{}', true);
            if (!is_array($stored_meta)) {
                $stored_meta = [];
            }
            $stored_meta['content_type'] = isset($row['content_type']) ? $row['content_type'] : '';
            $chunks = self::chunk_content(
                isset($row['title']) ? $row['title'] : '',
                $content,
                isset($row['url']) ? $row['url'] : '',
                $stored_meta
            );
            foreach ($chunks as $i => $c) {
                $wpdb->insert($table_chunks, [
                    'source_type'  => 'content',
                    'source_id'    => (int) $row['id'],
                    'chunk_index'  => $i,
                    'chunk_text'   => $c['text'],
                    'token_count'  => $c['token_estimate'],
                ], ['%s', '%d', '%d', '%s', '%d']);
                $result['chunks_created']++;
            }
            // Free memory after each content item
            unset($chunks);
        }
        unset($content_rows);

        // Batch-embed chunks that have no embedding
        $to_embed = $wpdb->get_results("SELECT id, chunk_text FROM $table_chunks WHERE source_type = 'content' AND (embedding IS NULL OR embedding = '') ORDER BY id ASC", ARRAY_A);
        $texts = array_column($to_embed, 'chunk_text');
        $ids = array_column($to_embed, 'id');
        $offset = 0;
        while ($offset < count($texts)) {
            $batch_texts = array_slice($texts, $offset, self::BATCH_SIZE);
            $batch_ids = array_slice($ids, $offset, self::BATCH_SIZE);
            $embed_result = self::generate_embeddings_batch($batch_texts);
            if ($embed_result['success'] && !empty($embed_result['embeddings'])) {
                foreach ($batch_ids as $i => $chunk_id) {
                    if (isset($embed_result['embeddings'][$i])) {
                        $blob = self::pack_embedding($embed_result['embeddings'][$i]);
                        $wpdb->update($table_chunks, ['embedding' => $blob], ['id' => $chunk_id], ['%s'], ['%d']);
                        $result['chunks_embedded']++;
                    }
                }
            } else {
                $result['errors'][] = $embed_result['error'] ?? 'Embedding batch failed';
            }
            $offset += self::BATCH_SIZE;
        }

        update_option('chatzio_last_embedding_sync', current_time('mysql'));
        return $result;
    }

    /**
     * Sync chunks for a single resource (PDF/doc paste). Call after resource upload/paste.
     *
     * @param int $resource_id chatzio_resources.id
     * @return array{success: bool, chunks_created: int, chunks_embedded: int, error?: string}
     */
    public static function sync_chunks_for_resource($resource_id) {
        global $wpdb;
        $table_chunks = $wpdb->prefix . 'chatzio_chunks';
        $table_resources = $wpdb->prefix . 'chatzio_resources';
        $resource_id = (int) $resource_id;

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_chunks));
        if (!$table_exists) {
            return ['success' => false, 'chunks_created' => 0, 'chunks_embedded' => 0, 'error' => 'Chunks table missing'];
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, content FROM $table_resources WHERE id = %d",
            $resource_id
        ), ARRAY_A);
        if (!$row || empty($row['content'])) {
            $wpdb->delete($table_chunks, ['source_type' => 'resource', 'source_id' => $resource_id], ['%s', '%d']);
            return ['success' => true, 'chunks_created' => 0, 'chunks_embedded' => 0];
        }

        $wpdb->delete($table_chunks, ['source_type' => 'resource', 'source_id' => $resource_id], ['%s', '%d']);
        $chunks = self::chunk_content($row['title'], $row['content'], '', ['content_type' => 'resource']);
        $chunk_ids = [];
        foreach ($chunks as $i => $c) {
            $wpdb->insert($table_chunks, [
                'source_type'  => 'resource',
                'source_id'    => $resource_id,
                'chunk_index'  => $i,
                'chunk_text'   => $c['text'],
                'token_count'  => $c['token_estimate'],
            ], ['%s', '%d', '%d', '%s', '%d']);
            $chunk_ids[] = $wpdb->insert_id;
        }
        $created = count($chunk_ids);
        $texts = array_map(function ($i) use ($chunks) { return $chunks[$i]['text']; }, array_keys($chunks));
        $embed_result = self::generate_embeddings_batch($texts);
        $embedded = 0;
        if ($embed_result['success'] && !empty($embed_result['embeddings'])) {
            foreach ($chunk_ids as $idx => $cid) {
                if (isset($embed_result['embeddings'][$idx])) {
                    $wpdb->update($table_chunks, ['embedding' => self::pack_embedding($embed_result['embeddings'][$idx])], ['id' => $cid], ['%s'], ['%d']);
                    $embedded++;
                }
            }
        }
        return ['success' => true, 'chunks_created' => $created, 'chunks_embedded' => $embedded];
    }
}

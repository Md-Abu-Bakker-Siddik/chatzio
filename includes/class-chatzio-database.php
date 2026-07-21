<?php
/**
 * Database Management Class
 * Handles table creation, schema upgrades, and FULLTEXT indexing
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Database {

    /**
     * Check version and run upgrades if needed
     */
    public static function init() {
        $stored_version = get_option('chatzio_version', '0');
        if (version_compare($stored_version, CHATZIO_VERSION, '<')) {
            self::create_tables();
            self::run_migrations($stored_version);
            update_option('chatzio_version', CHATZIO_VERSION);
        }
    }

    /**
     * Run data migrations based on version
     */
    private static function run_migrations($from_version) {
        global $wpdb;
        
        // Migration: Fix NULL created_at dates in leads table
        if (version_compare($from_version, '5.1.4', '<')) {
            $leads_table = $wpdb->prefix . 'chatzio_leads';
            $wpdb->query("UPDATE $leads_table SET created_at = NOW() WHERE created_at IS NULL");
        }
        
        // Migration: Populate messages JSON column from old schema
        if (version_compare($from_version, '5.1.2', '<')) {
            $conv_table = $wpdb->prefix . 'chatzio_conversations';
            
            // Get all conversations that don't have messages populated
            $rows = $wpdb->get_results("SELECT id, user_message, bot_response FROM $conv_table WHERE messages IS NULL OR messages = ''", ARRAY_A);
            
            foreach ($rows as $row) {
                $messages = [];
                if (!empty($row['user_message'])) {
                    $messages[] = ['role' => 'user', 'content' => $row['user_message']];
                }
                if (!empty($row['bot_response'])) {
                    $messages[] = ['role' => 'assistant', 'content' => $row['bot_response']];
                }
                
                if (!empty($messages)) {
                    $wpdb->update(
                        $conv_table,
                        ['messages' => json_encode($messages)],
                        ['id' => $row['id']],
                        ['%s'],
                        ['%d']
                    );
                }
            }
        }

        // Migration: Composite indexes for content and failed_topics
        if (version_compare($from_version, '5.1.8', '<')) {
            $table_content = $wpdb->prefix . 'chatzio_content';
            $table_failed = $wpdb->prefix . 'chatzio_failed_topics';
            $idx_content = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `$table_content` WHERE Key_name = %s", 'content_type_id'));
            if (empty($idx_content)) {
                $wpdb->query("ALTER TABLE `$table_content` ADD KEY content_type_id (content_type, content_id)");
            }
            $idx_failed = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM `$table_failed` WHERE Key_name = %s", 'resolved_created'));
            if (empty($idx_failed)) {
                $wpdb->query("ALTER TABLE `$table_failed` ADD KEY resolved_created (resolved, created_at)");
            }
        }

        // Migration: Backfill topics on existing conversations using keyword extraction
        if (version_compare($from_version, '5.3.0', '<')) {
            $conv_table = $wpdb->prefix . 'chatzio_conversations';
            $col_exists = $wpdb->get_results("SHOW COLUMNS FROM `$conv_table` LIKE 'topic'");
            if (!empty($col_exists) && class_exists('Chatzio_Topic_Resolver')) {
                $rows = $wpdb->get_results(
                    "SELECT id, user_message FROM $conv_table WHERE topic IS NULL ORDER BY id DESC LIMIT 1000",
                    ARRAY_A
                );
                foreach ($rows as $row) {
                    $topic = Chatzio_Topic_Resolver::extract_from_query($row['user_message']);
                    $wpdb->update($conv_table, ['topic' => $topic], ['id' => $row['id']], ['%s'], ['%d']);
                }
            }
        }
    }

    /**
     * Create/update all tables (idempotent via dbDelta)
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Table for indexed content (posts, pages, products)
        $table_content = $wpdb->prefix . 'chatzio_content';
        $sql_content = "CREATE TABLE $table_content (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            content_id bigint(20) UNSIGNED NOT NULL,
            content_type varchar(50) NOT NULL,
            title text NOT NULL,
            content longtext NOT NULL,
            excerpt text,
            url varchar(500),
            metadata longtext,
            indexed_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY content_id (content_id),
            KEY content_type (content_type)
        ) $charset_collate;";

        // Table for uploaded resources (PDFs, docs)
        $table_resources = $wpdb->prefix . 'chatzio_resources';
        $sql_resources = "CREATE TABLE $table_resources (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            filename varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_type varchar(50) NOT NULL,
            content longtext,
            status varchar(20) DEFAULT 'active',
            uploaded_by bigint(20) UNSIGNED,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Table for conversation history (with feedback column)
        $table_conversations = $wpdb->prefix . 'chatzio_conversations';
        $sql_conversations = "CREATE TABLE $table_conversations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            user_id bigint(20) UNSIGNED,
            user_message text NOT NULL,
            bot_response longtext NOT NULL,
            bot_response_raw longtext,
            context_used longtext,
            feedback varchar(10) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY feedback (feedback)
        ) $charset_collate;";

        // Table for error logs
        $table_logs = $wpdb->prefix . 'chatzio_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_type varchar(50) NOT NULL,
            message text NOT NULL,
            context longtext,
            stack_trace longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY log_type (log_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Table for captured leads
        $table_leads = $wpdb->prefix . 'chatzio_leads';
        $sql_leads = "CREATE TABLE $table_leads (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(100) NOT NULL,
            email varchar(255) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            source varchar(20) DEFAULT 'inchat',
            page_url varchar(500) DEFAULT NULL,
            conversation_count int UNSIGNED DEFAULT 0,
            status varchar(20) DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY email (email),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Table for analytics events
        $table_analytics = $wpdb->prefix . 'chatzio_analytics';
        $sql_analytics = "CREATE TABLE $table_analytics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(100) DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            page_url varchar(500) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY session_id (session_id)
        ) $charset_collate;";

        // Table for failed topics (AI couldn't answer)
        $table_failed_topics = $wpdb->prefix . 'chatzio_failed_topics';
        $sql_failed_topics = "CREATE TABLE $table_failed_topics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(100) DEFAULT NULL,
            user_query text NOT NULL,
            bot_response text DEFAULT NULL,
            confidence_score decimal(3,2) DEFAULT NULL,
            topic_category varchar(100) DEFAULT NULL,
            resolved tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY resolved (resolved),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Table for RAG chunks (content + resources, with optional embedding vector)
        $table_chunks = $wpdb->prefix . 'chatzio_chunks';
        $sql_chunks = "CREATE TABLE $table_chunks (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type varchar(20) NOT NULL,
            source_id bigint(20) UNSIGNED NOT NULL,
            chunk_index smallint(5) UNSIGNED NOT NULL,
            chunk_text text NOT NULL,
            embedding longblob DEFAULT NULL,
            token_count smallint(5) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_lookup (source_type, source_id)
        ) $charset_collate;";

        // Table for restock notification subscriptions
        $table_restock = $wpdb->prefix . 'chatzio_restock_subscriptions';
        $sql_restock = "CREATE TABLE $table_restock (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED NOT NULL,
            email varchar(255) NOT NULL,
            session_id varchar(100) DEFAULT NULL,
            lead_id bigint(20) UNSIGNED DEFAULT NULL,
            source varchar(20) DEFAULT 'inchat',
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            notified_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_email (product_id, email),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql_content);
        dbDelta($sql_resources);
        dbDelta($sql_conversations);
        dbDelta($sql_logs);
        dbDelta($sql_leads);
        dbDelta($sql_analytics);
        dbDelta($sql_failed_topics);
        dbDelta($sql_chunks);
        dbDelta($sql_restock);

        // Add FULLTEXT index for semantic search (dbDelta doesn't handle FULLTEXT well)
        self::add_fulltext_index($table_content);
        self::add_fulltext_index_chunks($table_chunks);

        // Add columns to existing conversations table if missing
        self::maybe_add_column($table_conversations, 'feedback', "VARCHAR(10) DEFAULT NULL");
        self::maybe_add_column($table_conversations, 'bot_response_raw', "LONGTEXT");
        self::maybe_add_column($table_conversations, 'lead_id', "BIGINT(20) UNSIGNED DEFAULT NULL");
        self::maybe_add_column($table_conversations, 'intent', "VARCHAR(30) DEFAULT NULL");
        self::maybe_add_column($table_conversations, 'products_mentioned', "TEXT DEFAULT NULL");
        self::maybe_add_column($table_conversations, 'messages', "LONGTEXT");
        self::maybe_add_column($table_conversations, 'topic', "VARCHAR(50) DEFAULT NULL");

        // Add topic index for fast filtering
        $topic_idx = $wpdb->get_results("SHOW INDEX FROM `$table_conversations` WHERE Key_name = 'topic'");
        if (empty($topic_idx)) {
            $wpdb->query("ALTER TABLE `$table_conversations` ADD KEY topic (topic)");
        }
    }

    /**
     * Add FULLTEXT index if it doesn't exist
     */
    private static function add_fulltext_index($table) {
        global $wpdb;

        $index_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", 'content_search')
        );

        if (empty($index_exists)) {
            // Suppress errors — some hosts restrict FULLTEXT on certain engines
            $wpdb->suppress_errors(true);
            $wpdb->query("ALTER TABLE `$table` ADD FULLTEXT INDEX content_search (title, content)");
            $wpdb->suppress_errors(false);

            if ($wpdb->last_error) {
                Chatzio_Logger::log_warning('Could not create FULLTEXT index — falling back to LIKE search', [
                    'error' => $wpdb->last_error,
                ]);
            }
        }
    }

    /**
     * Add FULLTEXT index on chunks table for hybrid search (chunk_text only).
     */
    private static function add_fulltext_index_chunks($table) {
        global $wpdb;

        $index_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", 'chunk_search')
        );

        if (empty($index_exists)) {
            $wpdb->suppress_errors(true);
            $wpdb->query("ALTER TABLE `$table` ADD FULLTEXT INDEX chunk_search (chunk_text)");
            $wpdb->suppress_errors(false);

            if ($wpdb->last_error) {
                Chatzio_Logger::log_warning('Could not create FULLTEXT index on chunks table', [
                    'error' => $wpdb->last_error,
                ]);
            }
        }
    }

    /**
     * Add a column to a table if it doesn't exist
     */
    private static function maybe_add_column($table, $column, $definition) {
        global $wpdb;

        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $table, $column
            )
        );

        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    /**
     * Check if FULLTEXT index exists (used by search to decide strategy)
     */
    public static function has_fulltext_index() {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_content';

        $index_exists = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", 'content_search')
        );

        return !empty($index_exists);
    }

    /**
     * Drop all plugin tables (uninstall)
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'chatzio_content',
            $wpdb->prefix . 'chatzio_resources',
            $wpdb->prefix . 'chatzio_chunks',
            $wpdb->prefix . 'chatzio_conversations',
            $wpdb->prefix . 'chatzio_logs',
            $wpdb->prefix . 'chatzio_leads',
            $wpdb->prefix . 'chatzio_analytics',
            $wpdb->prefix . 'chatzio_failed_topics',
            $wpdb->prefix . 'chatzio_restock_subscriptions',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
    }
}

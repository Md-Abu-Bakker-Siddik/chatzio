<?php
/**
 * Uninstall Chatzio
 * 
 * This file runs when the plugin is uninstalled (not just deactivated)
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/class-chatzio-database.php';

// Delete all plugin options
delete_option('chatzio_settings');
delete_option('chatzio_version');
delete_option('chatzio_last_sync');
delete_option('chatzio_last_embedding_sync');
delete_option('chatzio_setup_complete');

// Drop all plugin tables
Chatzio_Database::drop_tables();

// Clear ALL scheduled events
wp_clear_scheduled_hook('chatzio_auto_sync');
wp_clear_scheduled_hook('chatzio_failed_topics_digest');
wp_clear_scheduled_hook('chatzio_weekly_summary');
wp_clear_scheduled_hook('chatzio_data_retention');

// Purge all chatzio transients
$transients = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_chatzio_%'
        OR option_name LIKE '_transient_timeout_chatzio_%'"
);
foreach ($transients as $t) {
    delete_option($t);
}

// Delete uploaded resources
$upload_dir = wp_upload_dir();
$chatzio_dir = $upload_dir['basedir'] . '/chatzio-resources';

if (is_dir($chatzio_dir)) {
    $files = glob($chatzio_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($chatzio_dir);
}

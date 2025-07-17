<?php
/**
 * Uninstall WP User Activity Logger
 * 
 * This file is executed when the plugin is deleted from WordPress admin.
 * It removes the custom database table and all plugin options.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove the custom database table
global $wpdb;
$table_name = $wpdb->prefix . 'activity_log';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Remove plugin options
delete_option('wpual_version');

// Clear any cached data that has been removed
wp_cache_flush(); 
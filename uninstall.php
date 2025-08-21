<?php
/**
 * Uninstall script for WooCommerce UPS WWE Plugin
 * 
 * This file is executed when the plugin is deleted from WordPress admin.
 * It removes all plugin data, options, and database entries.
 * 
 * @package WWE_UPS
 * @since 1.0.0
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check - ensure this is the correct plugin being uninstalled
if (!defined('ABSPATH') || !current_user_can('activate_plugins')) {
    exit;
}

/**
 * Remove plugin options from database
 */
function wwe_ups_remove_plugin_options() {
    // Remove WooCommerce shipping method settings
    delete_option('woocommerce_wwe_ups_shipping_method_settings');
    
    // Remove plugin-specific options
    $plugin_options = [
        'wwe_ups_api_credentials',
        'wwe_ups_debug_mode',
        'wwe_ups_test_mode',
        'wwe_ups_shipper_details',
        'wwe_ups_default_packaging',
        'wwe_ups_handling_fee',
        'wwe_ups_max_weight',
        'wwe_ups_version',
        'wwe_ups_installation_date'
    ];
    
    foreach ($plugin_options as $option) {
        delete_option($option);
    }
}

/**
 * Remove plugin transients and cached data
 */
function wwe_ups_remove_transients() {
    global $wpdb;
    
    // Remove all transients starting with 'wwe_ups_'
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_wwe_ups_%',
            '_transient_timeout_wwe_ups_%'
        )
    );
}

/**
 * Remove user meta data related to plugin
 */
function wwe_ups_remove_user_meta() {
    global $wpdb;
    
    // Remove user meta keys related to WWE UPS
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            'wwe_ups_%'
        )
    );
}

/**
 * Remove order meta data (optional - be careful with this)
 * Uncomment only if you want to remove ALL WWE UPS tracking data from orders
 */
function wwe_ups_remove_order_meta() {
    global $wpdb;
    
    // WWE UPS specific meta keys
    $meta_keys = [
        '_wwe_ups_tracking_number',
        '_wwe_ups_label_url',
        '_wwe_ups_shipment_id',
        '_wwe_ups_rate_cost',
        '_wwe_ups_service_code'
    ];
    
    foreach ($meta_keys as $meta_key) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $meta_key
            )
        );
    }
    
    // For HPOS (High-Performance Order Storage)
    if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
        foreach ($meta_keys as $meta_key) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s",
                    $meta_key
                )
            );
        }
    }
}

/**
 * Remove custom database tables (if any were created)
 */
function wwe_ups_remove_custom_tables() {
    global $wpdb;
    
    // Example: if you had created custom tables
    // $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wwe_ups_rates_cache");
    // $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wwe_ups_shipments");
}

/**
 * Clean up log files
 */
function wwe_ups_remove_log_files() {
    // WooCommerce logs are handled by WC itself
    // But if you created custom log files, remove them here
    
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/wwe-ups-logs/';
    
    if (is_dir($log_dir)) {
        $files = glob($log_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($log_dir);
    }
}

/**
 * Main uninstall function
 */
function wwe_ups_uninstall_plugin() {
    // Remove plugin options
    wwe_ups_remove_plugin_options();
    
    // Remove transients and cached data
    wwe_ups_remove_transients();
    
    // Remove user meta data
    wwe_ups_remove_user_meta();
    
    // Remove order meta data (commented out by default for safety)
    // Uncomment the next line ONLY if you want to remove ALL tracking data
    // wwe_ups_remove_order_meta();
    
    // Remove custom database tables
    wwe_ups_remove_custom_tables();
    
    // Clean up log files
    wwe_ups_remove_log_files();
    
    // Clear any WordPress caches
    wp_cache_flush();
    
    // Log the uninstallation (optional)
    error_log('WWE UPS Plugin: Uninstallation completed successfully');
}

// Execute the uninstall process
wwe_ups_uninstall_plugin(); 
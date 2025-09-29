<?php
/**
 * Plugin Name: Super Dispatch Pricing Insights
 * Description: Get real-time freight pricing quotes with custom forms or Gravity Forms integration
 * Version: 1.2.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: super-dispatch-pricing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SDPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SDPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SDPI_VERSION', '1.2.0');

// Include required files
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-settings.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-api.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-form.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-cities.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-maritime.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-history.php';
require_once SDPI_PLUGIN_DIR . 'includes/class-sdpi-session.php';

// Check WooCommerce dependency
function sdpi_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Super Dispatch Pricing Insights:</strong> WooCommerce is required for payment functionality. Please install and activate WooCommerce.</p>';
            echo '</div>';
        });
        return false;
    }
    return true;
}

// Initialize the plugin
function sdpi_init() {
    new SDPI_Settings();
    new SDPI_Cities();
    new SDPI_Form();
    new SDPI_History();
}
add_action('plugins_loaded', 'sdpi_init');

// Hook: on WooCommerce order completed, send to Zapier
add_action('woocommerce_order_status_completed', function($order_id) {
    if (!class_exists('WC_Order')) { return; }
    $order = wc_get_order($order_id);
    if (!$order) { return; }

    // Try to locate quote product
    $quote_data = null;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product) {
            $meta = get_post_meta($product->get_id(), '_sdpi_quote_data', true);
            if ($meta) { $quote_data = $meta; break; }
        }
    }

    // Build and send payload if we have quote data
    if ($quote_data) {
        $form = new SDPI_Form();
        // Send consolidated data using existing method
        $form_reflection = new ReflectionClass($form);
        $method = $form_reflection->getMethod('send_to_zapier');
        $method->setAccessible(true);
        $pickup_zip = isset($quote_data['us_port']) && isset($quote_data['us_port']['zip']) && isset($quote_data['maritime_involved']) && $quote_data['maritime_involved'] && isset($quote_data['final_price']) ? ($quote_data['us_port']['zip']) : (isset($quote_data['pickup_zip']) ? $quote_data['pickup_zip'] : '');
        $delivery_zip = isset($quote_data['delivery_zip']) ? $quote_data['delivery_zip'] : '';
        $method->invoke($form,
            $pickup_zip,
            $delivery_zip,
            isset($quote_data['trailer_type']) ? $quote_data['trailer_type'] : '',
            isset($quote_data['vehicle']['type']) ? $quote_data['vehicle']['type'] : (isset($quote_data['vehicle_type']) ? $quote_data['vehicle_type'] : ''),
            isset($quote_data['vehicle']['inoperable']) ? $quote_data['vehicle']['inoperable'] : (isset($quote_data['vehicle_inoperable']) ? $quote_data['vehicle_inoperable'] : false),
            isset($quote_data['vehicle']['electric']) ? $quote_data['vehicle']['electric'] : (isset($quote_data['vehicle_electric']) ? $quote_data['vehicle_electric'] : false),
            isset($quote_data['vehicle']['make']) ? $quote_data['vehicle']['make'] : (isset($quote_data['vehicle_make']) ? $quote_data['vehicle_make'] : ''),
            isset($quote_data['vehicle']['model']) ? $quote_data['vehicle']['model'] : (isset($quote_data['vehicle_model']) ? $quote_data['vehicle_model'] : ''),
            isset($quote_data['vehicle']['year']) ? $quote_data['vehicle']['year'] : (isset($quote_data['vehicle_year']) ? $quote_data['vehicle_year'] : ''),
            isset($quote_data['final']) ? $quote_data['final'] : $quote_data,
            isset($quote_data['maritime_involved']) ? $quote_data['maritime_involved'] : false
        );

        // Update history Zapier status if possible
        global $wpdb;
        $history_table = $wpdb->prefix . 'sdpi_history';
        // Try to match by ZIP and final price timestamp proximity could be added; for now, set last 1 hour entries with same ZIPs
        $wpdb->query($wpdb->prepare("UPDATE {$history_table} SET zapier_status='sent', zapier_last_sent_at=NOW() WHERE pickup_zip=%s AND delivery_zip=%s AND created_at >= (NOW() - INTERVAL 1 HOUR)",
            isset($quote_data['pickup_zip']) ? $quote_data['pickup_zip'] : '',
            isset($quote_data['delivery_zip']) ? $quote_data['delivery_zip'] : ''
        ));
    }
}, 10, 1);

// Activation hook
register_activation_hook(__FILE__, 'sdpi_activate');
function sdpi_activate() {
    // Set default options
    $current_api_key = get_option('sdpi_api_key');
    if (empty($current_api_key)) {
        add_option('sdpi_api_key', '');
    }
    
    $current_endpoint = get_option('sdpi_api_endpoint');
    if (empty($current_endpoint)) {
        add_option('sdpi_api_endpoint', 'https://pricing-insights.superdispatch.com/api/v1/recommended-price');
    }
    
    $current_cache_time = get_option('sdpi_cache_time');
    if (empty($current_cache_time)) {
        add_option('sdpi_cache_time', 300); // 5 minutes in seconds
    }
    
    // Create cities table
    $cities = new SDPI_Cities();
    $cities->create_table();
    
    // Create history table
    $history = new SDPI_History();
    $history->create_table();
    // Create consolidated session table
    $session = new SDPI_Session();
    $session->create_table();
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Add admin notice about configuration
    add_action('admin_notices', function() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Super Dispatch Pricing Insights:</strong> Plugin activated successfully! Please configure your API key in <a href="' . admin_url('options-general.php?page=super-dispatch-pricing') . '">Settings → Super Dispatch Pricing</a>.</p>';
        echo '</div>';
    });
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'sdpi_deactivate');
function sdpi_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Note: We don't delete options on deactivation to preserve user settings
}

// Add AJAX handler for testing API connection
add_action('wp_ajax_sdpi_test_api_connection', 'sdpi_test_api_connection');
function sdpi_test_api_connection() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'sdpi_test_nonce')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    // Test API connection
    $api = new SDPI_API();
    $test_data = array(
        'pickup' => array('zip' => '10001'),
        'delivery' => array('zip' => '90210'),
        'trailer_type' => 'open',
        'vehicles' => array(
            array(
                'type' => 'sedan',
                'is_inoperable' => false,
                'make' => 'Toyota',
                'model' => 'Camry',
                'year' => 2020
            )
        )
    );
    
    $response = $api->get_pricing_quote($test_data);
    
    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => $response->get_error_message()
        ));
    } else {
        wp_send_json_success(array(
            'message' => 'API connection successful! Test quote received.',
            'data' => $response
        ));
    }
}

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sdpi_plugin_action_links');
function sdpi_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=super-dispatch-pricing') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

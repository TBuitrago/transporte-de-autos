<?php
/**
 * Plugin Name: Transporte de Autos
 * Description: Get real-time freight pricing quotes with custom forms or Gravity Forms integration
 * Version: 1.4.0
 * Author: Tomas Buitrago - TBA Digitals
 * License: GPL v2 or later
 * Text Domain: transporte-de-autos
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TDA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TDA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TDA_VERSION', '1.4.0');

// Backwards compatibility with legacy constant names
if (!defined('SDPI_PLUGIN_DIR')) {
    define('SDPI_PLUGIN_DIR', TDA_PLUGIN_DIR);
}

if (!defined('SDPI_PLUGIN_URL')) {
    define('SDPI_PLUGIN_URL', TDA_PLUGIN_URL);
}

if (!defined('SDPI_VERSION')) {
    define('SDPI_VERSION', TDA_VERSION);
}

// Include required files
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-settings.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-api.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-form.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-cities.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-maritime.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-history.php';
require_once TDA_PLUGIN_DIR . 'includes/class-sdpi-session.php';
require_once TDA_PLUGIN_DIR . 'auth/autoload.php';
require_once TDA_PLUGIN_DIR . 'includes/class-tda-updater.php';

new TDA_GitHub_Updater(
    __FILE__,
    array(
        'slug'              => 'transporte-de-autos',
        'additional_slugs'  => array('transporte-de-autos', 'super-dispatch-pricing-insights'),
        'user'              => 'tbadigitals',
        'repo'              => 'transporte-de-autos',
    )
);

function tda_load_textdomain() {
    load_plugin_textdomain('transporte-de-autos', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'tda_load_textdomain');

// Initialize the plugin
function sdpi_init() {
    new SDPI_Settings();
    new SDPI_Cities();
    new SDPI_Form();
    new SDPI_History();
}
add_action('plugins_loaded', 'sdpi_init');

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

    if (false === get_option('sdpi_authorize_environment', false)) {
        add_option('sdpi_authorize_environment', 'sandbox');
    }

    if (false === get_option('sdpi_authorize_api_login_id', false)) {
        add_option('sdpi_authorize_api_login_id', '');
    }

    if (false === get_option('sdpi_authorize_transaction_key', false)) {
        add_option('sdpi_authorize_transaction_key', '');
    }

    if (false === get_option('sdpi_authorize_public_client_key', false)) {
        add_option('sdpi_authorize_public_client_key', '');
    }

    if (false === get_option('sdpi_payment_success_url', false)) {
        add_option('sdpi_payment_success_url', '');
    }

    if (false === get_option('sdpi_payment_error_url', false)) {
        add_option('sdpi_payment_error_url', '');
    }
    
    // Create cities table and import packaged dataset
    $cities = new SDPI_Cities();
    $cities->create_table();
    $import_result = $cities->import_packaged_cities(true);
    if (is_wp_error($import_result)) {
        error_log('SDPI Cities import failed: ' . $import_result->get_error_message());
    }

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
        echo '<p><strong>Transporte de Autos:</strong> Plugin activated successfully! Please configure your API key in <a href="' . admin_url('options-general.php?page=transporte-de-autos') . '">Settings → Transporte de Autos</a>.</p>';
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
    $settings_link = '<a href="' . admin_url('options-general.php?page=transporte-de-autos') . '">' . esc_html__('Settings', 'transporte-de-autos') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

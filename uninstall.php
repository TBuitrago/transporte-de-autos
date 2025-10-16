<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'sdpi_cities',
    $wpdb->prefix . 'sdpi_history',
    $wpdb->prefix . 'sdpi_quote_sessions',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$options = array(
    'sdpi_api_key',
    'sdpi_api_endpoint',
    'sdpi_cache_time',
    'sdpi_zapier_webhook_url',
    'sdpi_authorize_environment',
    'sdpi_authorize_api_login_id',
    'sdpi_authorize_transaction_key',
    'sdpi_authorize_public_client_key',
    'sdpi_payment_success_url',
    'sdpi_payment_error_url',
    'sdpi_privacy_policy_url',
    'sdpi_terms_conditions_url'
);

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option);
}

$option_prefixes = array(
    '_transient_sdpi_',
    '_transient_timeout_sdpi_',
    '_site_transient_sdpi_',
    '_site_transient_timeout_sdpi_',
    '_transient_tda_github_release_',
    '_transient_timeout_tda_github_release_',
    '_site_transient_tda_github_release_',
    '_site_transient_timeout_tda_github_release_'
);

foreach ($option_prefixes as $prefix) {
    $like = $wpdb->esc_like($prefix) . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}

if (is_multisite() && !empty($wpdb->sitemeta)) {
    foreach ($option_prefixes as $prefix) {
        $like = $wpdb->esc_like($prefix) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like));
    }
}

if (function_exists('_get_cron_array') && function_exists('wp_clear_scheduled_hook')) {
    $cron_array = _get_cron_array();
    if (is_array($cron_array)) {
        foreach ($cron_array as $timestamp => $events) {
            foreach ($events as $hook => $details) {
                if (strpos($hook, 'sdpi_') === 0 || strpos($hook, 'tda_') === 0) {
                    wp_clear_scheduled_hook($hook);
                }
            }
        }
    }
}

if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}

<?php
/**
 * Settings class for Super Dispatch Pricing Insights
 */

class SDPI_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    public function add_admin_menu() {
        add_options_page(
            'Super Dispatch Pricing Insights',
            'Super Dispatch Pricing',
            'manage_options',
            'super-dispatch-pricing',
            array($this, 'settings_page')
        );
    }

    public function settings_init() {
        register_setting('sdpi_settings', 'sdpi_api_key');
        register_setting('sdpi_settings', 'sdpi_api_endpoint');
        register_setting('sdpi_settings', 'sdpi_cache_time');

        add_settings_section(
            'sdpi_api_section',
            'API Configuration',
            array($this, 'api_section_callback'),
            'sdpi_settings'
        );

        add_settings_field(
            'sdpi_api_key',
            'API Key',
            array($this, 'api_key_field_callback'),
            'sdpi_settings',
            'sdpi_api_section'
        );

        add_settings_field(
            'sdpi_api_endpoint',
            'API Endpoint',
            array($this, 'api_endpoint_field_callback'),
            'sdpi_settings',
            'sdpi_api_section'
        );

        add_settings_field(
            'sdpi_cache_time',
            'Cache Time (seconds)',
            array($this, 'cache_time_field_callback'),
            'sdpi_settings',
            'sdpi_api_section'
        );

        add_settings_field(
            'sdpi_test_connection',
            'Test Connection',
            array($this, 'test_connection_field_callback'),
            'sdpi_settings',
            'sdpi_api_section'
        );
    }

    public function api_section_callback() {
        echo '<p>Configure your Super Dispatch Pricing Insights API settings. The plugin will use these settings to connect to the Super Dispatch API.</p>';
    }

    public function api_key_field_callback() {
        $api_key = get_option('sdpi_api_key');
        echo '<input type="password" name="sdpi_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
        echo '<p class="description">Your Super Dispatch API Key (keep this secure)</p>';
        
        if (empty($api_key)) {
            echo '<p class="description" style="color: #d63638;"><strong>⚠️ API Key Required:</strong> Please enter your Super Dispatch API key to enable the plugin.</strong></p>';
        }
    }

    public function api_endpoint_field_callback() {
        $endpoint = get_option('sdpi_api_endpoint');
        echo '<input type="url" name="sdpi_api_endpoint" value="' . esc_url($endpoint) . '" class="regular-text" />';
        echo '<p class="description">The API endpoint URL for Super Dispatch Pricing Insights</p>';
    }

    public function cache_time_field_callback() {
        $cache_time = get_option('sdpi_cache_time', 300);
        echo '<input type="number" name="sdpi_cache_time" value="' . esc_attr($cache_time) . '" class="small-text" min="60" />';
        echo '<p class="description">Cache duration in seconds (default: 300 = 5 minutes)</p>';
    }

    public function test_connection_field_callback() {
        $api_key = get_option('sdpi_api_key');
        if (empty($api_key)) {
            echo '<p style="color: #d63638;">Please save your API key first before testing the connection.</p>';
            return;
        }
        
        echo '<button type="button" id="sdpi-test-connection" class="button button-secondary">Test API Connection</button>';
        echo '<div id="sdpi-test-result" style="margin-top: 10px;"></div>';
        echo '<script>
            jQuery(document).ready(function($) {
                $("#sdpi-test-connection").on("click", function() {
                    var button = $(this);
                    var resultDiv = $("#sdpi-test-result");
                    
                    button.prop("disabled", true).text("Testing...");
                    resultDiv.html("<p>Testing connection...</p>");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "sdpi_test_api_connection",
                            nonce: "' . wp_create_nonce('sdpi_test_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                resultDiv.html("<p style=\"color: #00a32a;\">✅ Connection successful! API is responding correctly.</p>");
                            } else {
                                resultDiv.html("<p style=\"color: #d63638;\">❌ Connection failed: " + response.data.message + "</p>");
                            }
                        },
                        error: function() {
                            resultDiv.html("<p style=\"color: #d63638;\">❌ Connection failed: Network error</p>");
                        },
                        complete: function() {
                            button.prop("disabled", false).text("Test API Connection");
                        }
                    });
                });
            });
        </script>';
    }

    public function admin_notices() {
        $api_key = get_option('sdpi_api_key');
        if (empty($api_key)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Super Dispatch Pricing Insights:</strong> Please configure your API key in <a href="' . admin_url('options-general.php?page=super-dispatch-pricing') . '">Settings → Super Dispatch Pricing</a> to enable the plugin.</p>';
            echo '</div>';
        }
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Plugin Status</h2>
                <?php
                $api_key = get_option('sdpi_api_key');
                $endpoint = get_option('sdpi_api_endpoint');
                
                if (!empty($api_key)) {
                    echo '<p style="color: #00a32a;">✅ <strong>Plugin Active:</strong> API key is configured.</p>';
                } else {
                    echo '<p style="color: #d63638;">❌ <strong>Plugin Inactive:</strong> API key is required.</p>';
                }
                
                if (!empty($endpoint)) {
                    echo '<p style="color: #00a32a;">✅ <strong>API Endpoint:</strong> ' . esc_html($endpoint) . '</p>';
                } else {
                    echo '<p style="color: #d63638;">❌ <strong>API Endpoint:</strong> Not configured</p>';
                }
                ?>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('sdpi_settings');
                do_settings_sections('sdpi_settings');
                submit_button('Save Settings');
                ?>
            </form>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Usage Instructions</h2>
                <p><strong>Shortcode:</strong> Use <code>[super_dispatch_pricing_form]</code> in any page or post to display the pricing form.</p>
                <p><strong>Form Fields:</strong> The form includes pickup/delivery ZIP codes, trailer type, vehicle type, and optional vehicle details.</p>
                <p><strong>API Response:</strong> Shows recommended price and confidence score from Super Dispatch.</p>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Support & Troubleshooting</h2>
                <ul>
                    <li>Ensure your API key is valid and active in Super Dispatch</li>
                    <li>Check that the API endpoint is accessible from your server</li>
                    <li>Enable WP_DEBUG for detailed error logging</li>
                    <li>Verify that all required form fields are filled</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

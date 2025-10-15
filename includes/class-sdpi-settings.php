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
        register_setting('sdpi_settings', 'sdpi_api_key', array('sanitize_callback' => array($this, 'sanitize_trimmed_text')));
        register_setting('sdpi_settings', 'sdpi_api_endpoint', array('sanitize_callback' => array($this, 'sanitize_url_field')));
        register_setting('sdpi_settings', 'sdpi_cache_time', array('sanitize_callback' => array($this, 'sanitize_integer_field')));
        register_setting('sdpi_settings', 'sdpi_zapier_webhook_url', array('sanitize_callback' => array($this, 'sanitize_url_field')));
        register_setting('sdpi_settings', 'sdpi_authorize_environment', array('sanitize_callback' => array($this, 'sanitize_authorize_environment')));
        register_setting('sdpi_settings', 'sdpi_authorize_api_login_id', array('sanitize_callback' => array($this, 'sanitize_trimmed_text')));
        register_setting('sdpi_settings', 'sdpi_authorize_transaction_key', array('sanitize_callback' => array($this, 'sanitize_trimmed_text')));
        register_setting('sdpi_settings', 'sdpi_authorize_public_client_key', array('sanitize_callback' => array($this, 'sanitize_trimmed_text')));
        register_setting('sdpi_settings', 'sdpi_payment_success_url', array('sanitize_callback' => array($this, 'sanitize_url_field')));
        register_setting('sdpi_settings', 'sdpi_payment_error_url', array('sanitize_callback' => array($this, 'sanitize_url_field')));
        register_setting('sdpi_settings', 'sdpi_privacy_policy_url', array('sanitize_callback' => array($this, 'sanitize_url_field')));
        register_setting('sdpi_settings', 'sdpi_terms_conditions_url', array('sanitize_callback' => array($this, 'sanitize_url_field')));

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

        add_settings_section(
            'sdpi_integrations_section',
            'Integrations',
            array($this, 'integrations_section_callback'),
            'sdpi_settings'
        );

        add_settings_field(
            'sdpi_zapier_webhook_url',
            'Zapier Webhook URL',
            array($this, 'zapier_webhook_field_callback'),
            'sdpi_settings',
            'sdpi_integrations_section'
        );

        add_settings_section(
            'sdpi_authorize_section',
            'Pagos con Authorize.net',
            array($this, 'authorize_section_callback'),
            'sdpi_settings'
        );

        add_settings_field(
            'sdpi_authorize_environment',
            'Entorno',
            array($this, 'authorize_environment_field_callback'),
            'sdpi_settings',
            'sdpi_authorize_section'
        );

        add_settings_field(
            'sdpi_authorize_api_login_id',
            'API Login ID',
            array($this, 'authorize_api_login_field_callback'),
            'sdpi_settings',
            'sdpi_authorize_section'
        );

        add_settings_field(
            'sdpi_authorize_transaction_key',
            'Transaction Key',
            array($this, 'authorize_transaction_key_field_callback'),
            'sdpi_settings',
            'sdpi_authorize_section'
        );

        add_settings_field(
            'sdpi_authorize_public_client_key',
            'Public Client Key',
            array($this, 'authorize_public_client_key_field_callback'),
            'sdpi_settings',
            'sdpi_authorize_section'
        );

        add_settings_section(
            'sdpi_payment_redirects_section',
            'Redirecciones de Pago',
            array($this, 'payment_redirects_section_callback'),
            'sdpi_settings'
        );

        add_settings_field(
            'sdpi_payment_success_url',
            'URL de "Gracias"',
            array($this, 'payment_success_url_field_callback'),
            'sdpi_settings',
            'sdpi_payment_redirects_section'
        );

        add_settings_field(
            'sdpi_payment_error_url',
            'URL de "Error"',
            array($this, 'payment_error_url_field_callback'),
            'sdpi_settings',
            'sdpi_payment_redirects_section'
        );

        add_settings_section(
            'sdpi_legal_section',
            'Avisos Legales',
            array($this, 'legal_section_callback'),
            'sdpi_settings'
        );

        add_settings_field(
            'sdpi_privacy_policy_url',
            'URL de Política de Privacidad',
            array($this, 'privacy_policy_url_field_callback'),
            'sdpi_settings',
            'sdpi_legal_section'
        );

        add_settings_field(
            'sdpi_terms_conditions_url',
            'URL de Términos y Condiciones',
            array($this, 'terms_conditions_url_field_callback'),
            'sdpi_settings',
            'sdpi_legal_section'
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

    public function integrations_section_callback() {
        echo '<p>Configure third-party integrations for the pricing form. Data from completed quotes will be automatically sent to these services.</p>';
    }

    public function zapier_webhook_field_callback() {
        $webhook_url = get_option('sdpi_zapier_webhook_url');
        echo '<input type="url" name="sdpi_zapier_webhook_url" value="' . esc_url($webhook_url) . '" class="regular-text" placeholder="https://hooks.zapier.com/hooks/catch/..." />';
        echo '<p class="description">Zapier webhook URL to send quote data automatically after each successful quote generation</p>';

        if (!empty($webhook_url)) {
            echo '<p class="description" style="color: #00a32a;">✅ <strong>Zapier Integration Active:</strong> Quote data will be sent to Zapier after each successful quote.</p>';
        } else {
            echo '<p class="description" style="color: #f0b849;">ℹ️ <strong>Optional:</strong> Leave empty to disable Zapier integration.</p>';
        }
    }

    public function authorize_section_callback() {
        echo '<p>Configura las credenciales de Authorize.net para procesar pagos directamente desde el formulario. Proporciona las claves correspondientes al entorno seleccionado.</p>';
    }

    public function authorize_environment_field_callback() {
        $environment = get_option('sdpi_authorize_environment', 'sandbox');
        ?>
        <select name="sdpi_authorize_environment">
            <option value="sandbox" <?php selected($environment, 'sandbox'); ?>>Sandbox</option>
            <option value="production" <?php selected($environment, 'production'); ?>>Producción</option>
        </select>
        <p class="description">Selecciona el entorno de Authorize.net que utilizará el formulario.</p>
        <?php
    }

    public function authorize_api_login_field_callback() {
        $login_id = get_option('sdpi_authorize_api_login_id');
        echo '<input type="text" name="sdpi_authorize_api_login_id" value="' . esc_attr($login_id) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">API Login ID proporcionado por Authorize.net.</p>';
    }

    public function authorize_transaction_key_field_callback() {
        $transaction_key = get_option('sdpi_authorize_transaction_key');
        echo '<input type="password" name="sdpi_authorize_transaction_key" value="' . esc_attr($transaction_key) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">Transaction Key generado en Authorize.net. Mantén este valor en secreto.</p>';
    }

    public function authorize_public_client_key_field_callback() {
        $client_key = get_option('sdpi_authorize_public_client_key');
        echo '<input type="text" name="sdpi_authorize_public_client_key" value="' . esc_attr($client_key) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description">Public Client Key (opcional, solo necesario si decides usar Accept.js en el futuro).</p>';
    }

    public function payment_redirects_section_callback() {
        echo '<p>Define las URLs de redirección después de procesar el pago.</p>';
    }

    public function payment_success_url_field_callback() {
        $url = get_option('sdpi_payment_success_url');
        echo '<input type="url" name="sdpi_payment_success_url" value="' . esc_url($url) . '" class="regular-text" placeholder="https://tu-sitio.com/gracias" />';
        echo '<p class="description">URL a la que se redirige al usuario cuando la transacción es aprobada.</p>';
    }

    public function payment_error_url_field_callback() {
        $url = get_option('sdpi_payment_error_url');
        echo '<input type="url" name="sdpi_payment_error_url" value="' . esc_url($url) . '" class="regular-text" placeholder="https://tu-sitio.com/error" />';
        echo '<p class="description">URL a la que se redirige al usuario cuando la transacción falla.</p>';
    }

    public function admin_notices() {
        $api_key = get_option('sdpi_api_key');
        if (empty($api_key)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Super Dispatch Pricing Insights:</strong> Please configure your API key in <a href="' . admin_url('options-general.php?page=super-dispatch-pricing') . '">Settings → Super Dispatch Pricing</a> to enable the plugin.</p>';
            echo '</div>';
        }

        if (!$this->is_authorize_configured()) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Super Dispatch Pricing Insights:</strong> Configura tus credenciales de Authorize.net en <a href="' . admin_url('options-general.php?page=super-dispatch-pricing') . '">Settings → Super Dispatch Pricing</a> para habilitar el cobro con tarjeta.</p>';
            echo '</div>';
        }

        if ($this->is_authorize_configured() && !$this->is_site_https()) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Super Dispatch Pricing Insights:</strong> Authorize.net requiere que tu sitio cargue sobre <code>https://</code>. Configura un certificado SSL y actualiza la URL del sitio antes de intentar procesar pagos.</p>';
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
                $zapier_webhook = get_option('sdpi_zapier_webhook_url');
                $authorize_ready = $this->is_authorize_configured();
                $authorize_environment = get_option('sdpi_authorize_environment', 'sandbox');
                $success_url = get_option('sdpi_payment_success_url');
                $error_url = get_option('sdpi_payment_error_url');

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

                if (!empty($zapier_webhook)) {
                    echo '<p style="color: #00a32a;">✅ <strong>Zapier Integration:</strong> Active - Quote data will be sent to Zapier</p>';
                } else {
                    echo '<p style="color: #f0b849;">ℹ️ <strong>Zapier Integration:</strong> Not configured (optional)</p>';
                }

                if ($authorize_ready) {
                    $environment_label = $authorize_environment === 'production' ? 'Producción' : 'Sandbox';
                    echo '<p style="color: #00a32a;">✅ <strong>Pagos Authorize.net:</strong> Credenciales configuradas (' . esc_html($environment_label) . ').</p>';
                } else {
                    echo '<p style="color: #d63638;">❌ <strong>Pagos Authorize.net:</strong> Ingresar API Login ID, Transaction Key y Public Client Key.</p>';
                }

                if (!empty($success_url)) {
                    echo '<p style="color: #00a32a;">✅ <strong>URL de “Gracias”:</strong> ' . esc_html($success_url) . '</p>';
                } else {
                    echo '<p style="color: #f0b849;">ℹ️ <strong>URL de “Gracias”:</strong> No configurada.</p>';
                }

                if (!empty($error_url)) {
                    echo '<p style="color: #00a32a;">✅ <strong>URL de “Error”:</strong> ' . esc_html($error_url) . '</p>';
                } else {
                    echo '<p style="color: #f0b849;">ℹ️ <strong>URL de “Error”:</strong> No configurada.</p>';
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
                <p><strong>Zapier Integration:</strong> If configured, quote data is automatically sent to your Zapier webhook after each successful quote generation.</p>
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

    public function legal_section_callback() {
        echo '<p>Configura los enlaces legales que se mostrarán en el formulario de información de contacto.</p>';
    }

    public function privacy_policy_url_field_callback() {
        $privacy_url = get_option('sdpi_privacy_policy_url');
        echo '<input type="url" name="sdpi_privacy_policy_url" value="' . esc_url($privacy_url) . '" class="regular-text" placeholder="https://tusitio.com/politica-de-privacidad" />';
        echo '<p class="description">URL que se usará para el enlace de la Política de Privacidad.</p>';
    }

    public function terms_conditions_url_field_callback() {
        $terms_url = get_option('sdpi_terms_conditions_url');
        echo '<input type="url" name="sdpi_terms_conditions_url" value="' . esc_url($terms_url) . '" class="regular-text" placeholder="https://tusitio.com/terminos" />';
        echo '<p class="description">URL que se usará para el enlace de los Términos y Condiciones.</p>';
    }

    public function sanitize_trimmed_text($value) {
        if (is_string($value)) {
            $value = trim($value);
        } else {
            $value = '';
        }
        return sanitize_text_field($value);
    }

    public function sanitize_integer_field($value) {
        $value = intval($value);
        return $value > 0 ? $value : 300;
    }

    public function sanitize_url_field($value) {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return esc_url_raw($value);
    }

    public function sanitize_authorize_environment($value) {
        $value = is_string($value) ? strtolower(trim($value)) : 'sandbox';
        return in_array($value, array('sandbox', 'production'), true) ? $value : 'sandbox';
    }

    private function is_authorize_configured() {
        $login_id = get_option('sdpi_authorize_api_login_id');
        $transaction_key = get_option('sdpi_authorize_transaction_key');
        return !empty($login_id) && !empty($transaction_key);
    }

    private function is_site_https() {
        $home = home_url();
        if (strpos($home, 'https://') === 0) {
            return true;
        }

        if (function_exists('is_ssl') && is_ssl()) {
            return true;
        }

        if (defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN) {
            return true;
        }

        return false;
    }
}

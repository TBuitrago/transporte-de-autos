<?php
/**
 * Custom form handler for Transporte de Autos - FIXED VERSION
 */

use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class SDPI_Form {

    private $api;
    private $summary_icons = array();
    private $documentation_allowed_mimes = array(
        'jpg|jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf'
    );
    private $documentation_max_files = 5;
    private $documentation_max_size = 10485760; // 10 MB
    private static $documentation_upload_subdir = 'documentos-cotizador';

    public function __construct() {
        $this->api = new SDPI_API();
        $this->init_hooks();
    }

    public function init_hooks() {
        // Register shortcode
        add_shortcode('super_dispatch_pricing_form', array($this, 'render_form'));

        // Handle form submission
        add_action('init', array($this, 'handle_form_submission'));

        // Enqueue styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));

        // Add AJAX handler for better user experience
        add_action('wp_ajax_sdpi_get_quote', array($this, 'ajax_get_quote'));
        add_action('wp_ajax_nopriv_sdpi_get_quote', array($this, 'ajax_get_quote'));

        // Add AJAX handler for payment
        add_action('wp_ajax_sdpi_initiate_payment', array($this, 'ajax_initiate_payment'));
        add_action('wp_ajax_nopriv_sdpi_initiate_payment', array($this, 'ajax_initiate_payment'));
        add_action('wp_ajax_sdpi_process_payment', array($this, 'ajax_process_payment'));
        add_action('wp_ajax_nopriv_sdpi_process_payment', array($this, 'ajax_process_payment'));

        // Add AJAX handler for additional shipping info
        add_action('wp_ajax_sdpi_save_additional_info', array($this, 'ajax_save_additional_info'));
        add_action('wp_ajax_nopriv_sdpi_save_additional_info', array($this, 'ajax_save_additional_info'));

        // Add AJAX handler for maritime additional info
        add_action('wp_ajax_sdpi_save_maritime_info', array($this, 'ajax_save_maritime_info'));
        add_action('wp_ajax_nopriv_sdpi_save_maritime_info', array($this, 'ajax_save_maritime_info'));

        // Add AJAX handler for client info capture
        add_action('wp_ajax_sdpi_save_client_info', array($this, 'ajax_save_client_info'));
        add_action('wp_ajax_nopriv_sdpi_save_client_info', array($this, 'ajax_save_client_info'));

        // Add new AJAX handler for finalizing quote with contact info
        add_action('wp_ajax_sdpi_finalize_quote_with_contact', array($this, 'ajax_finalize_quote_with_contact'));
        add_action('wp_ajax_nopriv_sdpi_finalize_quote_with_contact', array($this, 'ajax_finalize_quote_with_contact'));

        // Documentation uploads
        add_action('wp_ajax_sdpi_upload_document_file', array($this, 'ajax_upload_document_file'));
        add_action('wp_ajax_nopriv_sdpi_upload_document_file', array($this, 'ajax_upload_document_file'));
        add_action('wp_ajax_sdpi_delete_document_file', array($this, 'ajax_delete_document_file'));
        add_action('wp_ajax_nopriv_sdpi_delete_document_file', array($this, 'ajax_delete_document_file'));
        add_action('wp_ajax_sdpi_list_document_files', array($this, 'ajax_list_document_files'));
        add_action('wp_ajax_nopriv_sdpi_list_document_files', array($this, 'ajax_list_document_files'));

        // Enqueue JavaScript
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            'sdpi-form-styles',
            plugin_dir_url(__FILE__) . '../assets/form-styles.css',
            array(),
            SDPI_VERSION
        );
    }

    public function enqueue_scripts() {
        $authorize_ready = $this->is_authorize_ready();
        $is_secure = $this->is_request_secure();

        $dependencies = array('jquery');

        // Enqueue form script
        wp_enqueue_script(
            'sdpi-form-script',
            plugin_dir_url(__FILE__) . '../assets/form-script.js',
            $dependencies,
            SDPI_VERSION,
            true
        );

        $privacy_policy_url = esc_url(get_option('sdpi_privacy_policy_url'));
        $terms_conditions_url = esc_url(get_option('sdpi_terms_conditions_url'));

        wp_localize_script('sdpi-form-script', 'sdpi_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sdpi_nonce'),
            'loading_text' => 'Obteniendo cotizacion...',
            'error_text' => 'Error al obtener cotizacion'
        ));

        wp_localize_script('sdpi-form-script', 'sdpi_legal', array(
            'privacy_policy_url' => $privacy_policy_url,
            'terms_conditions_url' => $terms_conditions_url,
            'checkbox_text' => 'Al continuar aceptas nuestra {privacy_link} y nuestros {terms_link}.',
            'privacy_text' => 'Política de Privacidad',
            'terms_text' => 'Términos y Condiciones',
            'error_message' => 'Debes aceptar la Política de Privacidad y los Términos y Condiciones para continuar.'
        ));

        wp_localize_script('sdpi-form-script', 'sdpi_payment', array(
            'enabled' => $authorize_ready && $is_secure,
            'success_url' => esc_url(get_option('sdpi_payment_success_url')),
            'error_url' => esc_url(get_option('sdpi_payment_error_url')),
            'message' => ($authorize_ready && !$is_secure)
                ? __('Los pagos con tarjeta requieren que el sitio esté publicado con HTTPS.', 'transporte-de-autos')
                : ''
        ));

    }

    /**
     * Check if Authorize.net credentials are configured
     */
    private function is_authorize_ready() {
        $login_id = get_option('sdpi_authorize_api_login_id');
        $transaction_key = get_option('sdpi_authorize_transaction_key');
        return !empty($login_id) && !empty($transaction_key);
    }

    /**
     * Get Authorize.net environment endpoint
     */
    private function get_authorize_endpoint() {
        $environment = get_option('sdpi_authorize_environment', 'sandbox');
        return $environment === 'production'
            ? 'https://api2.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
    }

    /**
     * Split full name into first and last names
     */
    private function split_full_name($full_name) {
        $full_name = trim((string)$full_name);
        if ($full_name === '') {
            return array('Cliente', 'SDPI');
        }

        $parts = preg_split('/\s+/', $full_name);
        $first = array_shift($parts);
        $last = !empty($parts) ? implode(' ', $parts) : $first;
        if ($last === $first) {
            $last = 'Cliente';
        }

        return array(
            sanitize_text_field($first),
            sanitize_text_field($last)
        );
    }

    /**
     * Build a human-readable payment description
     */
    private function build_payment_description($quote_data) {
        $parts = array('Vehicle Shipping Quote');

        $pickup_zip = isset($quote_data['pickup_zip']) ? sanitize_text_field($quote_data['pickup_zip']) : '';
        $delivery_zip = isset($quote_data['delivery_zip']) ? sanitize_text_field($quote_data['delivery_zip']) : '';
        if ($pickup_zip && $delivery_zip) {
            $parts[] = $pickup_zip . ' → ' . $delivery_zip;
        }

        $transport = !empty($quote_data['maritime_involved']) ? 'Maritime' : 'Terrestrial';
        $parts[] = $transport;

        if (isset($quote_data['final_price'])) {
            $amount = number_format((float)$quote_data['final_price'], 2, '.', '');
            $parts[] = '$' . $amount . ' USD';
        }

        $description = implode(' | ', array_filter($parts));

        // Authorize.net limits description length to 255 characters
        if (strlen($description) > 255) {
            $description = substr($description, 0, 252) . '...';
        }

        return $description;
    }

    /**
     * Determine if the current request is served over HTTPS
     */
    private function is_request_secure() {
        if (function_exists('is_ssl') && is_ssl()) {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        return false;
    }

    private function normalize_digits($value, $length = null) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($length !== null) {
            $digits = substr($digits, 0, (int) $length);
        }
        return $digits;
    }

    private function validate_us_zip($zip) {
        return (bool) preg_match('/^\d{5}$/', (string) $zip);
    }

    private function validate_us_phone($phone) {
        return strlen($this->normalize_digits($phone)) === 10;
    }

    private function validate_state_code($state) {
        return (bool) preg_match('/^[A-Za-z]{2}$/', (string) $state);
    }

    private function normalize_state_code($state) {
        $letters = preg_replace('/[^A-Za-z]/', '', (string) $state);
        return strtoupper(substr($letters, 0, 2));
    }

    private function validate_person_name($value) {
        return (bool) preg_match("/^[\p{L}\s'\-\.,]+$/u", (string) $value);
    }

    private function validate_city_name($value) {
        return (bool) preg_match("/^[\p{L}\s'\-\.,]+$/u", (string) $value);
    }

    private function validate_address_line($value) {
        return (bool) preg_match("/^[\p{L}0-9\s'\-#\.,&]+$/u", (string) $value);
    }

    private function validate_generic_text($value) {
        return (bool) preg_match("/^[\p{L}0-9\s'\-\.,]+$/u", (string) $value);
    }

    private function validate_vehicle_year_value($year) {
        $year = (int) $year;
        $current = (int) date('Y') + 1;
        return $year > 1800 && $year <= $current;
    }

    private function validate_dimensions_value($value) {
        if ($value === '' || $value === null) {
            return true;
        }
        return (bool) preg_match("/^\d+(?:\.\d+)?x\d+(?:\.\d+)?x\d+(?:\.\d+)?(?:\s?(?:ft|feet|'|\"|in|cm|m))?$/i", (string) $value);
    }

    private function validate_allowed_country($country) {
        $normalized = strtoupper(trim((string) $country));
        return in_array($normalized, array('USA', 'PUERTO RICO'), true);
    }

    /**
     * Check if client contact info has been captured
     */
    private function has_client_info() {
        if (!session_id()) {
            session_start();
        }
        return isset($_SESSION['sdpi_client_info']) && !empty($_SESSION['sdpi_client_info']);
    }

    /**
     * Ensure quote session is started and return its id
     */
    private function ensure_quote_session() {
        $session = new SDPI_Session();
        $session_id = $session->get_session_id();
        if (!$session_id && isset($_SESSION['sdpi_client_info'])) {
            $ci = $_SESSION['sdpi_client_info'];
            $session_id = $session->start_session(
                isset($ci['name']) ? $ci['name'] : '',
                isset($ci['email']) ? $ci['email'] : '',
                isset($ci['phone']) ? $ci['phone'] : ''
            );
        }
        return $session_id ? $session_id : $session->get_session_id();
    }

    public static function ensure_documentation_directory() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return;
        }

        $base_dir = trailingslashit($uploads['basedir']) . self::$documentation_upload_subdir;
        if (!wp_mkdir_p($base_dir)) {
            return;
        }

        $index_file = trailingslashit($base_dir) . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $htaccess_file = trailingslashit($base_dir) . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $rules = "Options -Indexes\n<FilesMatch \"\\.(php|php[0-9]?|phtml)$\">\n    Require all denied\n</FilesMatch>\n";
            file_put_contents($htaccess_file, $rules);
        }
    }

    public function filter_documentation_upload_dir($dirs) {
        $subdir = '/' . ltrim(self::$documentation_upload_subdir, '/');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url'] = $dirs['baseurl'] . $subdir;
        return $dirs;
    }

    private function normalize_documentation_files($files) {
        $normalized = array();
        if (!is_array($files)) {
            return $normalized;
        }

        $allowed_mimes = array_values($this->documentation_allowed_mimes);

        foreach ($files as $key => $entry) {
            $attachment_id = 0;
            $entry_data = array();

            if (is_array($entry) && isset($entry['id'])) {
                $attachment_id = absint($entry['id']);
                $entry_data = $entry;
            } elseif (is_numeric($key)) {
                $attachment_id = absint($key);
                if (is_array($entry)) {
                    $entry_data = $entry;
                }
            }

            if (!$attachment_id) {
                continue;
            }

            $attachment = get_post($attachment_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                continue;
            }

            $mime_type = get_post_mime_type($attachment_id);
            if ($mime_type && !in_array($mime_type, $allowed_mimes, true)) {
                $mime_type = isset($entry_data['type']) ? sanitize_text_field($entry_data['type']) : $mime_type;
                if (!in_array($mime_type, $allowed_mimes, true)) {
                    continue;
                }
            }

            $url = wp_get_attachment_url($attachment_id);
            if (empty($url)) {
                continue;
            }

            $size = isset($entry_data['size']) ? intval($entry_data['size']) : 0;
            if (!$size) {
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $size = (int) filesize($file_path);
                }
            }

            $uploaded_at = isset($entry_data['uploaded_at']) ? sanitize_text_field($entry_data['uploaded_at']) : '';
            if (empty($uploaded_at)) {
                $uploaded_at = get_post_meta($attachment_id, '_sdpi_document_uploaded_at', true);
            }
            if (empty($uploaded_at)) {
                $uploaded_at = current_time('mysql');
            }

            $name = isset($entry_data['name']) ? sanitize_file_name($entry_data['name']) : basename($url);

            $normalized[$attachment_id] = array(
                'id' => $attachment_id,
                'url' => esc_url_raw($url),
                'name' => $name,
                'type' => sanitize_text_field($mime_type),
                'size' => $size,
                'uploaded_at' => $uploaded_at
            );
        }

        if (!empty($normalized)) {
            uasort($normalized, function($a, $b) {
                if ($a['uploaded_at'] === $b['uploaded_at']) {
                    return $a['id'] <=> $b['id'];
                }
                return strcmp($a['uploaded_at'], $b['uploaded_at']);
            });
            if (count($normalized) > $this->documentation_max_files) {
                $normalized = array_slice($normalized, 0, $this->documentation_max_files, true);
            }
        }

        return $normalized;
    }

    private function documentation_files_to_list($files_map) {
        if (empty($files_map) || !is_array($files_map)) {
            return array();
        }
        return array_values($files_map);
    }

    private function merge_documentation_files($sources) {
        $merged = array();
        if (!is_array($sources)) {
            $sources = array($sources);
        }

        foreach ($sources as $source) {
            if (empty($source)) {
                continue;
            }
            $normalized = $this->normalize_documentation_files($source);
            if (empty($normalized)) {
                continue;
            }
            foreach ($normalized as $attachment_id => $item) {
                $merged[$attachment_id] = $item;
            }
        }

        if (!empty($merged)) {
            uasort($merged, function($a, $b) {
                if ($a['uploaded_at'] === $b['uploaded_at']) {
                    return $a['id'] <=> $b['id'];
                }
                return strcmp($a['uploaded_at'], $b['uploaded_at']);
            });
            if (count($merged) > $this->documentation_max_files) {
                $merged = array_slice($merged, 0, $this->documentation_max_files, true);
            }
        }

        return $merged;
    }

    /**
     * Dispatch an automatic Zapier update respecting the 10 minute session window
     */
    private function dispatch_zapier_update($session_id, $quote_data, $extra_data = array(), $close_session = false) {
        if (empty($session_id) || empty($quote_data) || !is_array($quote_data) || !isset($quote_data['final_price'])) {
            return;
        }

        $session = new SDPI_Session();
        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();

        $extra_data = is_array($extra_data) ? $extra_data : array();

        $documentation_map = $this->merge_documentation_files(array(
            $session_data['documentation_files'] ?? array(),
            $quote_data['documentation_files'] ?? array(),
            $extra_data['documentation_files'] ?? array()
        ));
        $documentation_list = $this->documentation_files_to_list($documentation_map);

        $session->update_data($session_id, array(
            'documentation_files' => $documentation_map,
            'quote' => array('documentation_files' => $documentation_list)
        ));

        $extra_data['documentation_files'] = $documentation_list;

        $tracking = array();
        if (isset($session_data['zapier_tracking']) && is_array($session_data['zapier_tracking'])) {
            $tracking = $session_data['zapier_tracking'];
        }

        if (!empty($tracking['closed'])) {
            return;
        }

        $now = current_time('timestamp');
        $window = (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60) * 10;
        $first_sent = isset($tracking['first_sent_at']) ? intval($tracking['first_sent_at']) : 0;
        $expires_at = isset($tracking['expires_at']) ? intval($tracking['expires_at']) : ($first_sent ? ($first_sent + $window) : 0);

        if ($first_sent && $now > ($expires_at ?: ($first_sent + $window))) {
            $tracking['closed'] = true;
            $tracking['closed_reason'] = 'expired';
            $tracking['closed_at'] = $now;
            $session->update_data($session_id, array('zapier_tracking' => $tracking));
            return;
        }

        if (!$first_sent) {
            $tracking['first_sent_at'] = $now;
            $tracking['expires_at'] = $now + $window;
        }

        $tracking['last_sent_at'] = $now;

        if ($close_session) {
            $tracking['closed'] = true;
            $tracking['closed_reason'] = 'payment';
            $tracking['closed_at'] = $now;
        }

        $session->update_data($session_id, array('zapier_tracking' => $tracking));

        $payload_extra = is_array($extra_data) ? $extra_data : array();

        if (empty($payload_extra['client']) && isset($session_data['client_info'])) {
            $payload_extra['client'] = $session_data['client_info'];
        }

        if (empty($payload_extra['shipping']) && isset($session_data['shipping'])) {
            $payload_extra['shipping'] = $session_data['shipping'];
        }

        if (empty($payload_extra['maritime_details'])) {
            if (isset($session_data['maritime']) && is_array($session_data['maritime'])) {
                $payload_extra['maritime_details'] = $session_data['maritime'];
            } elseif (isset($quote_data['maritime_details'])) {
                $payload_extra['maritime_details'] = $quote_data['maritime_details'];
            }
        }

        if (empty($payload_extra['transport_type'])) {
            if (isset($session_data['transport_type'])) {
                $payload_extra['transport_type'] = $session_data['transport_type'];
            } elseif (!empty($quote_data['transport_type'])) {
                $payload_extra['transport_type'] = $quote_data['transport_type'];
            } elseif (!empty($quote_data['maritime_involved'])) {
                $payload_extra['transport_type'] = 'maritime';
            } else {
                $payload_extra['transport_type'] = 'terrestrial';
            }
        }

        if (empty($payload_extra['payment']) && isset($session_data['payment'])) {
            $payload_extra['payment'] = $session_data['payment'];
        }

        $payload_extra['session_id'] = $session_id;

        $this->send_to_zapier(
            $quote_data['pickup_zip'] ?? '',
            $quote_data['delivery_zip'] ?? '',
            $quote_data['trailer_type'] ?? '',
            $quote_data['vehicle_type'] ?? '',
            !empty($quote_data['vehicle_inoperable']),
            !empty($quote_data['vehicle_electric']),
            $quote_data['vehicle_make'] ?? '',
            $quote_data['vehicle_model'] ?? '',
            $quote_data['vehicle_year'] ?? '',
            $quote_data,
            !empty($quote_data['maritime_involved']),
            $payload_extra
        );

        $history = new SDPI_History();
        if (method_exists($history, 'mark_zapier_status')) {
            $history->mark_zapier_status($session_id, 'sent');
        }
    }

    /**
     * Render the pricing form
     */
    public function render_form() {
        // Enqueue scripts and styles when form is rendered
        $this->enqueue_styles();
        $this->enqueue_scripts();

        // MODIFICADO: Ya no verificamos datos de contacto al inicio
        // El formulario de cotizacion se muestra directamente

        $icons_base_url = plugin_dir_url(__FILE__) . '../assets/icons/';
        $summary_icons = array(
            'location'           => $icons_base_url . 'Location.webp',
            'inland'             => $icons_base_url . 'Inland.webp',
            'car'                => $icons_base_url . 'Car.webp',
            'inoperable'         => $icons_base_url . 'tda-inoperable.webp',
            'electric'           => $icons_base_url . 'tda-ev.webp',
            'transport-type'     => $icons_base_url . 'tipo-de-transporte.webp',
            'maritime-total'     => $icons_base_url . 'tda-maritimo.webp',
            'terrestrial-total'  => $icons_base_url . 'Car.webp',
        );

        $this->summary_icons = $summary_icons;

        ob_start();
        ?>
        <div class="sdpi-pricing-form sdpi-screen-wrapper">
            <div class="sdpi-progress-bar" id="sdpi-progress-bar">
                <div class="sdpi-progress-step active" data-step="1">
                    <span class="sdpi-progress-number">1</span>
                    <span class="sdpi-progress-label">Datos de envio</span>
                </div>
                <div class="sdpi-progress-step" data-step="2">
                    <span class="sdpi-progress-number">2</span>
                    <span class="sdpi-progress-label">Datos del vehiculo</span>
                </div>
                <div class="sdpi-progress-step" data-step="3">
                    <span class="sdpi-progress-number">3</span>
                    <span class="sdpi-progress-label">Contacto</span>
                </div>
                <div class="sdpi-progress-step" data-step="4">
                    <span class="sdpi-progress-number">4</span>
                    <span class="sdpi-progress-label">Resumen y Pago</span>
                </div>
            </div>
            <div class="sdpi-form-layout">
                <form method="post" class="sdpi-form" id="sdpi-pricing-form">
                <input type="hidden" name="sdpi_form_submit" value="1">
                <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_form_nonce'); ?>">
                
                <div class="sdpi-form-card sdpi-form-section">
                    <h3>Datos de envio</h3>
                    <p class="sdpi-section-subtitle">Ingresa las ciudades de origen y destino para comenzar tu cotizacion.</p>

                    <div class="sdpi-form-group">
                        <label for="sdpi_pickup_city">Ciudad de origen *</label>
                        <div class="sdpi-search-container">
                            <input type="text" id="sdpi_pickup_city" name="pickup_city"
                                   placeholder="Escribe ciudad o codigo postal..." required autocomplete="off">
                            <div class="sdpi-search-results" id="pickup-results"></div>
                        </div>
                        <input type="hidden" id="sdpi_pickup_zip" name="pickup_zip">
                        <small class="sdpi-field-help">Escribe para buscar ciudades o codigos postales.</small>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_delivery_city">Ciudad de destino *</label>
                        <div class="sdpi-search-container">
                            <input type="text" id="sdpi_delivery_city" name="delivery_city"
                                   placeholder="Escribe ciudad o codigo postal..." required autocomplete="off">
                            <div class="sdpi-search-results" id="delivery-results"></div>
                        </div>
                        <input type="hidden" id="sdpi_delivery_zip" name="delivery_zip">
                        <small class="sdpi-field-help">Escribe para buscar ciudades o codigos postales.</small>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_trailer_type">Tipo de trailer *</label>
                        <select id="sdpi_trailer_type" name="trailer_type" required>
                            <option value="">Selecciona una opcion</option>
                            <option value="open">Abierto</option>
                            <option value="enclosed">Cerrado</option>
                        </select>
                    </div>
                </div>

                <div class="sdpi-form-card sdpi-form-section">
                    <h3>Datos del vehiculo</h3>
                    <p class="sdpi-section-subtitle">Comparte los detalles principales del vehiculo a transportar.</p>
                    
                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_type">Tipo de vehiculo *</label>
                        <select id="sdpi_vehicle_type" name="vehicle_type" required>
                            <option value="">Selecciona una opcion</option>
                            <option value="sedan">Sedan</option>
                            <option value="suv">SUV</option>
                            <option value="van">Van</option>
                            <option value="coupe_2_doors">Coupe 2 puertas</option>
                            <option value="pickup_2_doors">Pickup 2 puertas</option>
                            <option value="pickup_4_doors">Pickup 4 puertas</option>
                        </select>
                    </div>
                    
                    <div class="sdpi-form-group sdpi-option-group">
                        <label class="sdpi-checkbox">
                            <input type="checkbox" id="sdpi_vehicle_inoperable" name="vehicle_inoperable" value="1">
                            El vehiculo no funciona
                        </label>
                        <small class="sdpi-field-help">Se agregarán $500 USD adicionales al precio final si tu terrestre + marítimo es para un vehículo inoperable.</small>
                    </div>

                    <div class="sdpi-form-group sdpi-option-group">
                        <label class="sdpi-checkbox">
                            <input type="checkbox" id="sdpi_vehicle_electric" name="vehicle_electric" value="1">
                            Vehiculo electrico
                        </label>
                        <small class="sdpi-field-help">Se agregarán $600 USD adicionales al precio final si tu terrestre + marítimo es para un vehículo eléctrico.</small>
                    </div>
                    
                    <div class="sdpi-form-grid">
                        <div class="sdpi-form-group">
                            <label for="sdpi_vehicle_make">Marca del vehiculo</label>
                            <input type="text" id="sdpi_vehicle_make" name="vehicle_make" placeholder="Ej: Toyota, Ford, Chevrolet">
                        </div>
                        <div class="sdpi-form-group">
                            <label for="sdpi_vehicle_model">Modelo del vehiculo</label>
                            <input type="text" id="sdpi_vehicle_model" name="vehicle_model" placeholder="Ej: Corolla, F-150, Silverado">
                        </div>
                        <div class="sdpi-form-group">
                            <label for="sdpi_vehicle_year">Año del vehiculo</label>
                            <input type="number" id="sdpi_vehicle_year" name="vehicle_year" min="1900" max="<?php echo date('Y'); ?>" placeholder="Ej: 2020">
                        </div>
                    </div>
                </div>

                <div class="sdpi-form-submit">
                    <button type="submit" class="sdpi-submit-btn" id="sdpi-submit-btn">Obtener cotización</button>
                    <button type="button" class="sdpi-pay-btn" id="sdpi-inline-continue-btn" style="display:none;">Pagar en línea</button>
                </div>
            </form>

            <?php
            $this->render_summary_panel(array(
                'panel_id' => 'sdpi-summary-panel',
                'title' => 'Resumen de tu cotizacion',
                'subtitle' => 'Actualizamos esta seccion mientras avanzas en el formulario.',
                'value_prefix' => 'sdpi-summary',
                'total_label' => 'Precio estimado',
                'total_value_id' => 'sdpi-summary-price',
                'footer_id' => 'sdpi-summary-footer-text',
                'footer_text' => 'Completa el formulario para calcular tu cotizacion y continuar.',
                'transport_row_hidden' => true,
                'actions' => array(
                    'wrapper_class' => 'sdpi-summary-actions',
                    'wrapper_id' => 'sdpi-summary-actions',
                    'buttons' => array(
                        array(
                            'id' => 'sdpi-summary-continue-btn',
                            'class' => 'sdpi-pay-btn',
                            'label' => 'Pagar en línea',
                            'type' => 'button',
                        ),
                    ),
                ),
            ));
            ?>
            </div>

            <!-- Loading indicator -->
            <div id="sdpi-loading" class="sdpi-loading" style="display: none;">
                <p>Obteniendo cotizacion...</p>
            </div>

            <!-- Debug info (only for admins) -->
            <?php if (current_user_can('manage_options') && WP_DEBUG): ?>
            <div id="sdpi-debug" class="sdpi-debug" style="display: none;">
                <h4>Debug Info (Admin Only)</h4>
                <pre id="sdpi-debug-content"></pre>
            </div>
            <?php endif; ?>
        </div>
        <?php $this->render_additional_info_screen(); ?>
        
        <script>
        jQuery(document).ready(function($) {
            
            // City search functionality
            var searchTimeout;
            var currentSearchField = null;
            
            // Initialize city search for both fields
            $('#sdpi_pickup_city, #sdpi_delivery_city').on('input', function() {
                var field = $(this);
                var fieldId = field.attr('id');
                var query = field.val().trim();
                
                
                // Ensure search container has proper positioning
                var searchContainer = field.closest('.sdpi-search-container');
                searchContainer.css({
                    'position': 'relative',
                    'width': '100%'
                });
                
                // Clear previous timeout
                clearTimeout(searchTimeout);
                
                // Hide results if query is too short
                // For ZIP codes, allow 3+ digits, for cities allow 2+ characters
                var isNumeric = /^\d+$/.test(query);
                var minLength = isNumeric ? 3 : 2;
                
                if (query.length < minLength) {
                    $('#' + fieldId.replace('_city', '-results')).empty().hide();
                    return;
                }
                
                // Set current search field
                currentSearchField = fieldId;
                
                // Show loading
                var resultsId = fieldId.replace('_city', '-results');
                var resultsContainer = $('#' + resultsId);
                
                if (resultsContainer.length === 0) {
                    var field = $('#' + fieldId);
                    var searchContainer = field.closest('.sdpi-search-container');
                    if (searchContainer.length > 0) {
                        searchContainer.append('<div class="sdpi-search-results" id="' + resultsId + '"></div>');
                        resultsContainer = $('#' + resultsId);
                    } else {
                        return;
                    }
                }
                
                // Force styles inline for loading state
                resultsContainer.css({
                    'position': 'absolute',
                    'top': '100%',
                    'left': '0',
                    'right': '0',
                    'background': '#fff',
                    'border': '2px solid #e1e5e9',
                    'border-top': 'none',
                    'border-radius': '0 0 8px 8px',
                    'box-shadow': '0 8px 25px rgba(0, 0, 0, 0.15)',
                    'z-index': '1000',
                    'display': 'block'
                });
                
                resultsContainer.html('<div class="sdpi-search-loading" style="padding: 12px 16px; color: #6c757d; font-style: italic; text-align: center; font-size: 0.95rem;">Buscando ciudades...</div>');
                
                // Debounce search
                searchTimeout = setTimeout(function() {
                    searchCities(query, fieldId);
                }, 300);
            });
            
            // Handle city selection
            $(document).on('click', '.sdpi-city-option', function() {
                var cityData = $(this).data('city');
                var resultsId = $(this).closest('.sdpi-search-results').attr('id');
                var fieldId = resultsId.replace('-results', '_city');
                
                
                // Set the selected city
                $('#' + fieldId).val(cityData.text);
                $('#' + fieldId.replace('_city', '_zip')).val(cityData.zips ? cityData.zips.split(' ')[0] : '');
                
                // Hide results
                $('#' + resultsId).empty().hide();
            });
            
            // Hide results when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.sdpi-search-container').length) {
                    $('.sdpi-search-results').empty().hide();
                }
            });
            
            // Search cities function
            function searchCities(query, fieldId) {
                
                if (typeof sdpi_ajax === 'undefined') {
                    // Mock search results
                    var cities = [
                        { text: 'Miami, FL', zips: '33101 33102 33103' },
                        { text: 'Miami Beach, FL', zips: '33139 33140' },
                        { text: 'Miami Gardens, FL', zips: '33014 33169' },
                        { text: 'Miami Lakes, FL', zips: '33014 33016' }
                    ];
                    
                    setTimeout(function() {
                        displayCityResults(cities, fieldId);
                    }, 500);
                    return;
                }
                
                $.ajax({
                    url: sdpi_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'sdpi_search_cities',
                        nonce: sdpi_ajax.nonce,
                        query: query,
                        limit: 10
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.data.length > 0) {
                            displayCityResults(response.data, fieldId);
                        } else {
                            displayNoResults(fieldId);
                        }
                    },
                    error: function(xhr, status, error) {
                        displayNoResults(fieldId);
                    }
                });
            }
            
            // Display city results
            function displayCityResults(cities, fieldId) {
                var resultsId = fieldId.replace('_city', '-results');
                var resultsContainer = $('#' + resultsId);
                
                if (resultsContainer.length === 0) {
                    
                    // Try to create the container dynamically
                    var field = $('#' + fieldId);
                    var searchContainer = field.closest('.sdpi-search-container');
                    if (searchContainer.length > 0) {
                        searchContainer.append('<div class="sdpi-search-results" id="' + resultsId + '"></div>');
                        resultsContainer = $('#' + resultsId);
                    } else {
                        return;
                    }
                }
                
                // Force styles inline to ensure visibility
                resultsContainer.css({
                    'position': 'absolute',
                    'top': '100%',
                    'left': '0',
                    'right': '0',
                    'background': '#fff',
                    'border': '2px solid #e1e5e9',
                    'border-top': 'none',
                    'border-radius': '0 0 8px 8px',
                    'box-shadow': '0 8px 25px rgba(0, 0, 0, 0.15)',
                    'max-height': '200px',
                    'overflow-y': 'auto',
                    'z-index': '1000',
                    'display': 'block'
                });
                
                var html = '';
                cities.forEach(function(city) {
                    var isZipSearch = city.search_type === 'zip';
                    var optionClass = isZipSearch ? 'sdpi-city-option sdpi-zip-option' : 'sdpi-city-option';
                    
                    html += '<div class="' + optionClass + '" data-city=\'' + JSON.stringify(city) + '\' style="padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f8f9fa; transition: all 0.2s ease; font-size: 0.95rem; color: #34495e;">';
                    html += '<span class="city-name" style="font-weight: 500; color: inherit;">' + city.text + '</span>';
                    if (isZipSearch) {
                        html += '<span class="zip-indicator" style="float: right; font-size: 0.8rem; color: #6c757d; background: #e9ecef; padding: 2px 6px; border-radius: 3px;">ZIP</span>';
                    }
                    html += '</div>';
                });
                
                resultsContainer.html(html);
            }
            
            // Display no results message
            function displayNoResults(fieldId) {
                var resultsId = fieldId.replace('_city', '-results');
                var resultsContainer = $('#' + resultsId);
                
                if (resultsContainer.length === 0) {
                    var field = $('#' + fieldId);
                    var searchContainer = field.closest('.sdpi-search-container');
                    if (searchContainer.length > 0) {
                        searchContainer.append('<div class="sdpi-search-results" id="' + resultsId + '"></div>');
                        resultsContainer = $('#' + resultsId);
                    } else {
                        return;
                    }
                }
                
                // Force styles inline for no results state
                resultsContainer.css({
                    'position': 'absolute',
                    'top': '100%',
                    'left': '0',
                    'right': '0',
                    'background': '#fff',
                    'border': '2px solid #e1e5e9',
                    'border-top': 'none',
                    'border-radius': '0 0 8px 8px',
                    'box-shadow': '0 8px 25px rgba(0, 0, 0, 0.15)',
                    'z-index': '1000',
                    'display': 'block'
                });
                
                resultsContainer.html('<div class="sdpi-no-results" style="padding: 12px 16px; color: #6c757d; font-style: italic; text-align: center; font-size: 0.95rem;">No se encontraron ciudades</div>');
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a reusable summary panel to keep layout consistent across screens.
     *
     * @param array $args Configuration arguments for the panel.
     */
    private function render_summary_panel(array $args = array()) {
        $defaults = array(
            'panel_id' => 'sdpi-summary-panel',
            'extra_classes' => '',
            'title' => 'Resumen de tu cotizacion',
            'subtitle' => '',
            'value_prefix' => 'sdpi-summary',
            'total_label' => 'Precio estimado',
            'total_value_id' => '',
            'footer_id' => '',
            'footer_text' => '',
            'footer_state_class' => '',
            'transport_row_hidden' => false,
            'transport_row_id' => '',
            'actions' => array(),
            'initially_hidden' => false,
        );

        $args = wp_parse_args($args, $defaults);

        $value_prefix = $args['value_prefix'];
        if (empty($args['total_value_id'])) {
            $args['total_value_id'] = $value_prefix . '-price';
        }
        if (empty($args['footer_id'])) {
            $args['footer_id'] = $value_prefix . '-footer-text';
        }

        $transport_row_id = !empty($args['transport_row_id'])
            ? $args['transport_row_id']
            : $value_prefix . '-transport-type-row';

        $panel_classes = trim('sdpi-summary-panel ' . $args['extra_classes']);
        $panel_style = $args['initially_hidden'] ? ' style="display:none;"' : '';

        $footer_class = 'sdpi-summary-footer';
        if (!empty($args['footer_state_class'])) {
            $footer_class .= ' ' . trim($args['footer_state_class']);
        }

        ob_start();
        ?>
        <aside class="<?php echo esc_attr($panel_classes); ?>" id="<?php echo esc_attr($args['panel_id']); ?>"<?php echo $panel_style; ?>>
            <div class="sdpi-summary-header">
                <h3><?php echo esc_html($args['title']); ?></h3>
                <?php if (!empty($args['subtitle'])) : ?>
                    <p><?php echo esc_html($args['subtitle']); ?></p>
                <?php endif; ?>
            </div>
            <div class="sdpi-summary-body">
                <?php
                $this->render_summary_item('location', 'Ciudad de origen', $value_prefix . '-pickup');
                $this->render_summary_item('location', 'Ciudad de destino', $value_prefix . '-delivery');
                $this->render_summary_item('inland', 'Tipo de trailer', $value_prefix . '-trailer');
                $this->render_summary_item('car', 'Vehículo', $value_prefix . '-vehicle');
                $this->render_summary_item('inoperable', 'Vehículo inoperable', $value_prefix . '-inoperable');
                $this->render_summary_item('electric', 'Vehículo eléctrico', $value_prefix . '-electric');
                $this->render_summary_item('transport-type', 'Tipo de transporte', $value_prefix . '-transport-type', $transport_row_id, $args['transport_row_hidden']);
                ?>
            </div>
            <div class="sdpi-summary-subtotals">
                <?php
                $this->render_summary_item(
                    'maritime-total',
                    'Total Transporte Marítimo',
                    $value_prefix . '-maritime-total',
                    '',
                    false,
                    array('sdpi-summary-subtotal', 'sdpi-hidden')
                );
                $this->render_summary_item(
                    'terrestrial-total',
                    'Total Transporte Terrestre',
                    $value_prefix . '-terrestrial-total',
                    '',
                    false,
                    array('sdpi-summary-subtotal', 'sdpi-hidden')
                );
                ?>
            </div>
            <div class="sdpi-summary-total pending">
                <span class="sdpi-summary-total-label"><?php echo esc_html($args['total_label']); ?></span>
                <span class="sdpi-summary-total-value" id="<?php echo esc_attr($args['total_value_id']); ?>">Pendiente</span>
            </div>
            <?php if (!empty($args['actions']) && !empty($args['actions']['buttons'])) :
                $actions = $args['actions'];
                $wrapper_class = !empty($actions['wrapper_class']) ? $actions['wrapper_class'] : 'sdpi-summary-actions';
                $wrapper_id = !empty($actions['wrapper_id']) ? $actions['wrapper_id'] : '';
                $wrapper_attributes = ' class="' . esc_attr(trim($wrapper_class)) . '"';
                if (!empty($wrapper_id)) {
                    $wrapper_attributes .= ' id="' . esc_attr($wrapper_id) . '"';
                }
                ?>
                <div<?php echo $wrapper_attributes; ?>>
                    <?php foreach ($actions['buttons'] as $button) :
                        if (empty($button['label'])) {
                            continue;
                        }
                        $button_type = !empty($button['type']) ? $button['type'] : 'button';
                        $button_class = !empty($button['class']) ? trim($button['class']) : '';
                        $button_id = !empty($button['id']) ? $button['id'] : '';
                        $button_attributes = '';
                        if (!empty($button_id)) {
                            $button_attributes .= ' id="' . esc_attr($button_id) . '"';
                        }
                        if (!empty($button_class)) {
                            $button_attributes .= ' class="' . esc_attr($button_class) . '"';
                        }
                        ?>
                        <button type="<?php echo esc_attr($button_type); ?>"<?php echo $button_attributes; ?>><?php echo esc_html($button['label']); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="<?php echo esc_attr(trim($footer_class)); ?>">
                <p id="<?php echo esc_attr($args['footer_id']); ?>"><?php echo esc_html($args['footer_text']); ?></p>
            </div>
        </aside>
        <?php
        echo ob_get_clean();
    }

    /**
     * Render a single summary item with icon and placeholder value.
     *
     * @param string $icon_key  Key referencing the icon in the summary icon map.
     * @param string $label     Label to display for the summary row.
     * @param string $value_id  DOM id for the value span.
     * @param string $wrapper_id Optional DOM id for the wrapper row.
     * @param bool   $hidden    Whether the row should be hidden initially.
     */
    private function render_summary_item($icon_key, $label, $value_id, $wrapper_id = '', $hidden = false, $extra_classes = array()) {
        $icon_url = isset($this->summary_icons[$icon_key]) ? $this->summary_icons[$icon_key] : '';
        $attributes = '';
        $classes = array('sdpi-summary-item', 'pending');

        if (!empty($extra_classes)) {
            if (!is_array($extra_classes)) {
                $extra_classes = array($extra_classes);
            }
            foreach ($extra_classes as $class) {
                if (!empty($class)) {
                    $classes[] = $class;
                }
            }
        }

        $class_attribute = ' class="' . esc_attr(implode(' ', array_unique($classes))) . '"';

        if (!empty($wrapper_id)) {
            $attributes .= ' id="' . esc_attr($wrapper_id) . '"';
        }

        if ($hidden) {
            $attributes .= ' style="display:none;"';
        }
        ?>
        <div<?php echo $class_attribute . $attributes; ?>>
            <span class="sdpi-summary-icon" aria-hidden="true">
                <?php if (!empty($icon_url)) : ?>
                    <img src="<?php echo esc_url($icon_url); ?>" alt="" role="presentation">
                <?php endif; ?>
            </span>
            <div class="sdpi-summary-content">
                <span class="sdpi-summary-label"><?php echo esc_html($label); ?></span>
                <span class="sdpi-summary-value" id="<?php echo esc_attr($value_id); ?>">Pendiente</span>
            </div>
        </div>
        <?php
    }

    /**
     * Render a secondary screen to capture additional shipping info before checkout
     * This markup will be toggled from JS when user clicks Pay
     */
    private function render_additional_info_screen() {
        ?>
        <div id="sdpi-additional-info" class="sdpi-screen-wrapper sdpi-review-screen" style="display:none;">
            <div class="sdpi-form-layout">
                <div class="sdpi-review-main">
                    <div class="sdpi-form-card sdpi-review-intro">
                        <h3>Informacion adicional para el envio</h3>
                        <p>Revisa los datos de la cotizacion y completa la informacion de recogida y entrega antes de continuar al pago.</p>
                        <ul class="sdpi-review-checklist">
                            <li>Confirma que los datos del vehiculo coinciden con tu cotizacion.</li>
                            <li>Completa los campos obligatorios de recogida y entrega.</li>
                            <li>Guarda los cambios para generar el enlace de pago.</li>
                        </ul>
                    </div>

                    <form id="sdpi-additional-info-form" class="sdpi-review-form">
                        <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">
                        <input type="hidden" id="sdpi_transport_type" name="transport_type" value="terrestrial">

                        <div class="sdpi-form-card sdpi-review-card">
                            <h3>Datos del vehiculo</h3>
                            <div class="sdpi-form-grid sdpi-grid-3">
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_vehicle_year">Anio</label>
                                    <input type="number" id="sdpi_ai_vehicle_year" disabled>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_vehicle_make">Marca</label>
                                    <input type="text" id="sdpi_ai_vehicle_make" disabled>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_vehicle_model">Modelo</label>
                                    <input type="text" id="sdpi_ai_vehicle_model" disabled>
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card">
                            <h3>Informacion de recogida</h3>
                            <div class="sdpi-form-grid">
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_p_name">Nombre de quien entrega *</label>
                                    <input type="text" id="sdpi_ai_p_name" required>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_p_street">Direccion de recogida *</label>
                                    <input type="text" id="sdpi_ai_p_street" required>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_p_city">Ciudad de recogida *</label>
                                    <input type="text" id="sdpi_ai_p_city" required readonly>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_p_zip">ZIP de recogida *</label>
                                    <input type="text" id="sdpi_ai_p_zip" required pattern="^\\d{5}$" maxlength="5" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card">
                            <h3>Informacion de entrega</h3>
                            <div class="sdpi-form-grid">
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_d_name">Nombre de quien recibe *</label>
                                    <input type="text" id="sdpi_ai_d_name" required>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_d_street">Direccion de entrega *</label>
                                    <input type="text" id="sdpi_ai_d_street" required>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_d_city">Ciudad de entrega *</label>
                                    <input type="text" id="sdpi_ai_d_city" required readonly>
                                </div>
                                <div class="sdpi-form-group">
                                    <label for="sdpi_ai_d_zip">ZIP de entrega *</label>
                                    <input type="text" id="sdpi_ai_d_zip" required pattern="^\\d{5}$" maxlength="5" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card">
                            <h3>Tipo de recogida</h3>
                            <div class="sdpi-form-group">
                                <label for="sdpi_ai_pickup_type">Seleccione una opcion *</label>
                                <select id="sdpi_ai_pickup_type" required>
                                    <option value="">Seleccione...</option>
                                    <option value="Subasta">Subasta</option>
                                    <option value="Residencia">Residencia</option>
                                    <option value="Dealer o negocio">Dealer o negocio</option>
                                </select>
                            </div>
                        </div>

                        <div class="sdpi-form-actions">
                            <button type="button" class="sdpi-clear-btn" id="sdpi-ai-cancel">Volver</button>
                            <button type="button" class="sdpi-pay-btn" id="sdpi-ai-continue">Proceder al Checkout</button>
                        </div>
                    </form>

                    <form id="sdpi-maritime-info-form" class="sdpi-review-form sdpi-maritime-form" style="display:none;">
                        <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">
                        <input type="hidden" id="sdpi_maritime_direction" name="maritime_direction" value="">

                        <div class="sdpi-form-card sdpi-review-card sdpi-maritime-vehicle-section">
                            <h3>Vehicle Information</h3>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_vehicle_year">Year</label>
                                    <input type="number" id="sdpi_m_vehicle_year" readonly>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_vehicle_make">Make</label>
                                    <input type="text" id="sdpi_m_vehicle_make" readonly>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_vehicle_model">Model</label>
                                    <input type="text" id="sdpi_m_vehicle_model" readonly>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_vehicle_type">Car Type</label>
                                    <input type="text" id="sdpi_m_vehicle_type" readonly>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_vehicle_conditions">Car Conditions *</label>
                                    <select id="sdpi_m_vehicle_conditions" required disabled>
                                        <option value="">Select...</option>
                                        <option value="Running">Running</option>
                                        <option value="Non-Running">Non-Running</option>
                                    </select>
                                    <input type="hidden" id="sdpi_m_vehicle_conditions_value">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_fuel_type">Fuel Type *</label>
                                    <select id="sdpi_m_fuel_type" required disabled>
                                        <option value="">Select...</option>
                                        <option value="Gasoline">Gasoline</option>
                                        <option value="Diesel">Diesel</option>
                                        <option value="Electric">Electric</option>
                                        <option value="Hybrid">Hybrid</option>
                                    </select>
                                    <input type="hidden" id="sdpi_m_fuel_type_value">
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_unit_value">Unit Value *</label>
                                    <input type="number" id="sdpi_m_unit_value" placeholder="Vehicle value in USD" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_color">Color *</label>
                                    <input type="text" id="sdpi_m_color" placeholder="e.g., Red, Blue, Black" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_dimensions">Dimensions LxWxH *</label>
                                    <input type="text" id="sdpi_m_dimensions" placeholder="e.g., 15x6x5 ft">
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card sdpi-maritime-shipper-section">
                            <h3>Shipper Information</h3>
                            <small class="sdpi-field-help">Este formulario debe llenarse con la información del dueño del vehículo.</small>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_s_name">Shipper Name *</label>
                                    <input type="text" id="sdpi_s_name" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_s_street">Shipper Street *</label>
                                    <input type="text" id="sdpi_s_street" required>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_city">Shipper City *</label>
                                    <input type="text" id="sdpi_s_city" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_state">Shipper State *</label>
                                    <input type="text" id="sdpi_s_state" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_country">Shipper Country *</label>
                                    <select id="sdpi_s_country" required>
                                        <option value="">Seleccione...</option>
                                        <option value="USA">Estados Unidos</option>
                                        <option value="Puerto Rico">Puerto Rico</option>
                                    </select>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_zip">Shipper Zip *</label>
                                    <input type="text" id="sdpi_s_zip" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_phone1">Shipper Phone 1 *</label>
                                    <input type="tel" id="sdpi_s_phone1" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_s_phone2">Shipper Phone 2</label>
                                    <input type="tel" id="sdpi_s_phone2">
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card sdpi-maritime-consignee-section">
                            <h3>Consignee Information</h3>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_c_name">Consignee Name *</label>
                                    <input type="text" id="sdpi_c_name" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_c_street">Consignee Street *</label>
                                    <input type="text" id="sdpi_c_street" required>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_city">Consignee City *</label>
                                    <input type="text" id="sdpi_c_city" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_state">Consignee State *</label>
                                    <input type="text" id="sdpi_c_state" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_country">Consignee Country *</label>
                                    <select id="sdpi_c_country" required>
                                        <option value="">Seleccione...</option>
                                        <option value="USA">Estados Unidos</option>
                                        <option value="Puerto Rico">Puerto Rico</option>
                                    </select>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_zip">Consignee Zip *</label>
                                    <input type="text" id="sdpi_c_zip" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_phone1">Consignee Phone 1 *</label>
                                    <input type="tel" id="sdpi_c_phone1" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_c_phone2">Consignee Phone 2</label>
                                    <input type="tel" id="sdpi_c_phone2">
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card sdpi-maritime-pickup-section" id="sdpi-pickup-section" style="display:none;">
                            <h3>Pick Up Information</h3>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_p_name">P. Name *</label>
                                    <input type="text" id="sdpi_p_name">
                                </div>
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_p_street">P. Street *</label>
                                    <input type="text" id="sdpi_p_street">
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_city">P. City *</label>
                                    <input type="text" id="sdpi_p_city">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_state">P. State *</label>
                                    <input type="text" id="sdpi_p_state">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_country">P. Country *</label>
                                    <input type="text" id="sdpi_p_country" value="USA" readonly>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_zip_code">P. Zip Code *</label>
                                    <input type="text" id="sdpi_p_zip_code">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_phone1">P. Phone 1 *</label>
                                    <input type="tel" id="sdpi_p_phone1">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_p_phone2">P. Phone 2 (Optional)</label>
                                    <input type="tel" id="sdpi_p_phone2">
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card sdpi-maritime-dropoff-section" id="sdpi-dropoff-section" style="display:none;">
                            <h3>Drop Off Information</h3>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_d_name">D. Name *</label>
                                    <input type="text" id="sdpi_d_name">
                                </div>
                                <div class="sdpi-form-group sdpi-col-2">
                                    <label for="sdpi_d_street">D. Street *</label>
                                    <input type="text" id="sdpi_d_street">
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_city">D. City *</label>
                                    <input type="text" id="sdpi_d_city">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_state">D. State *</label>
                                    <input type="text" id="sdpi_d_state">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_country">D. Country *</label>
                                    <input type="text" id="sdpi_d_country" value="USA" readonly>
                                </div>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_zip_code">D. Zip Code *</label>
                                    <input type="text" id="sdpi_d_zip_code">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_phone1">D. Phone 1 *</label>
                                    <input type="tel" id="sdpi_d_phone1">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_d_phone2">D. Phone 2 (Optional)</label>
                                    <input type="tel" id="sdpi_d_phone2">
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-card sdpi-review-card">
                            <h3>Documentación</h3>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_title">Title</label>
                                    <input type="text" id="sdpi_m_title" placeholder="Optional">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_registration">Registration</label>
                                    <input type="text" id="sdpi_m_registration" placeholder="Optional">
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi_m_id">ID</label>
                                    <input type="text" id="sdpi_m_id" placeholder="Optional">
                                </div>
                            </div>
                            <div class="sdpi-form-row sdpi-documentation-upload-row">
                                <div class="sdpi-form-group sdpi-col-6">
                                    <label for="sdpi_documentation_input">Carga de documentos (hasta <?php echo intval($this->documentation_max_files); ?> archivos)</label>
                                    <p class="sdpi-field-helper">Formatos permitidos: JPG, JPEG, PNG, WEBP y PDF. L&iacute;mite 10&nbsp;MB por archivo.</p>
                                    <div class="sdpi-documentation-dropzone" id="sdpi-documentation-dropzone" data-max-files="<?php echo intval($this->documentation_max_files); ?>" data-max-size="<?php echo intval($this->documentation_max_size); ?>" aria-describedby="sdpi_documentation_helper">
                                        <button type="button" class="sdpi-upload-btn" id="sdpi-documentation-trigger">Seleccionar archivos</button>
                                        <span id="sdpi_documentation_helper" class="sdpi-upload-subtitle">o arrastra y su&eacute;ltalos aqu&iacute;</span>
                                    </div>
                                    <input type="file" id="sdpi_documentation_input" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple hidden>
                                    <div class="sdpi-documentation-feedback" id="sdpi-documentation-feedback" role="alert" aria-live="polite"></div>
                                    <ul class="sdpi-documentation-list" id="sdpi-documentation-list" aria-live="polite"></ul>
                                </div>
                            </div>
                        </div>

                        <div class="sdpi-form-actions">
                            <button type="button" class="sdpi-clear-btn" id="sdpi-maritime-cancel">Volver</button>
                            <button type="button" class="sdpi-pay-btn" id="sdpi-maritime-continue">Proceder al Checkout</button>
                        </div>
                    </form>
                </div>

                <?php
                $this->render_summary_panel(array(
                    'panel_id' => 'sdpi-review-summary-panel',
                    'extra_classes' => 'sdpi-review-summary-panel',
                    'title' => 'Resumen de tu cotizacion',
                    'subtitle' => 'Verifica los datos antes de continuar.',
                    'value_prefix' => 'sdpi-review-summary',
                    'total_label' => 'Precio final',
                    'total_value_id' => 'sdpi-review-summary-price',
                    'footer_id' => 'sdpi-review-summary-footer-text',
                    'footer_text' => 'Confirma tus datos y completa la informacion solicitada.',
                    'footer_state_class' => 'is-info',
                    'initially_hidden' => true,
                ));
                ?>
            </div>
        </div>

        <div id="sdpi-payment-screen" class="sdpi-screen-wrapper sdpi-review-screen" style="display:none;">
            <div class="sdpi-form-layout">
                <div class="sdpi-review-main">
                    <div class="sdpi-form-card sdpi-review-card sdpi-payment-card" id="sdpi-payment-panel">
                        <h3>Pago con tarjeta</h3>
                        <p class="sdpi-payment-amount">Total a pagar: <span id="sdpi-payment-amount-display">--</span></p>
                        <div class="sdpi-form-actions sdpi-payment-actions">
                            <button type="button" class="sdpi-clear-btn" id="sdpi-payment-back-btn">Editar información</button>
                        </div>
                        <form id="sdpi-payment-form" autocomplete="off">
                            <div class="sdpi-form-group">
                                <label for="sdpi-card-number">Número de tarjeta</label>
                                <input type="tel" id="sdpi-card-number" inputmode="numeric" maxlength="25" placeholder="1234 5678 9012 3456" required>
                            </div>
                            <div class="sdpi-form-row">
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi-card-exp-month">Mes (MM)</label>
                                    <input type="tel" id="sdpi-card-exp-month" inputmode="numeric" maxlength="2" placeholder="MM" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi-card-exp-year">Año (YYYY)</label>
                                    <input type="tel" id="sdpi-card-exp-year" inputmode="numeric" maxlength="4" placeholder="YYYY" required>
                                </div>
                                <div class="sdpi-form-group sdpi-col-3">
                                    <label for="sdpi-card-cvv">CVV</label>
                                    <input type="tel" id="sdpi-card-cvv" inputmode="numeric" maxlength="4" placeholder="123" required>
                                </div>
                            </div>
                            <div class="sdpi-form-group">
                                <label for="sdpi-card-zip">Código postal</label>
                                <input type="tel" id="sdpi-card-zip" inputmode="numeric" maxlength="10" placeholder="Zip del titular">
                            </div>
                            <div class="sdpi-payment-feedback" id="sdpi-payment-feedback" style="display:none;"></div>
                            <button type="button" class="sdpi-pay-btn" id="sdpi-payment-submit">Pagar ahora</button>
                        </form>
                    </div>
                </div>

                <?php
                $this->render_summary_panel(array(
                    'panel_id' => 'sdpi-payment-summary-panel',
                    'extra_classes' => 'sdpi-review-summary-panel',
                    'title' => 'Resumen de tu cotizacion',
                    'subtitle' => 'Verifica los datos antes de realizar el pago.',
                    'value_prefix' => 'sdpi-payment-summary',
                    'total_label' => 'Precio final',
                    'total_value_id' => 'sdpi-payment-summary-price',
                    'footer_id' => 'sdpi-payment-summary-footer-text',
                    'footer_text' => 'Ingresa los datos de tu tarjeta para completar el pago.',
                    'footer_state_class' => 'is-info',
                    'initially_hidden' => true,
                ));
                ?>
            </div>
        </div>
        <?php
    }


    /**
     * Render the client information capture form
     */
    private function render_client_info_form() {
        // Enqueue scripts and styles when client info form is rendered
        $this->enqueue_scripts();

        ob_start();
        ?>
        <div class="sdpi-client-info-form">
            <div class="sdpi-form-section">
                <h3>Informacion de Contacto</h3>
                <p>Para poder contactarlo en caso de que necesite asistencia con su cotizacion o proceso de envÃƒÂ­o, por favor complete la siguiente informacion:</p>

                <form method="post" class="sdpi-form" id="sdpi-registration-form">
                    <input type="hidden" name="sdpi_registration_submit" value="1">
                    <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_name">Nombre Completo *</label>
                        <input type="text" id="sdpi_user_name" name="user_name" required
                               placeholder="Ej: Juan PÃƒÂ©rez">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_phone">NÃƒÂºmero de TelÃƒÂ©fono *</label>
                        <input type="tel" id="sdpi_user_phone" name="user_phone" required
                               placeholder="Ej: (787) 123-4567" pattern="[0-9\(\)\-\+\s]+">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_email">Correo ElectrÃƒÂ³nico *</label>
                        <input type="email" id="sdpi_user_email" name="user_email" required
                               placeholder="Ej: juan@email.com">
                    </div>
                <div class="sdpi-form-submit">
                    <button type="submit" class="sdpi-submit-btn" id="sdpi-submit-btn">Obtener cotización</button>
                </div>
            </form>
            </div>

            <!-- Client info results container -->
            <div id="sdpi-client-info-results" class="sdpi-results" style="display: none;">
                <h3 id="sdpi-client-info-title">Informacion Guardada</h3>
                <p id="sdpi-client-info-message"></p>
            </div>

            <!-- Loading indicator -->
            <div id="sdpi-client-info-loading" class="sdpi-loading" style="display: none;">
                <p>Guardando informacion...</p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Handle client info form submission
            $('#sdpi-registration-form').on('submit', function(e) {
                e.preventDefault();

                var validator = (window.SDPIValidation && typeof window.SDPIValidation.validateForm === 'function')
                    ? window.SDPIValidation.validateForm('#sdpi-registration-form')
                    : { valid: true };
                if (!validator.valid) {
                    if (validator.firstInvalid && validator.firstInvalid.length) {
                        validator.firstInvalid.focus();
                    }
                    return;
                }

                var formData = {
                    action: 'sdpi_save_client_info',
                    nonce: $('input[name="sdpi_nonce"]').val(),
                    client_name: $('#sdpi_user_name').val().trim(),
                    client_phone: $('#sdpi_user_phone').val().trim(),
                    client_email: $('#sdpi_user_email').val().trim()
                };

                // Show loading
                $('#sdpi-client-info-btn').prop('disabled', true).text('Guardando...');
                $('#sdpi-client-info-loading').show();
                $('#sdpi-client-info-results').hide();

                $.ajax({
                    url: sdpi_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        $('#sdpi-client-info-loading').hide();
                        $('#sdpi-client-info-btn').prop('disabled', false).text('Continuar con la Cotizacion');

                        if (response.success) {
                            $('#sdpi-client-info-title').text('Informacion Guardada');
                            $('#sdpi-client-info-message').text('Ã‚Â¡Perfecto! Continuando con la cotizacion...');
                            $('#sdpi-client-info-results').removeClass('error').addClass('success').show();

                            // Reload the page to show the quote form
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#sdpi-client-info-title').text('Error');
                            $('#sdpi-client-info-message').text(response.data || 'Error al guardar la informacion.');
                            $('#sdpi-client-info-results').removeClass('success').addClass('error').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#sdpi-client-info-loading').hide();
                        $('#sdpi-client-info-btn').prop('disabled', false).text('Continuar con la Cotizacion');

                        var errorMessage = 'Error de conexiÃƒÂ³n. Intente nuevamente.';
                        if (status === 'timeout') {
                            errorMessage = 'La solicitud tardÃƒÂ³ demasiado. Intente nuevamente.';
                        } else if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMessage = xhr.responseJSON.data;
                        }

                        $('#sdpi-client-info-title').text('Error');
                        $('#sdpi-client-info-message').text(errorMessage);
                        $('#sdpi-client-info-results').removeClass('success').addClass('error').show();
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for client info capture
     */
    public function ajax_save_client_info() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $client_name = sanitize_text_field($_POST['client_name']);
        $client_phone = sanitize_text_field($_POST['client_phone']);
        $client_email = sanitize_email($_POST['client_email']);

        // Validation
        if (empty($client_name) || empty($client_phone) || empty($client_email)) {
            wp_send_json_error('Todos los campos son requeridos.');
            exit;
        }

        if (!$this->validate_person_name($client_name)) {
            wp_send_json_error('Ingresa un nombre válido utilizando letras y espacios.');
            exit;
        }

        if (!$this->validate_us_phone($client_phone)) {
            wp_send_json_error('Ingresa un número de teléfono de EE. UU. válido con 10 dígitos.');
            exit;
        }
        $client_phone = $this->normalize_digits($client_phone, 10);

        if (!is_email($client_email)) {
            wp_send_json_error('Por favor ingresa un correo electrónico válido.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Store client data in session
        $client_captured_at = current_time('mysql');
        $_SESSION['sdpi_client_info'] = array(
            'name' => $client_name,
            'phone' => $client_phone,
            'email' => $client_email,
            'captured_at' => $client_captured_at
        );

        // Start consolidated quote session
        $session = new SDPI_Session();
        $session_id = $session->start_session($client_name, $client_email, $client_phone);
        $session->set_status($session_id, 'started');

        error_log("ajax_save_client_info session_id: " . $session_id);

        // Create initial history record
        $history = new SDPI_History();
        $history->create_initial_record($session_id, $client_name, $client_email, $client_phone);

        wp_send_json_success(array('message' => 'Informacion del cliente guardada exitosamente.', 'session_id' => $session_id));
        exit;
    }

    /**
     * AJAX handler for finalizing quote with contact info
     * This is called after quote calculation to capture contact details and show the final price
     */
    public function ajax_finalize_quote_with_contact() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        // Get contact info from POST
        $client_name = sanitize_text_field($_POST['client_name']);
        $client_phone = sanitize_text_field($_POST['client_phone']);
        $client_email = sanitize_email($_POST['client_email']);

        // Get the stored quote data from POST
        $quote_data = isset($_POST['quote_data']) ? json_decode(stripslashes($_POST['quote_data']), true) : null;

        // Validation
        if (empty($client_name) || empty($client_phone) || empty($client_email)) {
            wp_send_json_error('Todos los campos de contacto son requeridos.');
            exit;
        }

        if (!$this->validate_person_name($client_name)) {
            wp_send_json_error('Ingresa un nombre de contacto válido utilizando letras y espacios.');
            exit;
        }

        if (!$this->validate_us_phone($client_phone)) {
            wp_send_json_error('Ingresa un número de teléfono de EE. UU. válido con 10 dígitos.');
            exit;
        }
        $client_phone = $this->normalize_digits($client_phone, 10);

        if (!is_email($client_email)) {
            wp_send_json_error('Por favor ingresa un correo electrónico válido.');
            exit;
        }

        if (!$quote_data) {
            wp_send_json_error('No se encontraron datos de cotizacion.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Store client data in session
        $client_captured_at = current_time('mysql');
        $_SESSION['sdpi_client_info'] = array(
            'name' => $client_name,
            'phone' => $client_phone,
            'email' => $client_email,
            'captured_at' => $client_captured_at
        );

        // Start consolidated quote session
        $session = new SDPI_Session();
        $session_id = $session->start_session($client_name, $client_email, $client_phone);
        $session->set_status($session_id, 'started');

        // Create initial history record
        $history = new SDPI_History();
        $history->create_initial_record($session_id, $client_name, $client_email, $client_phone);

        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();

        $documentation_map = $this->merge_documentation_files(array(
            $session_data['documentation_files'] ?? array(),
            $quote_data['documentation_files'] ?? array()
        ));
        $documentation_list = $this->documentation_files_to_list($documentation_map);

        $quote_data['documentation_files'] = $documentation_list;

        $session->update_data($session_id, array(
            'documentation_files' => $documentation_map,
            'quote' => array('documentation_files' => $documentation_list)
        ));

        // Now update to cotizador status with the quote data
        $form_data = array(
            'pickup_zip' => $quote_data['pickup_zip'] ?? '',
            'delivery_zip' => $quote_data['delivery_zip'] ?? '',
            'pickup_city' => $quote_data['pickup_city'] ?? '',
            'delivery_city' => $quote_data['delivery_city'] ?? '',
            'trailer_type' => $quote_data['trailer_type'] ?? '',
            'vehicle_type' => $quote_data['vehicle_type'] ?? '',
            'vehicle_inoperable' => $quote_data['vehicle_inoperable'] ?? false,
            'vehicle_electric' => $quote_data['vehicle_electric'] ?? false,
            'vehicle_make' => $quote_data['vehicle_make'] ?? '',
            'vehicle_model' => $quote_data['vehicle_model'] ?? '',
            'vehicle_year' => $quote_data['vehicle_year'] ?? '',
            'maritime_involved' => $quote_data['maritime_involved'] ?? false,
            'maritime_cost' => $quote_data['maritime_cost'] ?? 0,
            'us_port_name' => $quote_data['us_port']['port'] ?? '',
            'us_port_zip' => $quote_data['us_port']['zip'] ?? '',
            'total_terrestrial_cost' => $quote_data['terrestrial_cost'] ?? 0,
            'total_maritime_cost' => $quote_data['maritime_cost'] ?? 0,
            'inoperable_fee' => $quote_data['inoperable_fee'] ?? 0,
            'maritime_direction' => $quote_data['maritime_direction'] ?? '',
            'transport_type' => $quote_data['transport_type'] ?? (($quote_data['maritime_involved'] ?? false) ? 'maritime' : 'terrestrial'),
            'maritime_details' => $quote_data['maritime_details'] ?? array(),
            'shipping' => $quote_data['shipping'] ?? array(),
            'documentation_files' => $documentation_list,
            'client_name' => $client_name,
            'client_email' => $client_email,
            'client_phone' => $client_phone,
            'client_info_captured_at' => $client_captured_at
        );

        // Update history to cotizador status
        $history->update_to_cotizador(
            $session_id, 
            $form_data, 
            $quote_data['api_response'] ?? array(), 
            $quote_data['final_price'] ?? 0, 
            $quote_data['breakdown'] ?? ''
        );

        // Store quote data in session
        $session->update_data($session_id, array(
            'quote' => $quote_data,
            'client_info' => array(
                'name' => $client_name,
                'email' => $client_email,
                'phone' => $client_phone,
                'captured_at' => $client_captured_at
            )
        ));

        $this->dispatch_zapier_update(
            $session_id,
            $quote_data,
            array(
                'client' => array(
                    'name' => $client_name,
                    'email' => $client_email,
                    'phone' => $client_phone,
                    'captured_at' => $client_captured_at
                ),
                'shipping' => $quote_data['shipping'] ?? array(),
                'transport_type' => $quote_data['transport_type'] ?? (!empty($quote_data['maritime_involved']) ? 'maritime' : 'terrestrial'),
                'documentation_files' => $documentation_list
            )
        );

        // Return the quote data with success flag
        $response_data = $quote_data;
        $response_data['session_id'] = $session_id;
        $response_data['client_name'] = $client_name;
        $response_data['client_email'] = $client_email;
        $response_data['client_phone'] = $client_phone;
        $response_data['client_info_captured_at'] = $client_captured_at;
        $response_data['documentation_files'] = $documentation_list;
        $response_data['show_price'] = true; // Flag to show the price now

        wp_send_json_success($response_data);
        exit;
    }

    public function ajax_list_document_files() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        $files_list = array();

        if ($session_id) {
            $session_row = $session->get($session_id);
            $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
            $files_map = $this->normalize_documentation_files($session_data['documentation_files'] ?? array());

            $session->update_data($session_id, array(
                'documentation_files' => $files_map,
                'quote' => array('documentation_files' => $this->documentation_files_to_list($files_map))
            ));

            $files_list = $this->documentation_files_to_list($files_map);
        }

        wp_send_json_success(array(
            'files' => $files_list,
            'total' => count($files_list),
            'max' => $this->documentation_max_files,
            'max_size' => $this->documentation_max_size
        ));
        exit;
    }

    public function ajax_upload_document_file() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        if (empty($_FILES) || empty($_FILES['file'])) {
            wp_send_json_error('Selecciona un archivo para subir.');
            exit;
        }

        $file = $_FILES['file'];
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            wp_send_json_error('No se pudo procesar el archivo seleccionado.');
            exit;
        }

        if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            $error_code = intval($file['error']);
            $message = 'Error al cargar el archivo. Intenta nuevamente.';
            switch ($error_code) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $message = 'El archivo supera el tamaño máximo permitido de 10 MB.';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $message = 'La carga se interrumpió. Intenta nuevamente.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $message = 'Selecciona un archivo para subir.';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                case UPLOAD_ERR_CANT_WRITE:
                case UPLOAD_ERR_EXTENSION:
                    $message = 'El servidor no pudo guardar el archivo. Contacta al administrador.';
                    break;
            }
            wp_send_json_error($message);
            exit;
        }

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        if (!$session_id) {
            wp_send_json_error('No se pudo asociar la carga con tu sesión. Refresca la página e inténtalo nuevamente.');
            exit;
        }

        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
        $existing_map = $this->normalize_documentation_files($session_data['documentation_files'] ?? array());

        if (count($existing_map) >= $this->documentation_max_files) {
            wp_send_json_error('Solo puedes subir hasta ' . $this->documentation_max_files . ' archivos.');
            exit;
        }

        $file_size = isset($file['size']) ? intval($file['size']) : 0;
        if ($file_size <= 0) {
            wp_send_json_error('El archivo seleccionado está vacío. Verifica el archivo e inténtalo de nuevo.');
            exit;
        }

        if ($file_size > $this->documentation_max_size) {
            wp_send_json_error('Cada archivo debe pesar máximo 10 MB.');
            exit;
        }

        $allowed_mimes = $this->documentation_allowed_mimes;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
        if (empty($filetype['ext']) || empty($filetype['type'])) {
            wp_send_json_error('Formato de archivo no permitido. Usa jpg, jpeg, png, webp o pdf.');
            exit;
        }

        self::ensure_documentation_directory();
        add_filter('upload_dir', array($this, 'filter_documentation_upload_dir'));
        $upload = wp_handle_upload($file, array(
            'test_form' => false,
            'mimes' => $allowed_mimes
        ));
        remove_filter('upload_dir', array($this, 'filter_documentation_upload_dir'));

        if (isset($upload['error'])) {
            wp_send_json_error('No se pudo guardar el archivo: ' . $upload['error']);
            exit;
        }

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            if (!empty($upload['file']) && file_exists($upload['file'])) {
                @unlink($upload['file']);
            }
            wp_send_json_error('No se pudo registrar el archivo en la biblioteca de medios.');
            exit;
        }

        if (strpos($filetype['type'], 'image/') === 0) {
            $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            if (!is_wp_error($metadata) && !empty($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        $uploaded_at = current_time('mysql');
        update_post_meta($attachment_id, '_sdpi_session_id', $session_id);
        update_post_meta($attachment_id, '_sdpi_document_uploaded_at', $uploaded_at);

        $new_entry = array(
            'id' => $attachment_id,
            'url' => esc_url_raw(wp_get_attachment_url($attachment_id)),
            'name' => sanitize_file_name(basename($upload['file'])),
            'type' => sanitize_text_field($filetype['type']),
            'size' => $file_size,
            'uploaded_at' => $uploaded_at
        );

        $existing_map[$attachment_id] = $new_entry;
        $existing_map = $this->normalize_documentation_files($existing_map);

        $session->update_data($session_id, array(
            'documentation_files' => $existing_map,
            'quote' => array('documentation_files' => $this->documentation_files_to_list($existing_map))
        ));

        $files_list = $this->documentation_files_to_list($existing_map);

        wp_send_json_success(array(
            'file' => $existing_map[$attachment_id],
            'files' => $files_list,
            'total' => count($files_list),
            'max' => $this->documentation_max_files
        ));
        exit;
    }

    public function ajax_delete_document_file() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error('Archivo no válido.');
            exit;
        }

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        if (!$session_id) {
            wp_send_json_error('No se pudo validar la sesión. Refresca la página e inténtalo nuevamente.');
            exit;
        }

        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
        $existing_map = $this->normalize_documentation_files($session_data['documentation_files'] ?? array());

        if (empty($existing_map[$attachment_id])) {
            wp_send_json_error('El archivo ya no está disponible en la sesión.');
            exit;
        }

        $stored_session = get_post_meta($attachment_id, '_sdpi_session_id', true);
        if (!empty($stored_session) && $stored_session !== $session_id) {
            wp_send_json_error('No tienes permiso para eliminar este archivo.');
            exit;
        }

        unset($existing_map[$attachment_id]);
        wp_delete_attachment($attachment_id, true);

        $existing_map = $this->normalize_documentation_files($existing_map);

        $session->update_data($session_id, array(
            'documentation_files' => $existing_map,
            'quote' => array('documentation_files' => $this->documentation_files_to_list($existing_map))
        ));

        $files_list = $this->documentation_files_to_list($existing_map);

        wp_send_json_success(array(
            'files' => $files_list,
            'total' => count($files_list),
            'max' => $this->documentation_max_files
        ));
        exit;
    }

    /**
     * AJAX handler for saving additional shipping info
     */
    public function ajax_save_additional_info() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $p_name = sanitize_text_field($_POST['p_name']);
        $p_street = sanitize_text_field($_POST['p_street']);
        $p_city = sanitize_text_field($_POST['p_city']);
        $p_zip = sanitize_text_field($_POST['p_zip']);
        $d_name = sanitize_text_field($_POST['d_name']);
        $d_street = sanitize_text_field($_POST['d_street']);
        $d_city = sanitize_text_field($_POST['d_city']);
        $d_zip = sanitize_text_field($_POST['d_zip']);
        $pickup_type = sanitize_text_field($_POST['pickup_type']);

        // Validation
        if (empty($p_name) || empty($p_street) || empty($p_city) || empty($p_zip) ||
            empty($d_name) || empty($d_street) || empty($d_city) || empty($d_zip) ||
            empty($pickup_type)) {
            wp_send_json_error('Todos los campos son requeridos.');
            exit;
        }

        if (!$this->validate_person_name($p_name) || !$this->validate_person_name($d_name)) {
            wp_send_json_error('Los nombres de recogida y entrega deben contener únicamente letras y espacios.');
            exit;
        }

        if (!$this->validate_address_line($p_street) || !$this->validate_address_line($d_street)) {
            wp_send_json_error('Las direcciones de recogida y entrega contienen caracteres no permitidos.');
            exit;
        }

        if (!$this->validate_city_name($p_city) || !$this->validate_city_name($d_city)) {
            wp_send_json_error('Las ciudades de recogida y entrega deben contener únicamente letras y espacios.');
            exit;
        }

        if (!$this->validate_us_zip($p_zip) || !$this->validate_us_zip($d_zip)) {
            wp_send_json_error('Los códigos postales deben tener exactamente 5 dígitos de EE. UU.');
            exit;
        }

        $p_zip = $this->normalize_digits($p_zip, 5);
        $d_zip = $this->normalize_digits($d_zip, 5);

        // Validate ZIP codes
        $valid_pickup_types = array('Subasta', 'Residencia', 'Dealer o negocio');
        if (!in_array($pickup_type, $valid_pickup_types, true)) {
            wp_send_json_error('Tipo de recogida invÃƒÂ¡lido.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        $shipping_details = array(
            'pickup' => array(
                'name' => $p_name,
                'street' => $p_street,
                'city' => $p_city,
                'zip' => $p_zip,
                'type' => $pickup_type
            ),
            'delivery' => array(
                'name' => $d_name,
                'street' => $d_street,
                'city' => $d_city,
                'zip' => $d_zip
            ),
            'saved_at' => current_time('mysql')
        );

        // Store additional shipping info in session (legacy structure retained for compatibility)
        $_SESSION['sdpi_additional_info'] = array(
            'p_name' => $p_name,
            'p_street' => $p_street,
            'p_city' => $p_city,
            'p_zip' => $p_zip,
            'd_name' => $d_name,
            'd_street' => $d_street,
            'd_city' => $d_city,
            'd_zip' => $d_zip,
            'pickup_type' => $pickup_type,
            'saved_at' => $shipping_details['saved_at']
        );

        // Persist to consolidated quote session
        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        $session->update_data($session_id, array(
            'shipping' => array(
                'pickup' => array(
                    'name' => $p_name,
                    'street' => $p_street,
                    'city' => $p_city,
                    'zip' => $p_zip,
                    'type' => $pickup_type
                ),
                'delivery' => array(
                    'name' => $d_name,
                    'street' => $d_street,
                    'city' => $d_city,
                    'zip' => $d_zip
                )
            ),
            'transport_type' => 'terrestrial'
        ));

        // Update history record with additional shipping data
        if ($session_id) {
            $history = new SDPI_History();
            $history->update_additional_shipping($session_id, $shipping_details);

            $session_row = $session->get($session_id);
            $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
            $quote_data = isset($session_data['quote']) && is_array($session_data['quote']) ? $session_data['quote'] : array();
            if (!empty($quote_data)) {
                $this->dispatch_zapier_update($session_id, $quote_data, array('shipping' => $shipping_details));
            }
        }

        wp_send_json_success('Informacion adicional guardada exitosamente.');
        exit;
    }

    /**
     * AJAX handler for saving maritime-specific additional info
     */
    public function ajax_save_maritime_info() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $direction = sanitize_text_field($_POST['maritime_direction'] ?? '');
        $valid_directions = array('usa_to_pr', 'pr_to_usa', 'pr_pr');

        if (empty($direction) || !in_array($direction, $valid_directions, true)) {
            wp_send_json_error('Direccion maritima invAÃ¯Â¿Â½lida.');
            exit;
        }

        $vehicle_conditions = sanitize_text_field($_POST['vehicle_conditions'] ?? '');
        $fuel_type = sanitize_text_field($_POST['fuel_type'] ?? '');
        $unit_value = isset($_POST['unit_value']) ? floatval($_POST['unit_value']) : 0;
        $color = sanitize_text_field($_POST['color'] ?? '');
        $dimensions = sanitize_text_field($_POST['dimensions'] ?? '');

        if (empty($vehicle_conditions) || empty($fuel_type)) {
            $session = new SDPI_Session();
            $session_id = $session->get_session_id();
            if ($session_id) {
                $session_row = $session->get($session_id);
                if (!empty($session_row) && isset($session_row['data']['quote'])) {
                    $quote_data = $session_row['data']['quote'];
                    if (empty($vehicle_conditions)) {
                        $is_inoperable = !empty($quote_data['vehicle_inoperable']);
                        $vehicle_conditions = $is_inoperable ? 'Non-Running' : 'Running';
                    }
                    if (empty($fuel_type)) {
                        $is_electric = !empty($quote_data['vehicle_electric']);
                        $fuel_type = $is_electric ? 'Electric' : 'Gasoline';
                    }
                }
            }
        }

        if (empty($vehicle_conditions) || empty($fuel_type) || empty($color) || $unit_value <= 0) {
            wp_send_json_error('Complete la Datos del vehiculo (condicion, combustible, valor y color).');
            exit;
        }

        if (!$this->validate_person_name($color)) {
            wp_send_json_error('Ingresa un color del vehículo válido.');
            exit;
        }

        if (!$this->validate_dimensions_value($dimensions)) {
            wp_send_json_error('Las dimensiones deben tener el formato Largo x Ancho x Alto con unidades opcionales.');
            exit;
        }

        $shipper = array(
            'name' => sanitize_text_field($_POST['shipper_name'] ?? ''),
            'street' => sanitize_text_field($_POST['shipper_street'] ?? ''),
            'city' => sanitize_text_field($_POST['shipper_city'] ?? ''),
            'state' => sanitize_text_field($_POST['shipper_state'] ?? ''),
            'country' => sanitize_text_field($_POST['shipper_country'] ?? ''),
            'zip' => sanitize_text_field($_POST['shipper_zip'] ?? ''),
            'phone1' => sanitize_text_field($_POST['shipper_phone1'] ?? ''),
            'phone2' => sanitize_text_field($_POST['shipper_phone2'] ?? '')
        );

        $consignee = array(
            'name' => sanitize_text_field($_POST['consignee_name'] ?? ''),
            'street' => sanitize_text_field($_POST['consignee_street'] ?? ''),
            'city' => sanitize_text_field($_POST['consignee_city'] ?? ''),
            'state' => sanitize_text_field($_POST['consignee_state'] ?? ''),
            'country' => sanitize_text_field($_POST['consignee_country'] ?? ''),
            'zip' => sanitize_text_field($_POST['consignee_zip'] ?? ''),
            'phone1' => sanitize_text_field($_POST['consignee_phone1'] ?? ''),
            'phone2' => sanitize_text_field($_POST['consignee_phone2'] ?? '')
        );

        $pickup_required = in_array($direction, array('usa_to_pr', 'pr_pr'), true);
        $pickup = array(
            'name' => sanitize_text_field($_POST['pickup_name'] ?? ''),
            'street' => sanitize_text_field($_POST['pickup_street'] ?? ''),
            'city' => sanitize_text_field($_POST['pickup_city'] ?? ''),
            'state' => sanitize_text_field($_POST['pickup_state'] ?? ''),
            'country' => sanitize_text_field($_POST['pickup_country'] ?? ''),
            'zip' => sanitize_text_field($_POST['pickup_zip'] ?? ''),
            'phone1' => sanitize_text_field($_POST['pickup_phone1'] ?? ''),
            'phone2' => sanitize_text_field($_POST['pickup_phone2'] ?? '')
        );

        $dropoff_required = in_array($direction, array('pr_to_usa', 'pr_pr'), true);
        $dropoff = array(
            'name' => sanitize_text_field($_POST['dropoff_name'] ?? ''),
            'street' => sanitize_text_field($_POST['dropoff_street'] ?? ''),
            'city' => sanitize_text_field($_POST['dropoff_city'] ?? ''),
            'state' => sanitize_text_field($_POST['dropoff_state'] ?? ''),
            'country' => sanitize_text_field($_POST['dropoff_country'] ?? ''),
            'zip' => sanitize_text_field($_POST['dropoff_zip'] ?? ''),
            'phone1' => sanitize_text_field($_POST['dropoff_phone1'] ?? ''),
            'phone2' => sanitize_text_field($_POST['dropoff_phone2'] ?? '')
        );

        $others = array(
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'registration' => sanitize_text_field($_POST['registration'] ?? ''),
            'id' => sanitize_text_field($_POST['other_id'] ?? '')
        );

        $validate_contact = function (&$details, $label, $required) {
            $fields = array('name', 'street', 'city', 'state', 'zip', 'phone1');
            $has_country = array_key_exists('country', $details);
            if ($has_country) {
                $fields[] = 'country';
            }

            $value_fields = array('name', 'street', 'city', 'zip', 'phone1');
            $has_values = false;
            foreach ($value_fields as $field_name) {
                if (!empty($details[$field_name])) {
                    $has_values = true;
                    break;
                }
            }

            if (!$required && !$has_values) {
                foreach ($details as $key => $value) {
                    $details[$key] = '';
                }
                return;
            }

            foreach ($fields as $field) {
                if ($required && empty($details[$field])) {
                    wp_send_json_error('Completa toda la información ' . $label . '.');
                    exit;
                }
            }

            if (!$this->validate_person_name($details['name'])) {
                wp_send_json_error('El nombre ' . $label . ' solo puede incluir letras y espacios.');
                exit;
            }

            if (!$this->validate_address_line($details['street'])) {
                wp_send_json_error('La dirección ' . $label . ' contiene caracteres no permitidos.');
                exit;
            }

            if (!$this->validate_city_name($details['city'])) {
                wp_send_json_error('La ciudad ' . $label . ' solo puede incluir letras y espacios.');
                exit;
            }

            $details['state'] = $this->normalize_state_code($details['state']);
            if (!$this->validate_state_code($details['state'])) {
                wp_send_json_error('Ingresa el estado ' . $label . ' con el código de dos letras.');
                exit;
            }

            if ($has_country) {
                if (!$this->validate_allowed_country($details['country'])) {
                    wp_send_json_error('Selecciona un país válido para la sección ' . $label . '.');
                    exit;
                }
                $normalized_country = strtoupper(trim($details['country'])) === 'PUERTO RICO' ? 'Puerto Rico' : 'USA';
                $details['country'] = $normalized_country;
            }

            if (!$this->validate_us_zip($details['zip'])) {
                wp_send_json_error('Ingresa un código postal válido de 5 dígitos para la sección ' . $label . '.');
                exit;
            }
            $details['zip'] = $this->normalize_digits($details['zip'], 5);

            if (!$this->validate_us_phone($details['phone1'])) {
                wp_send_json_error('Ingresa un teléfono válido de 10 dígitos para la sección ' . $label . '.');
                exit;
            }
            $details['phone1'] = $this->normalize_digits($details['phone1'], 10);

            if (!empty($details['phone2'])) {
                if (!$this->validate_us_phone($details['phone2'])) {
                    wp_send_json_error('El teléfono secundario ' . $label . ' debe tener 10 dígitos válidos.');
                    exit;
                }
                $details['phone2'] = $this->normalize_digits($details['phone2'], 10);
            } else {
                $details['phone2'] = '';
            }
        };

        $validate_contact($shipper, 'del shipper', true);
        $validate_contact($consignee, 'del consignatario', true);
        $validate_contact($pickup, 'de recogida', $pickup_required);
        $validate_contact($dropoff, 'de entrega', $dropoff_required);

        if (!empty($others['title']) && !$this->validate_generic_text($others['title'])) {
            wp_send_json_error('El campo Title solo puede contener letras y números.');
            exit;
        }

        if (!empty($others['registration']) && !$this->validate_generic_text($others['registration'])) {
            wp_send_json_error('El campo Registration solo puede contener letras y números.');
            exit;
        }

        if (!empty($others['id']) && !$this->validate_generic_text($others['id'])) {
            wp_send_json_error('El identificador adicional solo puede contener letras y números.');
            exit;
        }

        $maritime_details = array(
            'direction' => $direction,
            'vehicle' => array(
                'conditions' => $vehicle_conditions,
                'fuel_type' => $fuel_type,
                'unit_value' => $unit_value,
                'color' => $color,
                'dimensions' => $dimensions
            ),
            'shipper' => $shipper,
            'consignee' => $consignee,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'others' => $others,
            'saved_at' => current_time('mysql')
        );

        if (!session_id()) {
            session_start();
        }

        $_SESSION['sdpi_maritime_info'] = $maritime_details;

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        $session->update_data($session_id, array(
            'maritime' => $maritime_details,
            'transport_type' => 'maritime'
        ));

        $history = new SDPI_History();
        $history->update_maritime_details($session_id, $maritime_details, $direction);
        $maritime_shipping_summary = array(
            'pickup' => array(
                'name' => isset($shipper['name']) ? $shipper['name'] : '',
                'street' => isset($shipper['street']) ? $shipper['street'] : '',
                'city' => isset($shipper['city']) ? $shipper['city'] : '',
                'zip' => isset($shipper['zip']) ? $shipper['zip'] : '',
                'type' => ucwords(str_replace('_', ' ', $direction))
            ),
            'delivery' => array(
                'name' => isset($consignee['name']) ? $consignee['name'] : '',
                'street' => isset($consignee['street']) ? $consignee['street'] : '',
                'city' => isset($consignee['city']) ? $consignee['city'] : '',
                'zip' => isset($consignee['zip']) ? $consignee['zip'] : ''
            ),
            'saved_at' => current_time('mysql')
        );
        $history->update_additional_shipping($session_id, $maritime_shipping_summary);

        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
        $quote_data = isset($session_data['quote']) && is_array($session_data['quote']) ? $session_data['quote'] : array();
        if (!empty($quote_data)) {
            $this->dispatch_zapier_update(
                $session_id,
                $quote_data,
                array(
                    'maritime_details' => $maritime_details,
                    'shipping' => $maritime_shipping_summary,
                    'transport_type' => 'maritime'
                )
            );
        }

        wp_send_json_success(array(
            'message' => 'Informacion maritima guardada exitosamente.',
            'maritime_details' => $maritime_details,
            'direction' => $direction,
            'shipping_summary' => $maritime_shipping_summary
        ));
        exit;
    }

    /**
     * AJAX handler for getting a quote - MODIFIED to request contact info first
     */
    public function ajax_get_quote() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $pickup_zip = sanitize_text_field($_POST['pickup_zip']);
        $delivery_zip = sanitize_text_field($_POST['delivery_zip']);
        $pickup_city = sanitize_text_field($_POST['pickup_city'] ?? '');
        $delivery_city = sanitize_text_field($_POST['delivery_city'] ?? '');
        $trailer_type = sanitize_text_field($_POST['trailer_type']);
        $vehicle_type = sanitize_text_field($_POST['vehicle_type']);
        $vehicle_inoperable = !empty($_POST['vehicle_inoperable']);
        $vehicle_electric = !empty($_POST['vehicle_electric']);
        $vehicle_make = sanitize_text_field($_POST['vehicle_make']);
        $vehicle_model = sanitize_text_field($_POST['vehicle_model']);
        $vehicle_year_input = isset($_POST['vehicle_year']) ? trim((string) $_POST['vehicle_year']) : '';

        if (!$this->validate_us_zip($pickup_zip) || !$this->validate_us_zip($delivery_zip)) {
            wp_send_json_error('Proporciona códigos postales válidos de 5 dígitos en EE. UU. para origen y destino.');
            exit;
        }

        $valid_trailers = array('open', 'enclosed');
        if (!in_array($trailer_type, $valid_trailers, true)) {
            wp_send_json_error('Selecciona un tipo de tráiler válido.');
            exit;
        }

        $valid_vehicle_types = array('sedan', 'suv', 'van', 'coupe_2_doors', 'pickup_2_doors', 'pickup_4_doors');
        if (!in_array($vehicle_type, $valid_vehicle_types, true)) {
            wp_send_json_error('Selecciona un tipo de vehículo válido.');
            exit;
        }

        if (!empty($vehicle_make) && !$this->validate_generic_text($vehicle_make)) {
            wp_send_json_error('La marca del vehículo solo puede incluir letras, números y espacios.');
            exit;
        }

        if (!empty($vehicle_model) && !$this->validate_generic_text($vehicle_model)) {
            wp_send_json_error('El modelo del vehículo solo puede incluir letras, números y espacios.');
            exit;
        }

        if ($vehicle_year_input !== '' && !$this->validate_vehicle_year_value($vehicle_year_input)) {
            wp_send_json_error('Ingresa un año de vehículo válido mayor a 1800.');
            exit;
        }
        $vehicle_year = $vehicle_year_input === '' ? 0 : (int) $vehicle_year_input;

        // Check if maritime transport is involved (San Juan, PR)
        $involves_maritime = SDPI_Maritime::involves_maritime($pickup_zip, $delivery_zip);
        $maritime_direction = '';
        $api_response = null;
        
        if ($involves_maritime) {
            // For maritime routes, NEVER pass San Juan to API
            $pickup_is_san_juan = SDPI_Maritime::is_san_juan_zip($pickup_zip);
            $delivery_is_san_juan = SDPI_Maritime::is_san_juan_zip($delivery_zip);

            if ($pickup_is_san_juan && !$delivery_is_san_juan) {
                $maritime_direction = 'pr_to_usa';
            } elseif (!$pickup_is_san_juan && $delivery_is_san_juan) {
                $maritime_direction = 'usa_to_pr';
            } elseif ($pickup_is_san_juan && $delivery_is_san_juan) {
                $maritime_direction = 'pr_pr';
            }
            
            if ($pickup_is_san_juan && $delivery_is_san_juan) {
                // San Juan to San Juan - only maritime, no API call needed
                $final_price_data = $this->calculate_maritime_only_quote($pickup_zip, $delivery_zip, $vehicle_electric, $vehicle_inoperable);
            } else {
                // One side is San Juan, one side is continental
                $continental_zip = $pickup_is_san_juan ? $delivery_zip : $pickup_zip;
                $us_port = SDPI_Maritime::get_us_port($continental_zip);
                
                // Call API ONLY with continental ZIPs (never San Juan)
                $api_pickup_zip = $pickup_is_san_juan ? $us_port['zip'] : $pickup_zip;
                $api_delivery_zip = $delivery_is_san_juan ? $us_port['zip'] : $delivery_zip;
                
                $form_data = array(
                    'pickup' => array('zip' => $api_pickup_zip),
                    'delivery' => array('zip' => $api_delivery_zip),
                    'trailer_type' => $trailer_type,
                    'vehicles' => array(
                        array(
                            'type' => $vehicle_type,
                            'is_inoperable' => $vehicle_inoperable,
                            'make' => $vehicle_make,
                            'model' => $vehicle_model,
                            'year' => (string)$vehicle_year
                        )
                    )
                );

                $api_response = $this->api->get_pricing_quote($form_data);

                if (is_wp_error($api_response)) {
                    wp_send_json_error($api_response->get_error_message());
                    exit;
                }

                // Calculate final price with maritime transport
                $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip, $vehicle_electric, $vehicle_inoperable);
            }
        } else {
            // For terrestrial routes, use original ZIPs
            $form_data = array(
                'pickup' => array('zip' => $pickup_zip),
                'delivery' => array('zip' => $delivery_zip),
                'trailer_type' => $trailer_type,
                'vehicles' => array(
                    array(
                        'type' => $vehicle_type,
                        'is_inoperable' => $vehicle_inoperable,
                        'make' => $vehicle_make,
                        'model' => $vehicle_model,
                        'year' => (string)$vehicle_year
                    )
                )
            );

            $api_response = $this->api->get_pricing_quote($form_data);

            if (is_wp_error($api_response)) {
                wp_send_json_error($api_response->get_error_message());
                exit;
            }

            // Calculate final price with company profit and confidence adjustments
            $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip, $vehicle_electric, $vehicle_inoperable);
        }

        // NUEVO FLUJO: Preparar los datos de cotizacion pero NO mostrar el precio aÃƒÂºn
        $inoperable_fee = $involves_maritime ? ($final_price_data['inoperable_fee'] ?? 0) : 0;

        $quote_data = array(
            'pickup_zip' => $pickup_zip,
            'delivery_zip' => $delivery_zip,
            'pickup_city' => $pickup_city ?: $this->get_city_from_zip($pickup_zip),
            'delivery_city' => $delivery_city ?: $this->get_city_from_zip($delivery_zip),
            'trailer_type' => $trailer_type,
            'vehicle_type' => $vehicle_type,
            'vehicle_inoperable' => $vehicle_inoperable,
            'vehicle_electric' => $vehicle_electric,
            'vehicle_make' => $vehicle_make,
            'vehicle_model' => $vehicle_model,
            'vehicle_year' => $vehicle_year,
            'maritime_involved' => $involves_maritime,
            'inoperable_fee' => $inoperable_fee,
            'electric_surcharge' => $final_price_data['electric_surcharge'] ?? 0,
            'maritime_direction' => $maritime_direction,
            'maritime_cost' => $involves_maritime ? ($final_price_data['maritime_cost'] ?? 0) : 0,
            'us_port' => $involves_maritime ? ($final_price_data['us_port'] ?? null) : null,
            'terrestrial_cost' => $involves_maritime ? ($final_price_data['terrestrial_cost'] ?? 0) : 0,
            'api_response' => $api_response,
            'final_price' => $final_price_data['final_price'],
            'base_price' => $final_price_data['base_price'] ?? 0,
            'confidence' => $final_price_data['confidence'] ?? 0,
            'confidence_percentage' => $final_price_data['confidence_percentage'] ?? 0,
            'company_profit' => $final_price_data['company_profit'] ?? 0,
            'confidence_adjustment' => $final_price_data['confidence_adjustment'] ?? 0,
            'breakdown' => $final_price_data['breakdown'] ?? '',
            'message' => $final_price_data['message'] ?? '',
            'payment_available' => $this->is_authorize_ready(),
            'transport_type' => $involves_maritime ? 'maritime' : 'terrestrial'
        );

        // Retornar respuesta indicando que necesitamos informacion de contacto
        wp_send_json_success(array(
            'needs_contact_info' => true,
            'quote_calculated' => true,
            'quote_data' => $quote_data // Enviar los datos de cotizacion para usar despuÃƒÂ©s de capturar contacto
        ));
        exit;
    }


    /**
     * Log quote to history
     */
    private function log_to_history($pickup_zip, $delivery_zip, $trailer_type, $vehicle_type, $vehicle_inoperable, $vehicle_electric, $vehicle_make, $vehicle_model, $vehicle_year, $api_response, $final_price_data, $involves_maritime) {
        try {
            // Get cities from ZIPs
            $pickup_city = $this->get_city_from_zip($pickup_zip);
            $delivery_city = $this->get_city_from_zip($delivery_zip);

            // Get client info from session
            $client_info = array();
            if (!session_id()) {
                session_start();
            }
            if (isset($_SESSION['sdpi_client_info'])) {
                $client_info = $_SESSION['sdpi_client_info'];
            }

            // Prepare form data for logging
            $form_data = array(
                'pickup_zip' => $pickup_zip,
                'delivery_zip' => $delivery_zip,
                'pickup_city' => $pickup_city,
                'delivery_city' => $delivery_city,
                'trailer_type' => $trailer_type,
                'vehicle_type' => $vehicle_type,
                'vehicle_inoperable' => $vehicle_inoperable,
                'vehicle_electric' => $vehicle_electric,
                'vehicle_make' => $vehicle_make,
                'vehicle_model' => $vehicle_model,
                'vehicle_year' => $vehicle_year,
                'maritime_involved' => $involves_maritime,
                'client_name' => isset($client_info['name']) ? $client_info['name'] : '',
                'client_phone' => isset($client_info['phone']) ? $client_info['phone'] : '',
                'client_email' => isset($client_info['email']) ? $client_info['email'] : '',
                'client_info_captured_at' => isset($client_info['captured_at']) ? $client_info['captured_at'] : null
            );
            
        // Add maritime data if applicable
        if ($involves_maritime) {
            $form_data['maritime_cost'] = $final_price_data['maritime_cost'] ?? 0;
            $form_data['us_port_name'] = isset($final_price_data['us_port']['port']) ? $final_price_data['us_port']['port'] : '';
            $form_data['us_port_zip'] = isset($final_price_data['us_port']['zip']) ? $final_price_data['us_port']['zip'] : '';
            $form_data['total_terrestrial_cost'] = $final_price_data['terrestrial_cost'] ?? 0;
            $form_data['total_maritime_cost'] = $final_price_data['maritime_cost'] ?? 0;
            $form_data['maritime_direction'] = $maritime_direction;
        }
            // Log to history
            $history = new SDPI_History();
            return $history->log_quote($form_data, $api_response, $final_price_data['final_price'], $final_price_data['breakdown']);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get city name from ZIP code
     */
    private function get_city_from_zip($zip) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT CONCAT(city, ', ', state_id) FROM {$wpdb->prefix}sdpi_cities WHERE zips LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($zip) . '%'
        ));
        
        return $result ?: $zip;
    }

    /**
     * Calculate maritime-only quote without API call (San Juan to San Juan)
     */
    private function calculate_maritime_only_quote($pickup_zip, $delivery_zip, $vehicle_electric = false, $vehicle_inoperable = false) {
        // This should only be called for San Juan to San Juan routes
        $maritime_cost = SDPI_Maritime::get_maritime_rate($pickup_zip, $delivery_zip);

        // Surcharges
        $electric_surcharge = $vehicle_electric ? 600.00 : 0.00;
        $inoperable_fee = $vehicle_inoperable ? SDPI_Maritime::INOPERABLE_FEE : 0.00;
        $total_cost = $maritime_cost + $electric_surcharge + $inoperable_fee;

        $breakdown = '<div class="sdpi-maritime-breakdown">';
        $breakdown .= '<h4>Desglose de Costos - Transporte Marítimo</h4>';

        $breakdown .= '<div class="sdpi-cost-section">';
        $breakdown .= '<h5>Tramo Terrestre</h5>';
        $breakdown .= '<p><em>No aplica - Transporte directo marítimo</em></p>';
        $breakdown .= '</div>';

        $breakdown .= '<div class="sdpi-cost-section">';
        $breakdown .= '<h5>Tramo Marítimo</h5>';
        $breakdown .= '<p><strong>Ruta:</strong> San Juan, PR → San Juan, PR</p>';
        $breakdown .= '<p><strong>Tarifa Marítima:</strong> $' . number_format($maritime_cost, 2) . ' USD</p>';
        $breakdown .= '</div>';

        if (($vehicle_electric && $electric_surcharge > 0) || ($vehicle_inoperable && $inoperable_fee > 0)) {
            $breakdown .= '<div class="sdpi-cost-section">';
            $breakdown .= '<h5>Recargos Adicionales</h5>';
            if ($vehicle_electric && $electric_surcharge > 0) {
                $breakdown .= '<div class="sdpi-price-item">';
                $breakdown .= '<span class="sdpi-price-label">Recargo por vehículo eléctrico:</span>';
                $breakdown .= '<span class="sdpi-price-value">+$' . number_format($electric_surcharge, 2) . ' USD</span>';
                $breakdown .= '</div>';
            }
            if ($vehicle_inoperable && $inoperable_fee > 0) {
                $breakdown .= '<div class="sdpi-price-item">';
                $breakdown .= '<span class="sdpi-price-label">Recargo por vehículo inoperable:</span>';
                $breakdown .= '<span class="sdpi-price-value">+$' . number_format($inoperable_fee, 2) . ' USD</span>';
                $breakdown .= '</div>';
            }
            $breakdown .= '</div>';
        }

        $breakdown .= '<div class="sdpi-cost-total">';
        $breakdown .= '<h5>Total Final</h5>';
        $breakdown .= '<p><strong>Costo Total:</strong> $' . number_format($total_cost, 2) . ' USD</p>';
        $breakdown .= '<p class="sdpi-maritime-note">* Transporte marítimo directo</p>';
        $breakdown .= '</div>';

        $breakdown .= '</div>';

        return array(
            'base_price' => 0,
            'confidence' => 0.85,
            'final_price' => $total_cost,
            'message' => 'El precio recomendado incluye transporte marítimo directo.',
            'breakdown' => $breakdown,
            'price' => $total_cost,
            'confidence_percentage' => 85,
            'maritime_involved' => true,
            'us_port' => null,
            'terrestrial_cost' => 0,
            'maritime_cost' => $maritime_cost,
            'inoperable_fee' => $inoperable_fee,
            'electric_surcharge' => $electric_surcharge
        );
    }

    /**
     * Calculate final price with company profit and confidence adjustments including maritime transport
     */
    private function calculate_final_price($api_response, $pickup_zip = '', $delivery_zip = '', $vehicle_electric = false, $vehicle_inoperable = false) {
        $base_price = floatval($api_response['recommended_price'] ?? 0);
        $confidence = floatval($api_response['confidence'] ?? 0);
        
        // Check if maritime transport is involved
        $maritime_result = SDPI_Maritime::calculate_maritime_cost($pickup_zip, $delivery_zip, $base_price, $confidence, $vehicle_electric, $vehicle_inoperable);
        
        if ($maritime_result['maritime_involved']) {
            // Return maritime calculation result
            return array(
                'base_price' => $base_price,
                'confidence' => $confidence,
                'final_price' => $maritime_result['total_cost'],
                'message' => 'El precio recomendado incluye transporte marÃƒÂ­timo entre ' . $maritime_result['us_port']['port'] . ' y San Juan, PR.',
                'breakdown' => $maritime_result['breakdown'],
                'price' => $maritime_result['total_cost'],
                'confidence_percentage' => $confidence * 100,
                'maritime_involved' => true,
                'us_port' => $maritime_result['us_port'],
                'terrestrial_cost' => $maritime_result['terrestrial_cost'],
                'maritime_cost' => $maritime_result['maritime_cost'],
                'inoperable_fee' => $maritime_result['inoperable_fee'] ?? 0,
                'electric_surcharge' => $maritime_result['electric_surcharge'] ?? 0
            );
        }
        
        // Company profit (fixed) - only for terrestrial transport
        $company_profit = 200.00;

        // Electric vehicle surcharge
        $electric_surcharge = $vehicle_electric ? 600.00 : 0.00;

        // Confidence-based adjustment
        $confidence_adjustment = 0;
        $confidence_description = '';

        if ($confidence >= 60 && $confidence <= 100) {
            // Add percentage to reach 100%
            $remaining_percentage = 100 - $confidence;
            $confidence_adjustment = $base_price * ($remaining_percentage / 100);
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%% Ã¢â€ â€™ 100%%): +$%s USD',
                number_format($confidence, 1),
                number_format($confidence_adjustment, 2)
            );
        } elseif ($confidence >= 30 && $confidence <= 59) {
            // Add fixed $150
            $confidence_adjustment = 150.00;
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%%): +$150.00 USD',
                number_format($confidence, 1)
            );
        } elseif ($confidence >= 0 && $confidence <= 29) {
            // Add fixed $200
            $confidence_adjustment = 200.00;
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%%): +$200.00 USD',
                number_format($confidence, 1)
            );
        }

        // Calculate final price
        $final_price = $base_price + $confidence_adjustment + $company_profit + $electric_surcharge;
        
        // Build detailed message
        $message = sprintf(
            'El precio recomendado es $%s USD con un nivel de confianza de %s%%.',
            number_format($base_price, 2),
            number_format($confidence * 100, 1)
        );
        
        $breakdown = sprintf(
            '<div class="sdpi-price-breakdown">
                <h4>Desglose de Precios:</h4>
                <div class="sdpi-price-item">
                    <span class="sdpi-price-label">Precio base de la API:</span>
                    <span class="sdpi-price-value">$%s USD</span>
                </div>
                <div class="sdpi-price-item">
                    <span class="sdpi-price-label">%s</span>
                    <span class="sdpi-price-value">+$%s USD</span>
                </div>
                <div class="sdpi-price-item">
                    <span class="sdpi-price-label">Ganancia de la empresa:</span>
                    <span class="sdpi-price-value">+$%s USD</span>
                </div>%s
                <div class="sdpi-price-item sdpi-price-total">
                    <span class="sdpi-price-label"><strong>Total Final:</strong></span>
                    <span class="sdpi-price-value"><strong>$%s USD</strong></span>
                </div>
            </div>',
            number_format($base_price, 2),
            $confidence_description,
            number_format($confidence_adjustment, 2),
            number_format($company_profit, 2),
            $vehicle_electric ? sprintf(
                '<div class="sdpi-price-item">
                    <span class="sdpi-price-label">Recargo por vehÃƒÂ­culo elÃƒÂ©ctrico:</span>
                    <span class="sdpi-price-value">+$%s USD</span>
                </div>',
                number_format($electric_surcharge, 2)
            ) : '',
            number_format($final_price, 2)
        );

        return array(
            'base_price' => $base_price,
            'confidence' => $confidence,
            'confidence_adjustment' => $confidence_adjustment,
            'company_profit' => $company_profit,
            'final_price' => $final_price,
            'message' => $message,
            'breakdown' => $breakdown,
            'price' => $final_price, // For backward compatibility
            'confidence_percentage' => $confidence * 100,
            'inoperable_fee' => 0,
            'electric_surcharge' => $electric_surcharge,
            'terrestrial_cost' => $final_price,
            'maritime_cost' => 0
        );
    }

    /**
     * Handle traditional form submission (fallback)
     */
    public function handle_form_submission() {
        if (!isset($_POST['sdpi_form_submit']) || !$_POST['sdpi_form_submit']) {
            return;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Clear previous results
        unset($_SESSION['sdpi_result']);

        // Prepare form data
        $form_data = $this->prepare_form_data($_POST);

        if (is_wp_error($form_data)) {
            $_SESSION['sdpi_result'] = array(
                'success' => false,
                'message' => $form_data->get_error_message()
            );
            return;
        }

        // Get pricing quote from API
        $api_response = $this->api->get_pricing_quote($form_data);

        if (is_wp_error($api_response)) {
            $_SESSION['sdpi_result'] = array(
                'success' => false,
                'message' => 'No se pudo obtener la cotizacion en este momento. Por favor, intente nuevamente.'
            );
            return;
        }

        // Store successful result
        $_SESSION['sdpi_result'] = array(
            'success' => true,
            'price' => $api_response['recommended_price'] ?? 0,
            'confidence' => $api_response['confidence'] ?? 0,
            'message' => sprintf(
                'Tu cotizacion total es $%s USD.',
                number_format(floatval($api_response['recommended_price'] ?? 0), 2)
            )
        );

        // Redirect to prevent form resubmission
        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    /**
     * Prepare form data for API
     */
    private function prepare_form_data($post_data) {
        $form_data = array();

        // Validate required fields
        if (empty($post_data['pickup_zip']) || empty($post_data['delivery_zip']) || 
            empty($post_data['trailer_type']) || empty($post_data['vehicle_type'])) {
            return new WP_Error('missing_fields', 'Todos los campos marcados con * son requeridos.');
        }

        // Validate ZIP codes
        if (!preg_match('/^\d{5}$/', $post_data['pickup_zip'])) {
            return new WP_Error('invalid_zip', 'El cÃƒÂ³digo postal de origen debe tener 5 dÃƒÂ­gitos.');
        }

        if (!preg_match('/^\d{5}$/', $post_data['delivery_zip'])) {
            return new WP_Error('invalid_zip', 'El cÃƒÂ³digo postal de destino debe tener 5 dÃƒÂ­gitos.');
        }

        $form_data['pickup']['zip'] = sanitize_text_field($post_data['pickup_zip']);
        $form_data['delivery']['zip'] = sanitize_text_field($post_data['delivery_zip']);
        $form_data['trailer_type'] = sanitize_text_field($post_data['trailer_type']);

        // Vehicle data - all fields are required according to API docs
        $form_data['vehicles'][0] = array(
            'type' => sanitize_text_field($post_data['vehicle_type']),
            'is_inoperable' => !empty($post_data['vehicle_inoperable']),
            'make' => !empty($post_data['vehicle_make']) ? sanitize_text_field($post_data['vehicle_make']) : '',
            'model' => !empty($post_data['vehicle_model']) ? sanitize_text_field($post_data['vehicle_model']) : '',
            'year' => !empty($post_data['vehicle_year']) ? (string)intval($post_data['vehicle_year']) : ''
        );

        return $form_data;
    }

    /**
     * Show results after form submission
     */
    private function show_results() {
        $result = $_SESSION['sdpi_result'];
        ?>
        <div class="sdpi-results <?php echo $result['success'] ? 'success' : 'error'; ?>">
            <h3><?php echo $result['success'] ? 'Cotizacion lista' : 'Error'; ?></h3>
            <p><?php echo esc_html($result['message']); ?></p>
        </div>
        <?php
    }

    /**
     * AJAX handler for initiating payment
     */
    public function ajax_initiate_payment() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        if (!$this->is_authorize_ready()) {
            wp_send_json_error('La pasarela de pago no está configurada.');
            exit;
        }

        if (!$this->is_request_secure()) {
            wp_send_json_error('La pasarela de pago requiere que el sitio utilice HTTPS.');
            exit;
        }

        $posted_quote = isset($_POST['quote_data']) ? json_decode(stripslashes($_POST['quote_data']), true) : array();
        if (!is_array($posted_quote)) {
            $posted_quote = array();
        }

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        if (!$session_id) {
            wp_send_json_error('No se pudo iniciar la sesión de pago. Actualiza la página e inténtalo nuevamente.');
            exit;
        }

        $session_row = $session->get($session_id);
        $stored_quote = array();
        if ($session_row && isset($session_row['data']['quote']) && is_array($session_row['data']['quote'])) {
            $stored_quote = $session_row['data']['quote'];
        }

        if (!empty($posted_quote)) {
            $stored_quote = array_merge($stored_quote, $posted_quote);
        }

        if (empty($stored_quote) || !isset($stored_quote['final_price'])) {
            wp_send_json_error('No se encontró información válida de la cotización.');
            exit;
        }

        $amount = floatval($stored_quote['final_price']);
        if ($amount <= 0) {
            wp_send_json_error('El monto de la transacción es inválido.');
            exit;
        }

        $stored_quote['final_price'] = number_format($amount, 2, '.', '');

        $session->update_data($session_id, array('quote' => $stored_quote));
        $session->set_status($session_id, 'checkout');

        // Update history to 'checkout' status
        $history = new SDPI_History();
        $history->update_to_checkout($session_id);

        $client_info = array();
        if ($session_row && isset($session_row['data']['client_info']) && is_array($session_row['data']['client_info'])) {
            $client_info = $session_row['data']['client_info'];
        } elseif (!session_id()) {
            session_start();
        }

        if (empty($client_info) && isset($_SESSION['sdpi_client_info'])) {
            $client_info = $_SESSION['sdpi_client_info'];
        }

        $response = array(
            'session_id' => $session_id,
            'amount' => number_format($amount, 2, '.', ''),
            'amount_numeric' => round($amount, 2),
            'currency' => 'USD',
            'description' => $this->build_payment_description($stored_quote),
            'customer' => array(
                'name' => $client_info['name'] ?? '',
                'email' => $client_info['email'] ?? '',
                'phone' => $client_info['phone'] ?? ''
            ),
            'payment_available' => true
        );

        wp_send_json_success($response);
        exit;
    }

    /**
     * AJAX handler for processing Authorize.net payments
     */
    public function ajax_process_payment() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        if (!$this->is_authorize_ready()) {
            wp_send_json_error('La pasarela de pago no está configurada.');
            exit;
        }

        if (!$this->is_request_secure()) {
            wp_send_json_error('La pasarela de pago requiere una conexión HTTPS.');
            exit;
        }

        $card_number = isset($_POST['card_number']) ? preg_replace('/\D/', '', $_POST['card_number']) : '';
        $card_exp_month = isset($_POST['card_exp_month']) ? preg_replace('/\D/', '', $_POST['card_exp_month']) : '';
        $card_exp_year = isset($_POST['card_exp_year']) ? preg_replace('/\D/', '', $_POST['card_exp_year']) : '';
        $card_cvv = isset($_POST['card_cvv']) ? preg_replace('/\D/', '', $_POST['card_cvv']) : '';
        $card_zip = isset($_POST['card_zip']) ? sanitize_text_field($_POST['card_zip']) : '';

        if (empty($card_number) || strlen($card_number) < 13 || strlen($card_number) > 19) {
            wp_send_json_error('Número de tarjeta inválido.');
            exit;
        }

        if (empty($card_exp_month) || empty($card_exp_year)) {
            wp_send_json_error('Fecha de expiración inválida.');
            exit;
        }

        $card_exp_month = str_pad(substr($card_exp_month, -2), 2, '0', STR_PAD_LEFT);
        $card_exp_year = strlen($card_exp_year) === 2 ? ('20' . $card_exp_year) : $card_exp_year;
        if (!preg_match('/^20\d{2}$/', $card_exp_year)) {
            wp_send_json_error('Año de expiración inválido.');
            exit;
        }

        if (empty($card_cvv) || strlen($card_cvv) < 3 || strlen($card_cvv) > 4) {
            wp_send_json_error('Código de seguridad inválido.');
            exit;
        }

        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        if (!$session_id) {
            wp_send_json_error('Sesión de pago no encontrada. Actualiza la página e inténtalo nuevamente.');
            exit;
        }

        $session_row = $session->get($session_id);
        $session_data = is_array($session_row) ? ($session_row['data'] ?? array()) : array();
        $quote_data = isset($session_data['quote']) && is_array($session_data['quote']) ? $session_data['quote'] : array();

        if (empty($quote_data) || !isset($quote_data['final_price'])) {
            wp_send_json_error('No se encontró la cotización asociada al pago.');
            exit;
        }

        $amount = floatval($quote_data['final_price']);
        if ($amount <= 0) {
            wp_send_json_error('El monto de la transacción es inválido.');
            exit;
        }

        $api_login_id = get_option('sdpi_authorize_api_login_id');
        $transaction_key = get_option('sdpi_authorize_transaction_key');

        $client_info = isset($session_data['client_info']) && is_array($session_data['client_info'])
            ? $session_data['client_info']
            : array();
        if (empty($client_info) && isset($_SESSION['sdpi_client_info'])) {
            $client_info = $_SESSION['sdpi_client_info'];
        }

        $shipping_details = isset($session_data['shipping']) && is_array($session_data['shipping'])
            ? $session_data['shipping']
            : array();

        list($first_name, $last_name) = $this->split_full_name($client_info['name'] ?? '');

        $billing = array(
            'firstName' => $first_name,
            'lastName' => $last_name
        );

        if (!empty($client_info['phone'])) {
            $billing['phoneNumber'] = sanitize_text_field($client_info['phone']);
        }

        if (isset($shipping_details['pickup'])) {
            $pickup = $shipping_details['pickup'];
            if (!empty($pickup['street'])) {
                $billing['address'] = sanitize_text_field($pickup['street']);
            }
            if (!empty($pickup['city'])) {
                $billing['city'] = sanitize_text_field($pickup['city']);
            }
            if (!empty($pickup['zip'])) {
                $billing['zip'] = sanitize_text_field($pickup['zip']);
            }
            if (!empty($pickup['country'])) {
                $billing['country'] = sanitize_text_field($pickup['country']);
            }
        }

        if (empty($billing['country'])) {
            $billing['country'] = 'US';
        }

        $invoice = substr(preg_replace('/[^A-Za-z0-9]/', '', $session_id), 0, 20);
        $description = $this->build_payment_description($quote_data);

        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($api_login_id);
        $merchantAuthentication->setTransactionKey($transaction_key);

        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($card_number);
        $creditCard->setExpirationDate($card_exp_year . '-' . $card_exp_month);
        $creditCard->setCardCode($card_cvv);

        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber($invoice);
        $order->setDescription($description);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType('authCaptureTransaction');
        $transactionRequest->setAmount(number_format($amount, 2, '.', ''));
        $transactionRequest->setPayment($paymentOne);
        $transactionRequest->setOrder($order);

        $billTo = new AnetAPI\CustomerAddressType();
        $billTo->setFirstName($first_name);
        $billTo->setLastName($last_name);
        if (!empty($billing['address'])) {
            $billTo->setAddress($billing['address']);
        }
        if (!empty($billing['city'])) {
            $billTo->setCity($billing['city']);
        }
        if (!empty($billing['zip'])) {
            $billTo->setZip($billing['zip']);
        }
        if (!empty($billing['country'])) {
            $billTo->setCountry($billing['country']);
        }
        $transactionRequest->setBillTo($billTo);

        if (!empty($client_info['email'])) {
            $customerData = new AnetAPI\CustomerDataType();
            $customerData->setEmail(sanitize_email($client_info['email']));
            $customerData->setId($invoice);
            $transactionRequest->setCustomer($customerData);
        }

        if (!empty($card_zip)) {
            $shipTo = new AnetAPI\NameAndAddressType();
            $shipTo->setFirstName($first_name);
            $shipTo->setLastName($last_name);
            $shipTo->setZip($card_zip);
            $transactionRequest->setShipTo($shipTo);
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $transactionRequest->setCustomerIP(sanitize_text_field($_SERVER['REMOTE_ADDR']));
        }

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($invoice);
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);
        $environment = get_option('sdpi_authorize_environment', 'sandbox') === 'production'
            ? ANetEnvironment::PRODUCTION
            : ANetEnvironment::SANDBOX;
        $response = $controller->executeWithApiResponse($environment);

        if ($response === null) {
            wp_send_json_error('No se recibió respuesta de Authorize.net. Intente nuevamente.');
            exit;
        }

        if ($response->getMessages()->getResultCode() !== 'Ok') {
            $error_message = 'Transacción rechazada.';
            if ($response->getMessages()->getMessage()) {
                $error_message = sanitize_text_field($response->getMessages()->getMessage()[0]->getText());
            }

            $error_url = get_option('sdpi_payment_error_url');
            wp_send_json_error(array(
                'message' => $error_message,
                'redirect_url' => !empty($error_url) ? esc_url($error_url) : ''
            ));
            exit;
        }

        $tResponse = $response->getTransactionResponse();
        if (!$tResponse || $tResponse->getResponseCode() !== '1') {
            $error_message = 'Transacción rechazada.';
            if ($tResponse && $tResponse->getErrors()) {
                $error_message = sanitize_text_field($tResponse->getErrors()[0]->getErrorText());
            }

            $error_url = get_option('sdpi_payment_error_url');
            wp_send_json_error(array(
                'message' => $error_message,
                'redirect_url' => !empty($error_url) ? esc_url($error_url) : ''
            ));
            exit;
        }

        $transaction_id = sanitize_text_field($tResponse->getTransId());
        $auth_code = sanitize_text_field($tResponse->getAuthCode());

        $session->set_status($session_id, 'completado');
        $session->update_data($session_id, array(
            'payment' => array(
                'transaction_id' => $transaction_id,
                'auth_code' => $auth_code,
                'status' => 'approved',
                'processed_at' => current_time('mysql')
            )
        ));

        $history = new SDPI_History();
        $history->update_to_completado($session_id);

        $extra_data = array(
            'client' => $client_info,
            'shipping' => $shipping_details,
            'maritime_details' => isset($session_data['maritime']) ? $session_data['maritime'] : ($session_data['maritime_details'] ?? ($quote_data['maritime_details'] ?? array())),
            'transport_type' => isset($session_data['transport_type']) ? $session_data['transport_type'] : (!empty($quote_data['maritime_involved']) ? 'maritime' : 'terrestrial'),
            'payment' => array(
                'transaction_id' => $transaction_id,
                'auth_code' => $auth_code
            )
        );

        $this->dispatch_zapier_update($session_id, $quote_data, $extra_data, true);

        $success_url = get_option('sdpi_payment_success_url');

        wp_send_json_success(array(
            'transaction_id' => $transaction_id,
            'auth_code' => $auth_code,
            'redirect_url' => !empty($success_url) ? esc_url($success_url) : '',
            'message' => 'Pago procesado exitosamente.'
        ));
        exit;
    }
    /**
     * Send quote data to Zapier webhook
     */
    private function send_to_zapier($pickup_zip, $delivery_zip, $trailer_type, $vehicle_type, $vehicle_inoperable, $vehicle_electric, $vehicle_make, $vehicle_model, $vehicle_year, $final_price_data, $involves_maritime, $extra_data = array()) {
        if (!session_id()) {
            session_start();
        }

        $extra_data = is_array($extra_data) ? $extra_data : array();

        $client_info = isset($extra_data['client']) && is_array($extra_data['client']) ? $extra_data['client'] : array();
        if (empty($client_info) && isset($_SESSION['sdpi_client_info'])) {
            $client_info = $_SESSION['sdpi_client_info'];
        }

        $shipping_details = array();
        if (!empty($extra_data['shipping']) && is_array($extra_data['shipping'])) {
            $shipping_details = $extra_data['shipping'];
        } elseif (isset($_SESSION['sdpi_additional_info']) && is_array($_SESSION['sdpi_additional_info'])) {
            $shipping_details = array(
                'pickup' => array(
                    'name' => isset($_SESSION['sdpi_additional_info']['p_name']) ? $_SESSION['sdpi_additional_info']['p_name'] : '',
                    'street' => isset($_SESSION['sdpi_additional_info']['p_street']) ? $_SESSION['sdpi_additional_info']['p_street'] : '',
                    'city' => isset($_SESSION['sdpi_additional_info']['p_city']) ? $_SESSION['sdpi_additional_info']['p_city'] : '',
                    'zip' => isset($_SESSION['sdpi_additional_info']['p_zip']) ? $_SESSION['sdpi_additional_info']['p_zip'] : '',
                    'type' => isset($_SESSION['sdpi_additional_info']['pickup_type']) ? $_SESSION['sdpi_additional_info']['pickup_type'] : ''
                ),
                'delivery' => array(
                    'name' => isset($_SESSION['sdpi_additional_info']['d_name']) ? $_SESSION['sdpi_additional_info']['d_name'] : '',
                    'street' => isset($_SESSION['sdpi_additional_info']['d_street']) ? $_SESSION['sdpi_additional_info']['d_street'] : '',
                    'city' => isset($_SESSION['sdpi_additional_info']['d_city']) ? $_SESSION['sdpi_additional_info']['d_city'] : '',
                    'zip' => isset($_SESSION['sdpi_additional_info']['d_zip']) ? $_SESSION['sdpi_additional_info']['d_zip'] : ''
                )
            );
        } elseif (isset($_SESSION['sdpi_maritime_info']) && is_array($_SESSION['sdpi_maritime_info'])) {
            $maritime_info = $_SESSION['sdpi_maritime_info'];
            $shipping_details = array(
                'pickup' => array(
                    'name' => isset($maritime_info['shipper']['name']) ? $maritime_info['shipper']['name'] : '',
                    'street' => isset($maritime_info['shipper']['street']) ? $maritime_info['shipper']['street'] : '',
                    'city' => isset($maritime_info['shipper']['city']) ? $maritime_info['shipper']['city'] : '',
                    'zip' => isset($maritime_info['shipper']['zip']) ? $maritime_info['shipper']['zip'] : '',
                    'type' => isset($maritime_info['direction']) ? ucwords(str_replace('_', ' ', $maritime_info['direction'])) : 'Maritimo'
                ),
                'delivery' => array(
                    'name' => isset($maritime_info['consignee']['name']) ? $maritime_info['consignee']['name'] : '',
                    'street' => isset($maritime_info['consignee']['street']) ? $maritime_info['consignee']['street'] : '',
                    'city' => isset($maritime_info['consignee']['city']) ? $maritime_info['consignee']['city'] : '',
                    'zip' => isset($maritime_info['consignee']['zip']) ? $maritime_info['consignee']['zip'] : ''
                ),
                'saved_at' => isset($maritime_info['saved_at']) ? $maritime_info['saved_at'] : current_time('mysql')
            );
        }

        $maritime_details = array();
        if (!empty($extra_data['maritime_details']) && is_array($extra_data['maritime_details'])) {
            $maritime_details = $extra_data['maritime_details'];
        } elseif (isset($_SESSION['sdpi_maritime_info']) && is_array($_SESSION['sdpi_maritime_info'])) {
            $maritime_details = $_SESSION['sdpi_maritime_info'];
        }

        $documentation_files = $this->documentation_files_to_list(
            $this->normalize_documentation_files($extra_data['documentation_files'] ?? array())
        );

        $transport_type = isset($extra_data['transport_type']) ? $extra_data['transport_type'] : ($involves_maritime ? 'maritime' : 'terrestrial');
        $session_identifier = isset($extra_data['session_id']) ? $extra_data['session_id'] : '';

        // Get cities from ZIPs
        $pickup_city = $this->get_city_from_zip($pickup_zip);
        $delivery_city = $this->get_city_from_zip($delivery_zip);
        if (!$pickup_city && isset($shipping_details['pickup']['city'])) {
            $pickup_city = $shipping_details['pickup']['city'];
        }
        if (!$delivery_city && isset($shipping_details['delivery']['city'])) {
            $delivery_city = $shipping_details['delivery']['city'];
        }

        $zapier_data = array(
            'client_name' => isset($client_info['name']) ? $client_info['name'] : '',
            'client_email' => isset($client_info['email']) ? $client_info['email'] : '',
            'client_phone' => isset($client_info['phone']) ? $client_info['phone'] : '',
            'client_info_captured_at' => isset($client_info['captured_at']) ? $client_info['captured_at'] : null,
            'pickup_city' => $pickup_city,
            'pickup_zip' => $pickup_zip,
            'delivery_city' => $delivery_city,
            'delivery_zip' => $delivery_zip,
            'trailer_type' => $trailer_type,
            'vehicle_type' => $vehicle_type,
            'vehicle_inoperable' => $vehicle_inoperable,
            'vehicle_electric' => $vehicle_electric,
            'vehicle_make' => $vehicle_make,
            'vehicle_model' => $vehicle_model,
            'vehicle_year' => $vehicle_year,
            'final_price' => floatval($final_price_data['final_price']),
            'base_price' => isset($final_price_data['base_price']) ? floatval($final_price_data['base_price']) : 0,
            'confidence_percentage' => isset($final_price_data['confidence_percentage']) ? floatval($final_price_data['confidence_percentage']) : 0,
            'company_profit' => isset($final_price_data['company_profit']) ? floatval($final_price_data['company_profit']) : 0,
            'confidence_adjustment' => isset($final_price_data['confidence_adjustment']) ? floatval($final_price_data['confidence_adjustment']) : 0,
            'maritime_involved' => $involves_maritime,
            'maritime_cost' => isset($final_price_data['maritime_cost']) ? floatval($final_price_data['maritime_cost']) : 0,
            'terrestrial_cost' => isset($final_price_data['terrestrial_cost']) ? floatval($final_price_data['terrestrial_cost']) : 0,
            'us_port' => isset($final_price_data['us_port']) ? $final_price_data['us_port'] : null,
            'transport_type' => $transport_type,
            'session_id' => $session_identifier,
            'quote_date' => current_time('mysql'),
            'quote_timestamp' => current_time('timestamp'),
            'payment_available' => $this->is_authorize_ready()
        );

        if (!empty($shipping_details)) {
            $zapier_data['shipping_details'] = $shipping_details;
            $zapier_data['pickup_contact_name'] = isset($shipping_details['pickup']['name']) ? $shipping_details['pickup']['name'] : '';
            $zapier_data['pickup_contact_street'] = isset($shipping_details['pickup']['street']) ? $shipping_details['pickup']['street'] : '';
            $zapier_data['pickup_contact_city'] = isset($shipping_details['pickup']['city']) ? $shipping_details['pickup']['city'] : '';
            $zapier_data['pickup_contact_zip'] = isset($shipping_details['pickup']['zip']) ? $shipping_details['pickup']['zip'] : '';
            $zapier_data['pickup_contact_type'] = isset($shipping_details['pickup']['type']) ? $shipping_details['pickup']['type'] : '';
            $zapier_data['delivery_contact_name'] = isset($shipping_details['delivery']['name']) ? $shipping_details['delivery']['name'] : '';
            $zapier_data['delivery_contact_street'] = isset($shipping_details['delivery']['street']) ? $shipping_details['delivery']['street'] : '';
            $zapier_data['delivery_contact_city'] = isset($shipping_details['delivery']['city']) ? $shipping_details['delivery']['city'] : '';
            $zapier_data['delivery_contact_zip'] = isset($shipping_details['delivery']['zip']) ? $shipping_details['delivery']['zip'] : '';
            if (isset($shipping_details['saved_at'])) {
                $zapier_data['shipping_saved_at'] = $shipping_details['saved_at'];
            }
        }

        if (!empty($maritime_details)) {
            $zapier_data['maritime_details'] = $maritime_details;
        }

        if (!empty($documentation_files)) {
            $zapier_data['documentation_files'] = $documentation_files;
            $zapier_data['documentation_file_urls'] = array_map(function($file) {
                return isset($file['url']) ? $file['url'] : '';
            }, $documentation_files);
            $zapier_data['documentation_files_count'] = count($documentation_files);
        }

        // Get Zapier webhook URL from settings
        $zapier_webhook_url = get_option('sdpi_zapier_webhook_url');

        // Skip if Zapier integration is not configured
        if (empty($zapier_webhook_url)) {
            return;
        }

        // Send data to Zapier (non-blocking)
        $response = wp_remote_post($zapier_webhook_url, array(
            'body' => json_encode($zapier_data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'SDPI Plugin/1.0'
            ),
            'timeout' => 5, // 5 second timeout
            'blocking' => false // Non-blocking request
        ));

        // Log any errors (only in debug mode)
        if (is_wp_error($response) && WP_DEBUG) {
            error_log('SDPI Zapier Error: ' . $response->get_error_message());
        } elseif (WP_DEBUG) {
            error_log('SDPI Zapier Success: Data sent to webhook');
        }
    }

}












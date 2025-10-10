<?php
/**
 * Custom form handler for Super Dispatch Pricing Insights - FIXED VERSION
 */

class SDPI_Form {

    private $api;

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

        // Add AJAX handler for additional shipping info
        add_action('wp_ajax_sdpi_save_additional_info', array($this, 'ajax_save_additional_info'));
        add_action('wp_ajax_nopriv_sdpi_save_additional_info', array($this, 'ajax_save_additional_info'));

        // Add AJAX handler for maritime additional info
        add_action('wp_ajax_sdpi_save_maritime_info', array($this, 'ajax_save_maritime_info'));
        add_action('wp_ajax_nopriv_sdpi_save_maritime_info', array($this, 'ajax_save_maritime_info'));

        // WooCommerce payment completion hook
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'));

        // Add AJAX handler for client info capture
        add_action('wp_ajax_sdpi_save_client_info', array($this, 'ajax_save_client_info'));
        add_action('wp_ajax_nopriv_sdpi_save_client_info', array($this, 'ajax_save_client_info'));

        // Add new AJAX handler for finalizing quote with contact info
        add_action('wp_ajax_sdpi_finalize_quote_with_contact', array($this, 'ajax_finalize_quote_with_contact'));
        add_action('wp_ajax_nopriv_sdpi_finalize_quote_with_contact', array($this, 'ajax_finalize_quote_with_contact'));

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
        // Enqueue form script
        wp_enqueue_script(
            'sdpi-form-script',
            plugin_dir_url(__FILE__) . '../assets/form-script.js',
            array('jquery'),
            SDPI_VERSION,
            true
        );
        
        wp_localize_script('sdpi-form-script', 'sdpi_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sdpi_nonce'),
            'loading_text' => 'Obteniendo cotización...',
            'error_text' => 'Error al obtener cotización'
        ));
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

    /**
     * Render the pricing form
     */
    public function render_form() {
        // Enqueue scripts and styles when form is rendered
        $this->enqueue_styles();
        $this->enqueue_scripts();

        // MODIFICADO: Ya no verificamos datos de contacto al inicio
        // El formulario de cotización se muestra directamente

        ob_start();
        ?>
        <div class="sdpi-pricing-form">
            <form method="post" class="sdpi-form" id="sdpi-pricing-form">
                <input type="hidden" name="sdpi_form_submit" value="1">
                <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_form_nonce'); ?>">
                
                <div class="sdpi-form-section">
                    <h3>Información de Envío</h3>
                    
                    <div class="sdpi-form-group">
                        <label for="sdpi_pickup_city">Ciudad de Origen *</label>
                        <div class="sdpi-search-container">
                            <input type="text" id="sdpi_pickup_city" name="pickup_city" 
                                   placeholder="Buscar ciudad o código postal..." required autocomplete="off">
                            <div class="sdpi-search-results" id="pickup-results"></div>
                        </div>
                        <input type="hidden" id="sdpi_pickup_zip" name="pickup_zip">
                        <small class="sdpi-field-help">Escriba para buscar ciudades o códigos postales</small>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_delivery_city">Ciudad de Destino *</label>
                        <div class="sdpi-search-container">
                            <input type="text" id="sdpi_delivery_city" name="delivery_city" 
                                   placeholder="Buscar ciudad o código postal..." required autocomplete="off">
                            <div class="sdpi-search-results" id="delivery-results"></div>
                        </div>
                        <input type="hidden" id="sdpi_delivery_zip" name="delivery_zip">
                        <small class="sdpi-field-help">Escriba para buscar ciudades o códigos postales</small>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_trailer_type">Tipo de Tráiler *</label>
                        <select id="sdpi_trailer_type" name="trailer_type" required>
                            <option value="">Seleccione...</option>
                            <option value="open">Abierto</option>
                            <option value="enclosed">Cerrado</option>
                        </select>
                    </div>
                </div>

                <div class="sdpi-form-section">
                    <h3>Información del Vehículo</h3>
                    
                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_type">Tipo de Vehículo *</label>
                        <select id="sdpi_vehicle_type" name="vehicle_type" required>
                            <option value="">Seleccione...</option>
                            <option value="sedan">Sedán</option>
                            <option value="suv">SUV</option>
                            <option value="van">Van</option>
                            <option value="coupe_2_doors">Coupé 2 Puertas</option>
                            <option value="pickup_2_doors">Pickup 2 Puertas</option>
                            <option value="pickup_4_doors">Pickup 4 Puertas</option>
                        </select>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_inoperable">
                            <input type="checkbox" id="sdpi_vehicle_inoperable" name="vehicle_inoperable" value="1">
                            ¿El vehículo no funciona? *
                        </label>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_electric">
                            <input type="checkbox" id="sdpi_vehicle_electric" name="vehicle_electric" value="1">
                            ¿Es un vehículo eléctrico?
                        </label>
                        <small class="sdpi-field-help">Se agregarán $600 USD adicionales al precio total</small>
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_make">Marca del Vehículo</label>
                        <input type="text" id="sdpi_vehicle_make" name="vehicle_make"
                               placeholder="Ej: Toyota, Ford, Chevrolet">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_model">Modelo del Vehículo</label>
                        <input type="text" id="sdpi_vehicle_model" name="vehicle_model" 
                               placeholder="Ej: Camry, Focus, Silverado">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_vehicle_year">Año del Vehículo</label>
                        <input type="number" id="sdpi_vehicle_year" name="vehicle_year" 
                               min="1900" max="<?php echo date('Y'); ?>" 
                               placeholder="Ej: 2020">
                    </div>
                </div>

                <div class="sdpi-form-submit">
                    <button type="submit" class="sdpi-submit-btn" id="sdpi-submit-btn">Obtener Cotización</button>
                </div>
            </form>

            <!-- Results container -->
            <div id="sdpi-results" class="sdpi-results" style="display: none;">
                <h3 id="sdpi-result-title">Resultado</h3>
                <p id="sdpi-result-message"></p>
                <div id="sdpi-result-details" style="display: none;">
                    <p><strong>Precio Recomendado:</strong> <span id="sdpi-price"></span></p>
                    <p><strong>Nivel de Confianza:</strong> <span id="sdpi-confidence"></span></p>
                </div>
            </div>

            <!-- Loading indicator -->
            <div id="sdpi-loading" class="sdpi-loading" style="display: none;">
                <p>Obteniendo cotización...</p>
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
     * Render a secondary screen to capture additional shipping info before checkout
     * This markup will be toggled from JS when user clicks Pay
     */
    private function render_additional_info_screen() {
        ?>
        <div id="sdpi-additional-info" class="sdpi-client-info-form" style="display:none;">
            <div class="sdpi-form-section">
                <h3>Información adicional para el envío</h3>
                <p>Revise los datos de la cotización y complete la información de recogida y entrega antes de continuar al pago.</p>

                <!-- Resumen no editable de la cotización previa -->
                <div class="sdpi-price-breakdown" id="sdpi-additional-summary">
                    <h4>Resumen de la Cotización</h4>
                    <div class="sdpi-price-item"><span class="sdpi-price-label">Origen:</span><span class="sdpi-price-value" id="sdpi-summary-pickup"></span></div>
                    <div class="sdpi-price-item"><span class="sdpi-price-label">Destino:</span><span class="sdpi-price-value" id="sdpi-summary-delivery"></span></div>
                    <div class="sdpi-price-item"><span class="sdpi-price-label">Tipo de Tráiler:</span><span class="sdpi-price-value" id="sdpi-summary-trailer"></span></div>
                    <div class="sdpi-price-item"><span class="sdpi-price-label">Vehículo:</span><span class="sdpi-price-value" id="sdpi-summary-vehicle"></span></div>
                    <div class="sdpi-price-item" id="sdpi-summary-transport-type-row" style="display:none;"><span class="sdpi-price-label">Tipo de Transporte:</span><span class="sdpi-price-value" id="sdpi-summary-transport-type"></span></div>
                    <div class="sdpi-price-item sdpi-price-total"><span class="sdpi-price-label">Precio Final:</span><span class="sdpi-price-value" id="sdpi-summary-price"></span></div>
                </div>

                <!-- Formulario adicional para transporte TERRESTRE -->
                <form id="sdpi-additional-info-form" class="sdpi-terrestrial-form">
                    <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">
                    <input type="hidden" id="sdpi_transport_type" name="transport_type" value="terrestrial">

                    <div class="sdpi-form-section">
                        <h3>Datos del vehículo</h3>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_vehicle_year">Año</label>
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

                    <div class="sdpi-form-section">
                        <h3>Información de recogida</h3>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_p_name">Nombre de quien entrega *</label>
                            <input type="text" id="sdpi_ai_p_name" required>
                        </div>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_p_street">Dirección de recogida *</label>
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

                    <div class="sdpi-form-section">
                        <h3>Información de entrega</h3>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_d_name">Nombre de quien recibe *</label>
                            <input type="text" id="sdpi_ai_d_name" required>
                        </div>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_d_street">Dirección de entrega *</label>
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

                    <div class="sdpi-form-section">
                        <h3>Tipo de recogida</h3>
                        <div class="sdpi-form-group">
                            <label for="sdpi_ai_pickup_type">Seleccione una opción *</label>
                            <select id="sdpi_ai_pickup_type" required>
                                <option value="">Seleccione...</option>
                                <option value="Subasta">Subasta</option>
                                <option value="Residencia">Residencia</option>
                                <option value="Dealer o negocio">Dealer o negocio</option>
                            </select>
                        </div>
                    </div>

                    <div class="sdpi-form-submit">
                        <button type="button" class="sdpi-submit-btn" id="sdpi-ai-continue">Continuar al Pago</button>
                        <button type="button" class="sdpi-clear-btn" id="sdpi-ai-cancel">Cancelar</button>
                    </div>
                </form>

                <!-- Formulario adicional para transporte MARÍTIMO -->
                <form id="sdpi-maritime-info-form" class="sdpi-maritime-form" style="display:none;">
                    <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">
                    <input type="hidden" id="sdpi_maritime_direction" name="maritime_direction" value="">

                    <!-- Vehicle Information (No editable) -->
                    <div class="sdpi-form-section sdpi-maritime-vehicle-section">
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
                                <select id="sdpi_m_vehicle_conditions" required>
                                    <option value="">Select...</option>
                                    <option value="Running">Running</option>
                                    <option value="Non-Running">Non-Running</option>
                                </select>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_m_fuel_type">Fuel Type *</label>
                                <select id="sdpi_m_fuel_type" required>
                                    <option value="">Select...</option>
                                    <option value="Gasoline">Gasoline</option>
                                    <option value="Diesel">Diesel</option>
                                    <option value="Electric">Electric</option>
                                    <option value="Hybrid">Hybrid</option>
                                </select>
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
                                <label for="sdpi_m_dimensions">Dimensions LxWxH (Optional)</label>
                                <input type="text" id="sdpi_m_dimensions" placeholder="e.g., 15x6x5 ft">
                            </div>
                        </div>
                    </div>

                    <!-- Shipper Information -->
                    <div class="sdpi-form-section sdpi-maritime-overseas-section sdpi-maritime-shipper-section">
                        <h3>Shipper Information</h3>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-2">
                                <label for="sdpi_s_name">S. Name *</label>
                                <input type="text" id="sdpi_s_name" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-2">
                                <label for="sdpi_s_street">S. Street *</label>
                                <input type="text" id="sdpi_s_street" required>
                            </div>
                        </div>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_city">S. City *</label>
                                <input type="text" id="sdpi_s_city" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_state">S. State *</label>
                                <input type="text" id="sdpi_s_state" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_country">S. Country *</label>
                                <select id="sdpi_s_country" required>
                                    <option value="">Select...</option>
                                    <option value="USA">USA</option>
                                    <option value="Puerto Rico">Puerto Rico</option>
                                </select>
                            </div>
                        </div>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_zip">S. Zip Code *</label>
                                <input type="text" id="sdpi_s_zip" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_phone1">S. Phone 1 *</label>
                                <input type="tel" id="sdpi_s_phone1" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_s_phone2">S. Phone 2 (Optional)</label>
                                <input type="tel" id="sdpi_s_phone2">
                            </div>
                        </div>
                    </div>

                    <!-- Consignee Information -->
                    <div class="sdpi-form-section sdpi-maritime-overseas-section sdpi-maritime-consignee-section">
                        <h3>Consignee Information</h3>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-2">
                                <label for="sdpi_c_name">C. Name *</label>
                                <input type="text" id="sdpi_c_name" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-2">
                                <label for="sdpi_c_street">C. Street *</label>
                                <input type="text" id="sdpi_c_street" required>
                            </div>
                        </div>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_city">C. City *</label>
                                <input type="text" id="sdpi_c_city" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_state">C. State *</label>
                                <input type="text" id="sdpi_c_state" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_country">C. Country *</label>
                                <select id="sdpi_c_country" required>
                                    <option value="">Select...</option>
                                    <option value="USA">USA</option>
                                    <option value="Puerto Rico">Puerto Rico</option>
                                </select>
                            </div>
                        </div>
                        <div class="sdpi-form-row">
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_zip">C. Zip Code *</label>
                                <input type="text" id="sdpi_c_zip" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_phone1">C. Phone 1 *</label>
                                <input type="tel" id="sdpi_c_phone1" required>
                            </div>
                            <div class="sdpi-form-group sdpi-col-3">
                                <label for="sdpi_c_phone2">C. Phone 2 (Optional)</label>
                                <input type="tel" id="sdpi_c_phone2">
                            </div>
                        </div>
                    </div>

                    <!-- Pick Up Information (Solo si es USA -> PR) -->
                    <div class="sdpi-form-section sdpi-maritime-pickup-section" id="sdpi-pickup-section" style="display:none;">
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

                    <!-- Drop Off Information (Solo si es PR -> USA) -->
                    <div class="sdpi-form-section sdpi-maritime-dropoff-section" id="sdpi-dropoff-section" style="display:none;">
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

                    <!-- Others (Optional) -->
                    <div class="sdpi-form-section">
                        <h3>Others (Optional)</h3>
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
                    </div>

                    <div class="sdpi-form-submit">
                        <button type="button" class="sdpi-submit-btn" id="sdpi-maritime-continue">Continue to Payment</button>
                        <button type="button" class="sdpi-clear-btn" id="sdpi-maritime-cancel">Cancel</button>
                    </div>
                </form>
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
                <h3>Información de Contacto</h3>
                <p>Para poder contactarlo en caso de que necesite asistencia con su cotización o proceso de envío, por favor complete la siguiente información:</p>

                <form method="post" class="sdpi-form" id="sdpi-registration-form">
                    <input type="hidden" name="sdpi_registration_submit" value="1">
                    <input type="hidden" name="sdpi_nonce" value="<?php echo wp_create_nonce('sdpi_nonce'); ?>">

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_name">Nombre Completo *</label>
                        <input type="text" id="sdpi_user_name" name="user_name" required
                               placeholder="Ej: Juan Pérez">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_phone">Número de Teléfono *</label>
                        <input type="tel" id="sdpi_user_phone" name="user_phone" required
                               placeholder="Ej: (787) 123-4567" pattern="[0-9\(\)\-\+\s]+">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_user_email">Correo Electrónico *</label>
                        <input type="email" id="sdpi_user_email" name="user_email" required
                               placeholder="Ej: juan@email.com">
                    </div>

                    <div class="sdpi-form-submit">
                        <button type="submit" class="sdpi-submit-btn" id="sdpi-client-info-btn">Continuar con la Cotización</button>
                    </div>
                </form>
            </div>

            <!-- Client info results container -->
            <div id="sdpi-client-info-results" class="sdpi-results" style="display: none;">
                <h3 id="sdpi-client-info-title">Información Guardada</h3>
                <p id="sdpi-client-info-message"></p>
            </div>

            <!-- Loading indicator -->
            <div id="sdpi-client-info-loading" class="sdpi-loading" style="display: none;">
                <p>Guardando información...</p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Handle client info form submission
            $('#sdpi-registration-form').on('submit', function(e) {
                e.preventDefault();

                var formData = {
                    action: 'sdpi_save_client_info',
                    nonce: $('input[name="sdpi_nonce"]').val(),
                    client_name: $('#sdpi_user_name').val().trim(),
                    client_phone: $('#sdpi_user_phone').val().trim(),
                    client_email: $('#sdpi_user_email').val().trim()
                };

                // Basic validation
                if (!formData.client_name || !formData.client_phone || !formData.client_email) {
                    $('#sdpi-client-info-title').text('Error');
                    $('#sdpi-client-info-message').text('Todos los campos son requeridos.');
                    $('#sdpi-client-info-results').removeClass('success').addClass('error').show();
                    return;
                }

                // Email validation
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(formData.client_email)) {
                    $('#sdpi-client-info-title').text('Error');
                    $('#sdpi-client-info-message').text('Por favor ingrese un correo electrónico válido.');
                    $('#sdpi-client-info-results').removeClass('success').addClass('error').show();
                    return;
                }

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
                        $('#sdpi-client-info-btn').prop('disabled', false).text('Continuar con la Cotización');

                        if (response.success) {
                            $('#sdpi-client-info-title').text('Información Guardada');
                            $('#sdpi-client-info-message').text('¡Perfecto! Continuando con la cotización...');
                            $('#sdpi-client-info-results').removeClass('error').addClass('success').show();

                            // Reload the page to show the quote form
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#sdpi-client-info-title').text('Error');
                            $('#sdpi-client-info-message').text(response.data || 'Error al guardar la información.');
                            $('#sdpi-client-info-results').removeClass('success').addClass('error').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#sdpi-client-info-loading').hide();
                        $('#sdpi-client-info-btn').prop('disabled', false).text('Continuar con la Cotización');

                        var errorMessage = 'Error de conexión. Intente nuevamente.';
                        if (status === 'timeout') {
                            errorMessage = 'La solicitud tardó demasiado. Intente nuevamente.';
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

        if (!is_email($client_email)) {
            wp_send_json_error('Por favor ingrese un correo electrónico válido.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Store client data in session
        $_SESSION['sdpi_client_info'] = array(
            'name' => $client_name,
            'phone' => $client_phone,
            'email' => $client_email,
            'captured_at' => current_time('mysql')
        );

        // Start consolidated quote session
        $session = new SDPI_Session();
        $session_id = $session->start_session($client_name, $client_email, $client_phone);
        $session->set_status($session_id, 'started');

        error_log("ajax_save_client_info session_id: " . $session_id);

        // Create initial history record
        $history = new SDPI_History();
        $history->create_initial_record($session_id, $client_name, $client_email, $client_phone);

        wp_send_json_success(array('message' => 'Información del cliente guardada exitosamente.', 'session_id' => $session_id));
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

        if (!is_email($client_email)) {
            wp_send_json_error('Por favor ingrese un correo electrónico válido.');
            exit;
        }

        if (!$quote_data) {
            wp_send_json_error('No se encontraron datos de cotización.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Store client data in session
        $_SESSION['sdpi_client_info'] = array(
            'name' => $client_name,
            'phone' => $client_phone,
            'email' => $client_email,
            'captured_at' => current_time('mysql')
        );

        // Start consolidated quote session
        $session = new SDPI_Session();
        $session_id = $session->start_session($client_name, $client_email, $client_phone);
        $session->set_status($session_id, 'started');

        // Create initial history record
        $history = new SDPI_History();
        $history->create_initial_record($session_id, $client_name, $client_email, $client_phone);

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
            'maritime_direction' => $quote_data['maritime_direction'] ?? '',
            'transport_type' => $quote_data['transport_type'] ?? (($quote_data['maritime_involved'] ?? false) ? 'maritime' : 'terrestrial'),
            'maritime_details' => $quote_data['maritime_details'] ?? array(),
            'client_name' => $client_name,
            'client_email' => $client_email,
            'client_phone' => $client_phone,
            'client_info_captured_at' => current_time('mysql')
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
                'phone' => $client_phone
            )
        ));

        // Return the quote data with success flag
        $response_data = $quote_data;
        $response_data['session_id'] = $session_id;
        $response_data['show_price'] = true; // Flag to show the price now

        wp_send_json_success($response_data);
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

        // Validate ZIP codes
        if (!preg_match('/^\d{5}$/', $p_zip) || !preg_match('/^\d{5}$/', $d_zip)) {
            wp_send_json_error('Los códigos postales deben tener 5 dígitos.');
            exit;
        }

        // Validate pickup type
        $valid_pickup_types = array('Subasta', 'Residencia', 'Dealer o negocio');
        if (!in_array($pickup_type, $valid_pickup_types)) {
            wp_send_json_error('Tipo de recogida inválido.');
            exit;
        }

        // Start session if not started
        if (!session_id()) {
            session_start();
        }

        // Store additional shipping info in session
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
            'saved_at' => current_time('mysql')
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

        wp_send_json_success('Información adicional guardada exitosamente.');
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
            wp_send_json_error('DirecciA3n marA-tima invA�lida.');
            exit;
        }

        $vehicle_conditions = sanitize_text_field($_POST['vehicle_conditions'] ?? '');
        $fuel_type = sanitize_text_field($_POST['fuel_type'] ?? '');
        $unit_value = isset($_POST['unit_value']) ? floatval($_POST['unit_value']) : 0;
        $color = sanitize_text_field($_POST['color'] ?? '');
        $dimensions = sanitize_text_field($_POST['dimensions'] ?? '');

        if (empty($vehicle_conditions) || empty($fuel_type) || empty($color) || $unit_value <= 0) {
            wp_send_json_error('Complete la informaciA3n del vehA-culo (condiciA3n, combustible, valor y color).');
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

        foreach (array('name', 'street', 'city', 'state', 'country', 'zip', 'phone1') as $field) {
            if (empty($shipper[$field])) {
                wp_send_json_error('Complete toda la informaciA3n del shipper.');
                exit;
            }
        }

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

        foreach (array('name', 'street', 'city', 'state', 'country', 'zip', 'phone1') as $field) {
            if (empty($consignee[$field])) {
                wp_send_json_error('Complete toda la informaciA3n del consignee.');
                exit;
            }
        }

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

        if ($pickup_required) {
            foreach (array('name', 'street', 'city', 'state', 'country', 'zip', 'phone1') as $field) {
                if (empty($pickup[$field])) {
                    wp_send_json_error('Complete la informaciA3n de recogida.');
                    exit;
                }
            }
        }

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

        if ($dropoff_required) {
            foreach (array('name', 'street', 'city', 'state', 'country', 'zip', 'phone1') as $field) {
                if (empty($dropoff[$field])) {
                    wp_send_json_error('Complete la informaciA3n de entrega.');
                    exit;
                }
            }
        }

        $others = array(
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'registration' => sanitize_text_field($_POST['registration'] ?? ''),
            'id' => sanitize_text_field($_POST['other_id'] ?? '')
        );

        foreach (array($shipper['zip'], $consignee['zip'], $pickup['zip'], $dropoff['zip']) as $zip_value) {
            if (!empty($zip_value) && !preg_match('/^[0-9A-Za-z\\- ]{4,10}$/', $zip_value)) {
                wp_send_json_error('Use un formato de cA3digo postal vA�lido.');
                exit;
            }
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

        wp_send_json_success(array(
            'message' => 'InformaciA3n marA-tima guardada exitosamente.',
            'maritime_details' => $maritime_details,
            'direction' => $direction
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
        $vehicle_year = intval($_POST['vehicle_year']);

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
                $final_price_data = $this->calculate_maritime_only_quote($pickup_zip, $delivery_zip, $vehicle_electric);
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
                $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip, $vehicle_electric);
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
            $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip, $vehicle_electric);
        }

        // NUEVO FLUJO: Preparar los datos de cotización pero NO mostrar el precio aún
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
            'payment_available' => class_exists('WooCommerce'),
            'transport_type' => $involves_maritime ? 'maritime' : 'terrestrial'
        );

        // Retornar respuesta indicando que necesitamos información de contacto
        wp_send_json_success(array(
            'needs_contact_info' => true,
            'quote_calculated' => true,
            'quote_data' => $quote_data // Enviar los datos de cotización para usar después de capturar contacto
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
            if ($involves_maritime && isset($final_price_data['maritime_data'])) {
                $maritime_data = $final_price_data['maritime_data'];
                $form_data['maritime_cost'] = $maritime_data['maritime_cost'];
                $form_data['us_port_name'] = $maritime_data['us_port']['port'];
                $form_data['us_port_zip'] = $maritime_data['us_port']['zip'];
                $form_data['total_terrestrial_cost'] = $maritime_data['terrestrial_cost'];
                $form_data['total_maritime_cost'] = $maritime_data['maritime_cost'];
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
    private function calculate_maritime_only_quote($pickup_zip, $delivery_zip, $vehicle_electric = false) {
        // This should only be called for San Juan to San Juan routes
        $maritime_cost = SDPI_Maritime::get_maritime_rate($pickup_zip, $delivery_zip);

        // Electric vehicle surcharge
        $electric_surcharge = $vehicle_electric ? 600.00 : 0.00;
        $total_cost = $maritime_cost + $electric_surcharge;

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

        // Electric vehicle surcharge
        if ($vehicle_electric && $electric_surcharge > 0) {
            $breakdown .= '<div class="sdpi-cost-section">';
            $breakdown .= '<h5>Recargos Adicionales</h5>';
            $breakdown .= '<div class="sdpi-price-item">';
            $breakdown .= '<span class="sdpi-price-label">Recargo por vehículo eléctrico:</span>';
            $breakdown .= '<span class="sdpi-price-value">+$' . number_format($electric_surcharge, 2) . ' USD</span>';
            $breakdown .= '</div>';
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
            'maritime_cost' => $maritime_cost
        );
    }

    /**
     * Calculate final price with company profit and confidence adjustments including maritime transport
     */
    private function calculate_final_price($api_response, $pickup_zip = '', $delivery_zip = '', $vehicle_electric = false) {
        $base_price = floatval($api_response['recommended_price'] ?? 0);
        $confidence = floatval($api_response['confidence'] ?? 0);
        
        // Check if maritime transport is involved
        $maritime_result = SDPI_Maritime::calculate_maritime_cost($pickup_zip, $delivery_zip, $base_price, $confidence, $vehicle_electric);
        
        if ($maritime_result['maritime_involved']) {
            // Return maritime calculation result
            return array(
                'base_price' => $base_price,
                'confidence' => $confidence,
                'final_price' => $maritime_result['total_cost'],
                'message' => 'El precio recomendado incluye transporte marítimo entre ' . $maritime_result['us_port']['port'] . ' y San Juan, PR.',
                'breakdown' => $maritime_result['breakdown'],
                'price' => $maritime_result['total_cost'],
                'confidence_percentage' => $confidence * 100,
                'maritime_involved' => true,
                'us_port' => $maritime_result['us_port'],
                'terrestrial_cost' => $maritime_result['terrestrial_cost'],
                'maritime_cost' => $maritime_result['maritime_cost']
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
                'Ajuste por confianza (%s%% → 100%%): +$%s USD',
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
                    <span class="sdpi-price-label">Recargo por vehículo eléctrico:</span>
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
            'confidence_percentage' => $confidence * 100
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
                'message' => 'No se pudo obtener la cotización en este momento. Por favor, intente nuevamente.'
            );
            return;
        }

        // Store successful result
        $_SESSION['sdpi_result'] = array(
            'success' => true,
            'price' => $api_response['recommended_price'] ?? 0,
            'confidence' => $api_response['confidence'] ?? 0,
            'message' => sprintf(
                'El precio recomendado es $%s USD con un nivel de confianza de %s%%.',
                number_format(floatval($api_response['recommended_price'] ?? 0), 2),
                number_format(floatval($api_response['confidence'] ?? 0) * 100, 1)
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
            return new WP_Error('invalid_zip', 'El código postal de origen debe tener 5 dígitos.');
        }

        if (!preg_match('/^\d{5}$/', $post_data['delivery_zip'])) {
            return new WP_Error('invalid_zip', 'El código postal de destino debe tener 5 dígitos.');
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
            <h3><?php echo $result['success'] ? 'Cotización Obtenida' : 'Error'; ?></h3>
            <p><?php echo esc_html($result['message']); ?></p>
        </div>
        <?php
    }

    /**
     * AJAX handler for initiating payment
     */
    public function ajax_initiate_payment() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            wp_send_json_error('WooCommerce is required for payment processing.');
            exit;
        }

        $quote_data = json_decode(stripslashes($_POST['quote_data']), true);

        // Update history to 'checkout' status
        $session = new SDPI_Session();
        $session_id = $session->get_session_id();
        if ($session_id) {
            $history = new SDPI_History();
            $history->update_to_checkout($session_id);
        }
        
        if (!$quote_data || !isset($quote_data['final_price'])) {
            wp_send_json_error('Invalid quote data.');
            exit;
        }

        // Remove previous SDPI quote products from cart
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];
                $quote_meta = get_post_meta($product_id, '_sdpi_quote_data', true);
                if ($quote_meta) {
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }
        }

        $final_price = floatval($quote_data['final_price']);
        $description = 'Vehicle Shipping Quote - $' . number_format($final_price, 2) . ' USD';

        // Create WooCommerce product
        $product = new WC_Product_Simple();
        $product->set_name($description);
        $product->set_regular_price($final_price);
        $product->set_price($final_price);
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_catalog_visibility('hidden');
        $product->set_status('publish');
        
        // Add custom meta with quote details
        $product->update_meta_data('_sdpi_quote_data', $quote_data);
        
        $product_id = $product->save();

        if (!$product_id) {
            wp_send_json_error('Failed to create product.');
            exit;
        }

        // Persist status: moving to checkout
        $session = new SDPI_Session();
        $session_id = $this->ensure_quote_session();
        $session->set_status($session_id, 'checkout');

        // Store session ID in product meta for later reference
        $product->add_meta_data('_sdpi_session_id', $session_id, true);

        // Add to cart
        WC()->cart->add_to_cart($product_id, 1);

        // Get checkout URL
        $checkout_url = wc_get_checkout_url();

        wp_send_json_success(array(
            'checkout_url' => $checkout_url,
            'product_id' => $product_id
        ));
        exit;
    }

    /**
     * Send quote data to Zapier webhook
     */
    private function send_to_zapier($pickup_zip, $delivery_zip, $trailer_type, $vehicle_type, $vehicle_inoperable, $vehicle_electric, $vehicle_make, $vehicle_model, $vehicle_year, $final_price_data, $involves_maritime) {
        // Safety: Allow manual calls after completion only
        // Get client info from session
        $client_info = array();
        if (!session_id()) {
            session_start();
        }
        if (isset($_SESSION['sdpi_client_info'])) {
            $client_info = $_SESSION['sdpi_client_info'];
        }

        // Get cities from ZIPs
        $pickup_city = $this->get_city_from_zip($pickup_zip);
        $delivery_city = $this->get_city_from_zip($delivery_zip);

        // Prepare data for Zapier
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
            'quote_date' => current_time('mysql'),
            'quote_timestamp' => current_time('timestamp'),
            'payment_available' => class_exists('WooCommerce')
        );

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

    /**
     * Handle WooCommerce payment completion
     */
    public function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if this is an SDPI order by looking for our meta data
        $session_id = $order->get_meta('_sdpi_session_id');
        if (!$session_id) {
            return;
        }

        // Update history to 'completado' status
        $history = new SDPI_History();
        $history->update_to_completado($session_id);

        // Send to Zapier if configured
        $this->send_to_zapier_from_order($order);
    }

    /**
     * Send order data to Zapier webhook
     */
    private function send_to_zapier_from_order($order) {
        // Get Zapier webhook URL from settings
        $zapier_webhook_url = get_option('sdpi_zapier_webhook_url');
        if (empty($zapier_webhook_url)) {
            return;
        }

        // Prepare data for Zapier
        $zapier_data = array(
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'total' => $order->get_total(),
            'client_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'client_email' => $order->get_billing_email(),
            'client_phone' => $order->get_billing_phone(),
            'payment_date' => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d H:i:s') : null,
            'quote_date' => current_time('mysql'),
            'quote_timestamp' => current_time('timestamp')
        );

        // Send data to Zapier (non-blocking)
        $response = wp_remote_post($zapier_webhook_url, array(
            'body' => json_encode($zapier_data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'SDPI Plugin/1.0'
            ),
            'timeout' => 5,
            'blocking' => false
        ));

        // Log any errors (only in debug mode)
        if (is_wp_error($response) && WP_DEBUG) {
            error_log('SDPI Zapier Error: ' . $response->get_error_message());
        } elseif (WP_DEBUG) {
            error_log('SDPI Zapier Success: Order data sent to webhook');
        }
    }
}

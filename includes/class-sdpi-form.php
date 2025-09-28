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
     * Render the pricing form
     */
    public function render_form() {
        // Enqueue scripts and styles when form is rendered
        $this->enqueue_styles();
        $this->enqueue_scripts();
        
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
     * AJAX handler for getting a quote
     */
    public function ajax_get_quote() {
        check_ajax_referer('sdpi_nonce', 'nonce');

        $pickup_zip = sanitize_text_field($_POST['pickup_zip']);
        $delivery_zip = sanitize_text_field($_POST['delivery_zip']);
        $trailer_type = sanitize_text_field($_POST['trailer_type']);
        $vehicle_type = sanitize_text_field($_POST['vehicle_type']);
        $vehicle_inoperable = !empty($_POST['vehicle_inoperable']);
        $vehicle_make = sanitize_text_field($_POST['vehicle_make']);
        $vehicle_model = sanitize_text_field($_POST['vehicle_model']);
        $vehicle_year = intval($_POST['vehicle_year']);

        // Check if maritime transport is involved (San Juan, PR)
        $involves_maritime = SDPI_Maritime::involves_maritime($pickup_zip, $delivery_zip);
        
        if ($involves_maritime) {
            // For maritime routes, NEVER pass San Juan to API
            $pickup_is_san_juan = SDPI_Maritime::is_san_juan_zip($pickup_zip);
            $delivery_is_san_juan = SDPI_Maritime::is_san_juan_zip($delivery_zip);
            
            if ($pickup_is_san_juan && $delivery_is_san_juan) {
                // San Juan to San Juan - only maritime, no API call needed
                $final_price_data = $this->calculate_maritime_only_quote($pickup_zip, $delivery_zip);
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
                $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip);
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
            $final_price_data = $this->calculate_final_price($api_response, $pickup_zip, $delivery_zip);
        }

        // Log to history
        $this->log_to_history($pickup_zip, $delivery_zip, $trailer_type, $vehicle_type, $vehicle_inoperable, $vehicle_make, $vehicle_model, $vehicle_year, $api_response, $final_price_data, $involves_maritime);

        // Add payment availability
        $final_price_data['payment_available'] = class_exists('WooCommerce');

        wp_send_json_success($final_price_data);
        exit;
    }


    /**
     * Log quote to history
     */
    private function log_to_history($pickup_zip, $delivery_zip, $trailer_type, $vehicle_type, $vehicle_inoperable, $vehicle_make, $vehicle_model, $vehicle_year, $api_response, $final_price_data, $involves_maritime) {
        try {
            // Get cities from ZIPs
            $pickup_city = $this->get_city_from_zip($pickup_zip);
            $delivery_city = $this->get_city_from_zip($delivery_zip);
            
            // Prepare form data for logging
            $form_data = array(
                'pickup_zip' => $pickup_zip,
                'delivery_zip' => $delivery_zip,
                'pickup_city' => $pickup_city,
                'delivery_city' => $delivery_city,
                'trailer_type' => $trailer_type,
                'vehicle_type' => $vehicle_type,
                'vehicle_inoperable' => $vehicle_inoperable,
                'vehicle_make' => $vehicle_make,
                'vehicle_model' => $vehicle_model,
                'vehicle_year' => $vehicle_year,
                'maritime_involved' => $involves_maritime
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
    private function calculate_maritime_only_quote($pickup_zip, $delivery_zip) {
        // This should only be called for San Juan to San Juan routes
        $maritime_cost = SDPI_Maritime::get_maritime_rate($pickup_zip, $delivery_zip);
        
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
        
        $breakdown .= '<div class="sdpi-cost-total">';
        $breakdown .= '<h5>Total Final</h5>';
        $breakdown .= '<p><strong>Costo Total:</strong> $' . number_format($maritime_cost, 2) . ' USD</p>';
        $breakdown .= '<p class="sdpi-maritime-note">* Transporte marítimo directo</p>';
        $breakdown .= '</div>';
        
        $breakdown .= '</div>';
        
        return array(
            'base_price' => 0,
            'confidence' => 0.85,
            'final_price' => $maritime_cost,
            'message' => 'El precio recomendado incluye transporte marítimo directo.',
            'breakdown' => $breakdown,
            'price' => $maritime_cost,
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
    private function calculate_final_price($api_response, $pickup_zip = '', $delivery_zip = '') {
        $base_price = floatval($api_response['recommended_price'] ?? 0);
        $confidence = floatval($api_response['confidence'] ?? 0);
        
        // Check if maritime transport is involved
        $maritime_result = SDPI_Maritime::calculate_maritime_cost($pickup_zip, $delivery_zip, $base_price, $confidence);
        
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
        $final_price = $base_price + $confidence_adjustment + $company_profit;
        
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
                </div>
                <div class="sdpi-price-item sdpi-price-total">
                    <span class="sdpi-price-label"><strong>Total Final:</strong></span>
                    <span class="sdpi-price-value"><strong>$%s USD</strong></span>
                </div>
            </div>',
            number_format($base_price, 2),
            $confidence_description,
            number_format($confidence_adjustment, 2),
            number_format($company_profit, 2),
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
}

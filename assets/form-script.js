jQuery(document).ready(function($) {
    'use strict';
    
    console.log('SDPI Form Script loaded - NEW FLOW');
    console.log('sdpi_ajax object:', typeof sdpi_ajax !== 'undefined' ? sdpi_ajax : 'NOT DEFINED');
    
    // City search functionality
    var searchTimeout;
    var currentSearchField = null;

    var maritimePickupFields = [
        '#sdpi_p_name', '#sdpi_p_street', '#sdpi_p_city', '#sdpi_p_state',
        '#sdpi_p_country', '#sdpi_p_zip_code', '#sdpi_p_phone1'
    ];
    var maritimeDropoffFields = [
        '#sdpi_d_name', '#sdpi_d_street', '#sdpi_d_city', '#sdpi_d_state',
        '#sdpi_d_country', '#sdpi_d_zip_code', '#sdpi_d_phone1'
    ];

    function setRequiredFields(fields, required) {
        fields.forEach(function(selector) {
            var $field = $(selector);
            if (!$field.length) { return; }
            if (required) {
                $field.attr('required', 'required');
            } else {
                $field.removeAttr('required');
            }
        });
    }

    function toggleMaritimeSection(sectionSelector, show, requiredFields) {
        var $section = $(sectionSelector);
        if (!$section.length) { return; }

        if (show) {
            $section.show();
            if (requiredFields) {
                setRequiredFields(requiredFields, true);
            }
        } else {
            $section.hide();
            if (requiredFields) {
                setRequiredFields(requiredFields, false);
            }
        }
    }
    
    // Initialize city search for both fields
    $('#sdpi_pickup_city, #sdpi_delivery_city').on('input', function() {
        var field = $(this);
        var fieldId = field.attr('id');
        var query = field.val().trim();
        
        // Clear previous timeout
        clearTimeout(searchTimeout);
        
        // Hide results if query is too short
        if (query.length < 2) {
            $('#' + fieldId.replace('_city', '-results')).empty().hide();
            return;
        }
        
        // Set current search field
        currentSearchField = fieldId;
        
        // Show loading
        var resultsContainer = $('#' + fieldId.replace('_city', '-results'));
        resultsContainer.html('<div class="sdpi-search-loading">Buscando ciudades...</div>').show();
        
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
        console.log('Searching cities for query:', query, 'fieldId:', fieldId);
        
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
                console.log('Search response:', response);
                if (response.success && response.data.length > 0) {
                    displayCityResults(response.data, fieldId);
                } else {
                    displayNoResults(fieldId);
                }
            },
            error: function(xhr, status, error) {
                console.log('Search error:', error, xhr.responseText);
                displayNoResults(fieldId);
            }
        });
    }
    
    // Display city results
    function displayCityResults(cities, fieldId) {
        var resultsContainer = $('#' + fieldId.replace('_city', '-results'));
        console.log('Displaying results for fieldId:', fieldId, 'container:', resultsContainer);
        var html = '';
        
        cities.forEach(function(city) {
            html += '<div class="sdpi-city-option" data-city=\'' + JSON.stringify(city) + '\'>';
            html += '<span class="city-name">' + city.text + '</span>';
            html += '</div>';
        });
        
        resultsContainer.html(html).show();
        console.log('Results container shown with', cities.length, 'cities');
    }
    
    // Display no results message
    function displayNoResults(fieldId) {
        var resultsContainer = $('#' + fieldId.replace('_city', '-results'));
        resultsContainer.html('<div class="sdpi-no-results">No se encontraron ciudades</div>').show();
    }
    
    // MODIFICADO: Handle form submission - Nuevo flujo con captura de contacto después
    $('#sdpi-pricing-form').on('submit', function(e) {
        e.preventDefault();
        
        // Get form data
        var formData = {
            action: 'sdpi_get_quote',
            nonce: sdpi_ajax.nonce,
            pickup_zip: $('#sdpi_pickup_zip').val(),
            delivery_zip: $('#sdpi_delivery_zip').val(),
            pickup_city: $('#sdpi_pickup_city').val(),
            delivery_city: $('#sdpi_delivery_city').val(),
            trailer_type: $('#sdpi_trailer_type').val(),
            vehicle_type: $('#sdpi_vehicle_type').val(),
            vehicle_inoperable: $('#sdpi_vehicle_inoperable').is(':checked') ? 1 : 0,
            vehicle_electric: $('#sdpi_vehicle_electric').is(':checked') ? 1 : 0,
            vehicle_make: $('#sdpi_vehicle_make').val(),
            vehicle_model: $('#sdpi_vehicle_model').val(),
            vehicle_year: $('#sdpi_vehicle_year').val()
        };
        
        // Debug logging
        console.log('SDPI FRONTEND DEBUG - Form Data:', formData);
        
        // Validate required fields
        if (!formData.pickup_zip || !formData.delivery_zip || !formData.trailer_type || !formData.vehicle_type) {
            alert('Por favor complete todos los campos requeridos.');
            return;
        }
        
        // Show loading
        $('#sdpi-loading').show();
        $('#sdpi-results').hide();
        $('#sdpi-submit-btn').prop('disabled', true).text('Calculando cotización...');
        
        // Make AJAX request
        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                $('#sdpi-loading').hide();
                $('#sdpi-submit-btn').prop('disabled', false).text('Obtener Cotización');

                if (response.success) {
                    // NUEVO FLUJO: Verificar si necesitamos información de contacto
                    if (response.data.needs_contact_info) {
                        console.log('Necesita información de contacto, mostrando formulario...');
                        
                        // Guardar los datos de cotización temporalmente
                        window.currentQuoteData = response.data.quote_data;
                        
                        // Mostrar formulario de contacto
                        showContactForm();
                    } else {
                        // Este caso no debería ocurrir en el nuevo flujo, pero lo dejamos por compatibilidad
                        displayQuoteResults(response.data);
                    }
                } else {
                    // Show error
                    $('#sdpi-result-title').text('Error');
                    $('#sdpi-result-message').text(response.data || 'Error al obtener la cotización.');
                    $('#sdpi-result-details').hide();
                    $('#sdpi-results').removeClass('success').addClass('error').show();
                }
            },
            error: function(xhr, status, error) {
                $('#sdpi-loading').hide();
                $('#sdpi-submit-btn').prop('disabled', false).text('Obtener Cotización');
                
                var errorMessage = 'Error al conectar con el servidor.';
                if (status === 'timeout') {
                    errorMessage = 'La solicitud tardó demasiado. Intente nuevamente.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                }
                
                $('#sdpi-result-title').text('Error');
                $('#sdpi-result-message').text(errorMessage);
                $('#sdpi-result-details').hide();
                $('#sdpi-results').removeClass('success').addClass('error').show();
            }
        });
    });
    
    // NUEVA FUNCIÓN: Mostrar formulario de contacto
    function showContactForm() {
        // Ocultar el formulario principal
        $('#sdpi-pricing-form').hide();
        
        // Crear y mostrar el formulario de contacto
        var contactFormHtml = `
            <div id="sdpi-contact-form-container" class="sdpi-form-section">
                <h3>Información de Contacto</h3>
                <p>Para mostrarle su cotización personalizada, por favor proporcione sus datos de contacto:</p>

                <form id="sdpi-contact-form" class="sdpi-form">
                    <div class="sdpi-form-group">
                        <label for="sdpi_contact_name">Nombre Completo *</label>
                        <input type="text" id="sdpi_contact_name" name="contact_name" required
                               placeholder="Ej: Juan Pérez">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_contact_phone">Número de Teléfono *</label>
                        <input type="tel" id="sdpi_contact_phone" name="contact_phone" required
                               placeholder="Ej: (787) 123-4567" pattern="[0-9\\(\\)\\-\\+\\s]+">
                    </div>

                    <div class="sdpi-form-group">
                        <label for="sdpi_contact_email">Correo Electrónico *</label>
                        <input type="email" id="sdpi_contact_email" name="contact_email" required
                               placeholder="Ej: juan@email.com">
                    </div>

                    <div class="sdpi-form-submit">
                        <button type="submit" class="sdpi-submit-btn" id="sdpi-contact-submit-btn">Ver Mi Cotización</button>
                        <button type="button" class="sdpi-clear-btn" id="sdpi-contact-back-btn">Volver</button>
                    </div>
                </form>
            </div>
        `;
        
        // Insertar el formulario de contacto después del formulario principal
        $('#sdpi-pricing-form').after(contactFormHtml);
        
        // Manejar el envío del formulario de contacto
        $('#sdpi-contact-form').on('submit', function(e) {
            e.preventDefault();
            submitContactInfo();
        });
        
        // Manejar el botón de volver
        $('#sdpi-contact-back-btn').on('click', function() {
            $('#sdpi-contact-form-container').remove();
            $('#sdpi-pricing-form').show();
        });
    }
    
    // NUEVA FUNCIÓN: Enviar información de contacto y obtener precio final
    function submitContactInfo() {
        var contactData = {
            action: 'sdpi_finalize_quote_with_contact',
            nonce: sdpi_ajax.nonce,
            client_name: $('#sdpi_contact_name').val().trim(),
            client_phone: $('#sdpi_contact_phone').val().trim(),
            client_email: $('#sdpi_contact_email').val().trim(),
            quote_data: JSON.stringify(window.currentQuoteData)
        };
        
        // Validación básica
        if (!contactData.client_name || !contactData.client_phone || !contactData.client_email) {
            alert('Todos los campos de contacto son requeridos.');
            return;
        }
        
        // Validación de email
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(contactData.client_email)) {
            alert('Por favor ingrese un correo electrónico válido.');
            return;
        }
        
        // Mostrar loading
        $('#sdpi-contact-submit-btn').prop('disabled', true).text('Procesando...');
        $('#sdpi-loading').show();
        
        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: contactData,
            dataType: 'json',
            success: function(response) {
                $('#sdpi-loading').hide();
                
                if (response.success) {
                    console.log('Contacto guardado, mostrando resultados con precio...');
                    
                    // Ocultar formulario de contacto
                    $('#sdpi-contact-form-container').remove();
                    
                    // Guardar los datos actualizados
                    window.currentQuoteData = response.data;
                    
                    // Mostrar los resultados con el precio
                    displayQuoteResults(response.data);
                    
                    // Mostrar el formulario principal nuevamente (por si quieren hacer otra cotización)
                    $('#sdpi-pricing-form').show();
                } else {
                    $('#sdpi-contact-submit-btn').prop('disabled', false).text('Ver Mi Cotización');
                    alert(response.data || 'Error al procesar la información de contacto.');
                }
            },
            error: function(xhr, status, error) {
                $('#sdpi-loading').hide();
                $('#sdpi-contact-submit-btn').prop('disabled', false).text('Ver Mi Cotización');
                alert('Error de conexión. Por favor intente nuevamente.');
            }
        });
    }
    
    // FUNCIÓN MODIFICADA: Mostrar resultados de cotización
    function displayQuoteResults(data) {
        // Clear any previous payment buttons before showing new results
        $('.sdpi-pay-btn').remove();

        data.transport_type = data.transport_type || (data.maritime_involved ? 'maritime' : 'terrestrial');

        // Show success results
        $('#sdpi-result-title').text('Cotización Obtenida');
        $('#sdpi-result-message').text(data.message || 'Su cotización ha sido calculada exitosamente.');
        $('#sdpi-price').text('$' + data.final_price + ' USD');
        $('#sdpi-confidence').text((data.confidence_percentage || 0) + '%');

        // Show price breakdown
        if (data.breakdown) {
            $('#sdpi-result-details').html(data.breakdown);
        } else {
            $('#sdpi-result-details').html(
                '<p><strong>Precio Final:</strong> $' + data.final_price + ' USD</p>' +
                '<p><strong>Nivel de Confianza:</strong> ' + (data.confidence_percentage || 0) + '%</p>'
            );
        }

        $('#sdpi-result-details').show();
        $('#sdpi-results').removeClass('error').addClass('success').show();

        // Add continue button if payment available
        if (data.payment_available) {
            var payButton = '<button type="button" class="sdpi-pay-btn" data-quote=\'' + JSON.stringify(data) + '\'>Continuar</button>';
            $('#sdpi-results').append(payButton);
        }
    }

    function initiateCheckout(btn, quoteData, originalText) {
        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sdpi_initiate_payment',
                nonce: sdpi_ajax.nonce,
                quote_data: JSON.stringify(quoteData)
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data && response.data.checkout_url) {
                    window.location.href = response.data.checkout_url;
                } else {
                    alert('Error al iniciar el pago: ' + ((response && response.data) || 'Error desconocido'));
                    if (btn) {
                        btn.prop('disabled', false).text(originalText);
                    }
                }
            },
            error: function() {
                alert('Error de conexiA3n al iniciar el pago.');
                if (btn) {
                    btn.prop('disabled', false).text(originalText);
                }
            }
        });
    }
    
    // Clear form button
    $('<button type="button" class="sdpi-clear-btn">Limpiar Formulario</button>').insertAfter('#sdpi-submit-btn');
    
    $('.sdpi-clear-btn').on('click', function() {
        $('#sdpi-pricing-form')[0].reset();
        $('#sdpi-results').hide();
        $('#sdpi-loading').hide();
    });

    // Handle payment button click
    $(document).on('click', '.sdpi-pay-btn', function() {
        var quoteData = $(this).data('quote') || {};
        quoteData.transport_type = quoteData.transport_type || (quoteData.maritime_involved ? 'maritime' : 'terrestrial');
        var isMaritime = quoteData.transport_type === 'maritime';

        try {
            var pickupLabel = quoteData.pickup_city ? (quoteData.pickup_city + ' (' + quoteData.pickup_zip + ')') : ($('#sdpi_pickup_city').val() + ' (' + $('#sdpi_pickup_zip').val() + ')');
            var deliveryLabel = quoteData.delivery_city ? (quoteData.delivery_city + ' (' + quoteData.delivery_zip + ')') : ($('#sdpi_delivery_city').val() + ' (' + $('#sdpi_delivery_zip').val() + ')');
            var trailerText = $('#sdpi_trailer_type option[value="' + (quoteData.trailer_type || '') + '"]').text() || $('#sdpi_trailer_type option:selected').text();
            var vehicleYear = $('#sdpi_vehicle_year').val();
            var vehicleMake = $('#sdpi_vehicle_make').val();
            var vehicleModel = $('#sdpi_vehicle_model').val();
            var vehicleTypeLabel = $('#sdpi_vehicle_type option:selected').text() || (quoteData.vehicle_type || '');
            var vehicleSummary = (vehicleYear + ' ' + vehicleMake + ' ' + vehicleModel).trim();

            $('#sdpi-summary-pickup').text(pickupLabel);
            $('#sdpi-summary-delivery').text(deliveryLabel);
            $('#sdpi-summary-trailer').text(trailerText);
            $('#sdpi-summary-vehicle').text(vehicleSummary);
            $('#sdpi-summary-price').text('$' + quoteData.final_price + ' USD');
            $('#sdpi-summary-transport-type').text(isMaritime ? 'Transporte MarA-timo' : 'Transporte Terrestre');
            $('#sdpi-summary-transport-type-row').show();

            $('#sdpi_ai_vehicle_year').val(vehicleYear);
            $('#sdpi_ai_vehicle_make').val(vehicleMake);
            $('#sdpi_ai_vehicle_model').val(vehicleModel);

            $('#sdpi_m_vehicle_year').val(vehicleYear);
            $('#sdpi_m_vehicle_make').val(vehicleMake);
            $('#sdpi_m_vehicle_model').val(vehicleModel);
            $('#sdpi_m_vehicle_type').val(vehicleTypeLabel);

            $('#sdpi_ai_p_city').val($('#sdpi_pickup_city').val());
            $('#sdpi_ai_p_zip').val($('#sdpi_pickup_zip').val());
            $('#sdpi_ai_d_city').val($('#sdpi_delivery_city').val());
            $('#sdpi_ai_d_zip').val($('#sdpi_delivery_zip').val());

            if (isMaritime) {
                $('#sdpi_transport_type').val('maritime');
                $('#sdpi-additional-info-form').hide();
                $('#sdpi-maritime-info-form').show();

                var direction = quoteData.maritime_direction || '';
                if (!direction) {
                    if ((quoteData.pickup_zip || '').indexOf('009') === 0) {
                        direction = 'pr_to_usa';
                    } else if ((quoteData.delivery_zip || '').indexOf('009') === 0) {
                        direction = 'usa_to_pr';
                    } else {
                        direction = 'pr_pr';
                    }
                }
                $('#sdpi_maritime_direction').val(direction);

                if (direction === 'usa_to_pr') {
                    $('#sdpi_s_country').val('USA');
                    $('#sdpi_c_country').val('Puerto Rico');
                } else if (direction === 'pr_to_usa') {
                    $('#sdpi_s_country').val('Puerto Rico');
                    $('#sdpi_c_country').val('USA');
                }

                toggleMaritimeSection('#sdpi-pickup-section', direction !== 'pr_to_usa', maritimePickupFields);
                toggleMaritimeSection('#sdpi-dropoff-section', direction !== 'usa_to_pr', maritimeDropoffFields);

                if (direction !== 'pr_to_usa') {
                    $('#sdpi_p_city').val($('#sdpi_pickup_city').val()).prop('readonly', true);
                    $('#sdpi_p_zip_code').val($('#sdpi_pickup_zip').val()).prop('readonly', true);
                } else {
                    $('#sdpi_p_city').val('').prop('readonly', false);
                    $('#sdpi_p_zip_code').val('').prop('readonly', false);
                }

                if (direction !== 'usa_to_pr') {
                    $('#sdpi_d_city').val($('#sdpi_delivery_city').val()).prop('readonly', true);
                    $('#sdpi_d_zip_code').val($('#sdpi_delivery_zip').val()).prop('readonly', true);
                } else {
                    $('#sdpi_d_city').val('').prop('readonly', false);
                    $('#sdpi_d_zip_code').val('').prop('readonly', false);
                }

                $('#sdpi-maritime-info-form').data('quote', quoteData);
            } else {
                $('#sdpi_transport_type').val('terrestrial');
                $('#sdpi-maritime-info-form').hide();
                toggleMaritimeSection('#sdpi-pickup-section', false, maritimePickupFields);
                toggleMaritimeSection('#sdpi-dropoff-section', false, maritimeDropoffFields);
                $('#sdpi-additional-info-form').show();
                $('#sdpi_ai_p_city, #sdpi_ai_p_zip, #sdpi_ai_d_city, #sdpi_ai_d_zip').prop('readonly', true);
            }

            $('#sdpi-pricing-form').hide();
            $('#sdpi-results').hide();
            $('#sdpi-additional-info').show();
            $('html, body').animate({ scrollTop: $('#sdpi-additional-info').offset().top - 40 }, 300);

            $('#sdpi-additional-info').data('quote', quoteData);
            window.currentQuoteData = quoteData;
        } catch (error) {
            console.error('Error preparando pantalla adicional:', error);
        }
    });



    // Botón cancelar en pantalla adicional
    $(document).on('click', '#sdpi-ai-cancel', function() {
        $('#sdpi-additional-info').hide();
        $('#sdpi-pricing-form').show();
        $('#sdpi-results').show();
    });

    // Continuar al pago: validar y guardar info adicional, luego iniciar pago
    $(document).on('click', '#sdpi-ai-continue', function() {
        var btn = $(this);
        var container = $('#sdpi-additional-info');
        var quoteData = container.data('quote') || {};

        var payload = {
            action: 'sdpi_save_additional_info',
            nonce: sdpi_ajax.nonce,
            p_name: $('#sdpi_ai_p_name').val().trim(),
            p_street: $('#sdpi_ai_p_street').val().trim(),
            p_city: $('#sdpi_ai_p_city').val().trim(),
            p_zip: $('#sdpi_ai_p_zip').val().trim(),
            d_name: $('#sdpi_ai_d_name').val().trim(),
            d_street: $('#sdpi_ai_d_street').val().trim(),
            d_city: $('#sdpi_ai_d_city').val().trim(),
            d_zip: $('#sdpi_ai_d_zip').val().trim(),
            pickup_type: $('#sdpi_ai_pickup_type').val().trim()
        };

        if (!payload.p_name || !payload.p_street || !payload.p_city || !/^\d{5}$/.test(payload.p_zip) ||
            !payload.d_name || !payload.d_street || !payload.d_city || !/^\d{5}$/.test(payload.d_zip) ||
            !payload.pickup_type) {
            alert('Por favor complete todos los campos obligatorios con informaciA3n vA�lida.');
            return;
        }

        var originalText = btn.data('original-text') || btn.text();
        btn.data('original-text', originalText);
        btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    alert(resp.data || 'Error al guardar la informaciA3n adicional.');
                    btn.prop('disabled', false).text(originalText);
                    return;
                }

                var shippingDetails = {
                    pickup: {
                        name: payload.p_name,
                        street: payload.p_street,
                        city: payload.p_city,
                        zip: payload.p_zip,
                        type: payload.pickup_type
                    },
                    delivery: {
                        name: payload.d_name,
                        street: payload.d_street,
                        city: payload.d_city,
                        zip: payload.d_zip
                    }
                };

                quoteData.shipping = shippingDetails;
                quoteData.transport_type = 'terrestrial';
                window.currentQuoteData = quoteData;
                $('#sdpi-additional-info').data('quote', quoteData);

                initiateCheckout(btn, quoteData, originalText);
            },
            error: function() {
                alert('Error de conexiA3n al guardar la informaciA3n adicional.');
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    $(document).on('click', '#sdpi-maritime-cancel', function() {
        $('#sdpi-additional-info').hide();
        $('#sdpi-pricing-form').show();
        $('#sdpi-results').show();
    });

    $(document).on('click', '#sdpi-maritime-continue', function() {
        var btn = $(this);
        var form = $('#sdpi-maritime-info-form')[0];

        if (form && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        var quoteData = $('#sdpi-maritime-info-form').data('quote') || {};
        var originalText = btn.data('original-text') || btn.text();
        btn.data('original-text', originalText);
        btn.prop('disabled', true).text('Guardando...');

        var payload = {
            action: 'sdpi_save_maritime_info',
            nonce: sdpi_ajax.nonce,
            maritime_direction: $('#sdpi_maritime_direction').val(),
            vehicle_conditions: $('#sdpi_m_vehicle_conditions').val(),
            fuel_type: $('#sdpi_m_fuel_type').val(),
            unit_value: $('#sdpi_m_unit_value').val(),
            color: $('#sdpi_m_color').val().trim(),
            dimensions: $('#sdpi_m_dimensions').val().trim(),
            shipper_name: $('#sdpi_s_name').val().trim(),
            shipper_street: $('#sdpi_s_street').val().trim(),
            shipper_city: $('#sdpi_s_city').val().trim(),
            shipper_state: $('#sdpi_s_state').val().trim(),
            shipper_country: $('#sdpi_s_country').val().trim(),
            shipper_zip: $('#sdpi_s_zip').val().trim(),
            shipper_phone1: $('#sdpi_s_phone1').val().trim(),
            shipper_phone2: $('#sdpi_s_phone2').val().trim(),
            consignee_name: $('#sdpi_c_name').val().trim(),
            consignee_street: $('#sdpi_c_street').val().trim(),
            consignee_city: $('#sdpi_c_city').val().trim(),
            consignee_state: $('#sdpi_c_state').val().trim(),
            consignee_country: $('#sdpi_c_country').val().trim(),
            consignee_zip: $('#sdpi_c_zip').val().trim(),
            consignee_phone1: $('#sdpi_c_phone1').val().trim(),
            consignee_phone2: $('#sdpi_c_phone2').val().trim(),
            pickup_name: $('#sdpi_p_name').val().trim(),
            pickup_street: $('#sdpi_p_street').val().trim(),
            pickup_city: $('#sdpi_p_city').val().trim(),
            pickup_state: $('#sdpi_p_state').val().trim(),
            pickup_country: $('#sdpi_p_country').val().trim(),
            pickup_zip: $('#sdpi_p_zip_code').val().trim(),
            pickup_phone1: $('#sdpi_p_phone1').val().trim(),
            pickup_phone2: $('#sdpi_p_phone2').val().trim(),
            dropoff_name: $('#sdpi_d_name').val().trim(),
            dropoff_street: $('#sdpi_d_street').val().trim(),
            dropoff_city: $('#sdpi_d_city').val().trim(),
            dropoff_state: $('#sdpi_d_state').val().trim(),
            dropoff_country: $('#sdpi_d_country').val().trim(),
            dropoff_zip: $('#sdpi_d_zip_code').val().trim(),
            dropoff_phone1: $('#sdpi_d_phone1').val().trim(),
            dropoff_phone2: $('#sdpi_d_phone2').val().trim(),
            title: $('#sdpi_m_title').val().trim(),
            registration: $('#sdpi_m_registration').val().trim(),
            other_id: $('#sdpi_m_id').val().trim()
        };

        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(resp) {
                if (!resp.success) {
                    alert(resp.data || 'Error al guardar la informaciA3n marA-tima.');
                    btn.prop('disabled', false).text(originalText);
                    return;
                }

                quoteData.transport_type = 'maritime';
                quoteData.maritime_details = resp.data && resp.data.maritime_details ? resp.data.maritime_details : null;
                quoteData.maritime_direction = resp.data && resp.data.direction ? resp.data.direction : payload.maritime_direction;
                window.currentQuoteData = quoteData;
                $('#sdpi-maritime-info-form').data('quote', quoteData);
                $('#sdpi-additional-info').data('quote', quoteData);

                initiateCheckout(btn, quoteData, originalText);
            },
            error: function() {
                alert('Error de conexiA3n al guardar la informaciA3n marA-tima.');
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Real-time ZIP code validation
    $('#sdpi_pickup_zip, #sdpi_delivery_zip').on('input', function() {
        var zip = $(this).val();
        if (zip.length === 5 && /^\d{5}$/.test(zip)) {
            $(this).removeClass('error').addClass('valid');
        } else if (zip.length > 0) {
            $(this).removeClass('valid').addClass('error');
        } else {
            $(this).removeClass('valid error');
        }
    });
});

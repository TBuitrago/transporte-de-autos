jQuery(document).ready(function($) {
    'use strict';
    
    console.log('SDPI Form Script loaded');
    console.log('sdpi_ajax object:', typeof sdpi_ajax !== 'undefined' ? sdpi_ajax : 'NOT DEFINED');
    
    // City search functionality
    var searchTimeout;
    var currentSearchField = null;
    
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
    
    // Handle form submission
    $('#sdpi-pricing-form').on('submit', function(e) {
        e.preventDefault();
        
        // Get form data
        var formData = {
            action: 'sdpi_get_quote',
            nonce: sdpi_ajax.nonce,
            pickup_zip: $('#sdpi_pickup_zip').val(),
            delivery_zip: $('#sdpi_delivery_zip').val(),
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
        console.log('SDPI FRONTEND DEBUG - Pickup ZIP:', formData.pickup_zip);
        console.log('SDPI FRONTEND DEBUG - Delivery ZIP:', formData.delivery_zip);
        
        // Validate required fields
        if (!formData.pickup_zip || !formData.delivery_zip || !formData.trailer_type || !formData.vehicle_type) {
            alert('Por favor complete todos los campos requeridos.');
            return;
        }
        
        // Show loading
        $('#sdpi-loading').show();
        $('#sdpi-results').hide();
        
        // Make AJAX request
        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                $('#sdpi-loading').hide();
                
                if (response.success) {
                    // Show success results
                    $('#sdpi-result-title').text('Cotización Obtenida');
                    $('#sdpi-result-message').text(response.data.message);
                    $('#sdpi-price').text('$' + response.data.final_price + ' USD');
                    $('#sdpi-confidence').text(response.data.confidence_percentage + '%');
                    
                    // Show price breakdown
                    if (response.data.breakdown) {
                        $('#sdpi-result-details').html(response.data.breakdown);
                    } else {
                        $('#sdpi-result-details').html(
                            '<p><strong>Precio Final:</strong> $' + response.data.final_price + ' USD</p>' +
                            '<p><strong>Nivel de Confianza:</strong> ' + response.data.confidence_percentage + '%</p>'
                        );
                    }
                    
                    $('#sdpi-result-details').show();
                    $('#sdpi-results').removeClass('error').addClass('success').show();

                    // Add payment button if available
                    if (response.data.payment_available) {
                        var payButton = '<button type="button" class="sdpi-pay-btn" data-quote=\'' + JSON.stringify(response.data) + '\'>Pagar Cotización Ahora</button>';
                        $('#sdpi-results').append(payButton);
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
    
    // Clear form button
    $('<button type="button" class="sdpi-clear-btn">Limpiar Formulario</button>').insertAfter('#sdpi-submit-btn');
    
    $('.sdpi-clear-btn').on('click', function() {
        $('#sdpi-pricing-form')[0].reset();
        $('#sdpi-results').hide();
        $('#sdpi-loading').hide();
    });

    // Handle payment button click
    $(document).on('click', '.sdpi-pay-btn', function() {
        var quoteData = $(this).data('quote');
        var button = $(this);
        
        button.prop('disabled', true).text('Procesando...');
        
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
                if (response.success) {
                    window.location.href = response.data.checkout_url;
                } else {
                    alert('Error al iniciar el pago: ' + (response.data || 'Error desconocido'));
                    button.prop('disabled', false).text('Pagar Cotización Ahora');
                }
            },
            error: function() {
                alert('Error de conexión. Intente nuevamente.');
                button.prop('disabled', false).text('Pagar Cotización Ahora');
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

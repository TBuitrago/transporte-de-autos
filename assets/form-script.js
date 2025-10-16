jQuery(document).ready(function($) {
    $("#sdpi_trailer_type, #sdpi_vehicle_type").on("change", updateLiveSummary);
    $("#sdpi_vehicle_make, #sdpi_vehicle_model, #sdpi_vehicle_year").on("input change", updateLiveSummary);
    $('#sdpi_vehicle_inoperable, #sdpi_vehicle_electric').on('change', updateLiveSummary);
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
    var currentProgressStep = 1;
    var formLocked = false;
    var defaultSummaryFooter = 'Completa el formulario para calcular tu cotizacion y continuar.';
    var additionalInfoDefaults = {
        title: $('#sdpi-additional-info .sdpi-review-intro h3').text(),
        subtitle: $('#sdpi-additional-info .sdpi-review-intro p').text(),
        checklist: $('#sdpi-additional-info .sdpi-review-checklist').html(),
        reviewFooter: $('#sdpi-review-summary-footer-text').text()
    };
    var paymentContext = null;
    var paymentEnabled = typeof sdpi_payment !== 'undefined' && !!sdpi_payment.enabled;
    var paymentBlockedMessage = (typeof sdpi_payment !== 'undefined' && sdpi_payment.message) ? sdpi_payment.message : '';

    function setProgressStep(step) {
        currentProgressStep = step;
        $('.sdpi-progress-step').each(function() {
            var target = parseInt($(this).data('step'), 10);
            $(this).removeClass('active completed');
            if (target < step) {
                $(this).addClass('completed');
            } else if (target === step) {
                $(this).addClass('active');
            }
        });
    }

    function updateSummaryFooter(state, message) {
        var $footer = $('.sdpi-summary-footer');
        var finalState = state || 'info';
        var finalMessage = message || defaultSummaryFooter;

        if ($footer.length) {
            $footer.removeClass('is-info is-success is-error').addClass('is-' + finalState);
        }

        var $text = $('#sdpi-summary-footer-text');
        if ($text.length) {
            $text.text(finalMessage);
        }

        var $reviewText = $('#sdpi-review-summary-footer-text');
        if ($reviewText.length) {
            $reviewText.text(finalMessage);
        }

        var $paymentText = $('#sdpi-payment-summary-footer-text');
        if ($paymentText.length) {
            $paymentText.text(finalMessage);
        }
    }

    function toggleContinueButton(data) {
        var $btn = $('#sdpi-summary-continue-btn');
        var $actions = $('#sdpi-summary-actions');
        var $inlineBtn = $('#sdpi-inline-continue-btn');
        if (!$btn.length) { return; }

        var canPay = paymentEnabled && data && data.payment_available;

        if (canPay) {
            if ($actions.length) { $actions.show(); }
            $btn.show().prop('disabled', false).data('quote', data);
            try {
                $btn.attr('data-quote', JSON.stringify(data));
            } catch (error) {
                $btn.removeAttr('data-quote');
            }

            if ($inlineBtn.length) {
                if (formLocked) {
                    $inlineBtn.show().prop('disabled', false).data('quote', data);
                    try {
                        $inlineBtn.attr('data-quote', JSON.stringify(data));
                    } catch (error) {
                        $inlineBtn.removeAttr('data-quote');
                    }
                } else {
                    $inlineBtn.hide().removeData('quote').removeAttr('data-quote');
                }
            }
        } else {
            if ($actions.length) { $actions.hide(); }
            $btn.hide().prop('disabled', false).removeData('quote');
            $btn.removeAttr('data-quote');
            resetPaymentPanel();
            if (data && data.payment_available && paymentBlockedMessage) {
                updateSummaryFooter('error', paymentBlockedMessage);
            }

            if ($inlineBtn.length) {
                $inlineBtn.hide().prop('disabled', false).removeData('quote').removeAttr('data-quote');
            }
        }
    }

    function formatCityDisplay(city, zip) {
        if (!city) { return ''; }
        return zip ? city + ' (' + zip + ')' : city;
    }

    function formatTransportLabel(type) {
        if (!type) { return ''; }
        if (type === 'maritime') { return 'Transporte maritimo'; }
        if (type === 'terrestrial') { return 'Transporte terrestre'; }
        return type;
    }

    function formatCurrency(amount) {
        var number = parseFloat(amount);
        if (isNaN(number)) {
            return '';
        }
        return '$' + number.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' USD';
    }

    function normalizePhaseAmount(amount) {
        var parsed = parseFloat(amount);
        if (isNaN(parsed) || !isFinite(parsed)) {
            return null;
        }
        if (parsed < 0) {
            parsed = 0;
        }
        return parsed;
    }

    function setPhaseTotalValue(selector, amount) {
        var $value = $(selector);
        if (!$value.length) { return; }

        var $row = $value.closest('.sdpi-summary-subtotal');
        var hasAmount = typeof amount === 'number' && !isNaN(amount) && isFinite(amount) && amount > 0;

        if (hasAmount) {
            var formatted = formatCurrency(amount);
            if (!formatted) {
                formatted = '$0.00 USD';
            }
            $value.text(formatted);
            if ($row.length) {
                $row.removeClass('pending');
                $row.removeClass('sdpi-hidden');
            }
        } else {
            $value.text('Pendiente');
            if ($row.length) {
                $row.addClass('pending');
                $row.addClass('sdpi-hidden');
            }
        }
    }

    function updatePhaseTotals(maritimeAmount, terrestrialAmount) {
        var maritimeValue = normalizePhaseAmount(maritimeAmount);
        var terrestrialValue = normalizePhaseAmount(terrestrialAmount);

        setPhaseTotalValue('#sdpi-summary-maritime-total', maritimeValue);
        setPhaseTotalValue('#sdpi-summary-terrestrial-total', terrestrialValue);
        setPhaseTotalValue('#sdpi-review-summary-maritime-total', maritimeValue);
        setPhaseTotalValue('#sdpi-review-summary-terrestrial-total', terrestrialValue);
        setPhaseTotalValue('#sdpi-payment-summary-maritime-total', maritimeValue);
        setPhaseTotalValue('#sdpi-payment-summary-terrestrial-total', terrestrialValue);
    }

    function calculatePhaseTotals(data, isMaritime, electricSurcharge, inoperableFee) {
        var maritimeCost = parseFloat(data && data.maritime_cost ? data.maritime_cost : 0);
        if (isNaN(maritimeCost)) { maritimeCost = 0; }

        var terrestrialCost = parseFloat(data && data.terrestrial_cost ? data.terrestrial_cost : 0);
        if (isNaN(terrestrialCost)) { terrestrialCost = 0; }

        var finalPriceAmount = parseFloat(data && data.final_price ? data.final_price : 0);
        if (isNaN(finalPriceAmount)) { finalPriceAmount = 0; }

        var normalizedElectric = parseFloat(electricSurcharge || 0);
        if (isNaN(normalizedElectric) || normalizedElectric < 0) { normalizedElectric = 0; }

        var normalizedInoperable = parseFloat(inoperableFee || 0);
        if (isNaN(normalizedInoperable) || normalizedInoperable < 0) { normalizedInoperable = 0; }

        var maritimeTotal = null;
        if (isMaritime) {
            var computedMaritime = maritimeCost + normalizedElectric + normalizedInoperable;
            if (isFinite(computedMaritime) && computedMaritime > 0) {
                maritimeTotal = computedMaritime;
            }
        }

        var terrestrialCandidate = isMaritime ? terrestrialCost : finalPriceAmount;
        var terrestrialTotal = (isFinite(terrestrialCandidate) && terrestrialCandidate > 0)
            ? terrestrialCandidate
            : null;

        return {
            maritime: maritimeTotal,
            terrestrial: terrestrialTotal
        };
    }

    function resetPaymentPanel() {
        paymentContext = null;
        var $paymentScreen = $('#sdpi-payment-screen');
        if ($paymentScreen.length) {
            $paymentScreen.hide();
        }
        var $panel = $('#sdpi-payment-panel');
        if ($panel.length) {
            $panel.hide();
        }
        var $form = $('#sdpi-payment-form');
        if ($form.length && $form[0]) {
            $form[0].reset();
        }
        var $feedback = $('#sdpi-payment-feedback');
        if ($feedback.length) {
            $feedback.hide().removeClass('error success').css('color', '').text('');
        }
        $('#sdpi-payment-amount-display').text('--');
        $('#sdpi-payment-summary-panel').hide();
        $('#sdpi-review-summary-panel').hide();
        $('#sdpi-ai-continue').prop('disabled', false).text('Proceder al Checkout');
        $('#sdpi-maritime-continue').prop('disabled', false).text('Proceder al Checkout');
    }

    function showPaymentPanel(context) {
        if (!paymentEnabled || !context) {
            return;
        }

        paymentContext = context;
        var $panel = $('#sdpi-payment-panel');
        var $paymentScreen = $('#sdpi-payment-screen');
        if (!$panel.length || !$paymentScreen.length) {
            return;
        }

        var formattedAmount = formatCurrency(context.amount);
        if (!formattedAmount && context.amount_numeric) {
            formattedAmount = formatCurrency(context.amount_numeric);
        }
        if (!formattedAmount && typeof context.amount === 'string') {
            formattedAmount = '$' + context.amount;
        }

        $('#sdpi-payment-amount-display').text(formattedAmount || '');

        var $form = $('#sdpi-payment-form');
        if ($form.length && $form[0]) {
            $form[0].reset();
        }

        var $feedback = $('#sdpi-payment-feedback');
        if ($feedback.length) {
            $feedback.hide().removeClass('error success').css('color', '').text('');
        }

        $('#sdpi-additional-info').hide();
        $('#sdpi-review-summary-panel').hide();
        $('#sdpi-payment-summary-panel').show();
        $paymentScreen.show();
        $panel.show();
        $('html, body').animate({ scrollTop: $paymentScreen.offset().top - 40 }, 300);
        updateSummaryFooter('info', 'Ingresa los datos de tu tarjeta para completar el pago.');
    }

    function showPaymentError(message) {
        var finalMessage = message || 'No se pudo procesar el pago. Inténtalo nuevamente.';
        var $feedback = $('#sdpi-payment-feedback');
        if ($feedback.length) {
            $feedback.removeClass('success').addClass('error').css('color', '#d63638').text(finalMessage).show();
        }
        updateSummaryFooter('error', finalMessage);
    }

    function showPaymentSuccess(message) {
        var finalMessage = message || 'Pago procesado exitosamente.';
        var $feedback = $('#sdpi-payment-feedback');
        if ($feedback.length) {
            $feedback.removeClass('error').addClass('success').css('color', '#00a32a').text(finalMessage).show();
        }
        updateSummaryFooter('success', finalMessage);
    }

    function setSummaryValue(selector, value) {
        var $value = $(selector);
        if (!$value.length) { return; }
        var $item = $value.closest('.sdpi-summary-item');
        if (value && value.trim() !== '') {
            $value.text(value);
            if ($item.length) { $item.removeClass('pending'); }
        } else {
            $value.text('Pendiente');
            if ($item.length) { $item.addClass('pending'); }
        }

        if ($value.attr('id') === 'sdpi-summary-transport-type') {
            if (value && value.trim() !== '') {
                if ($item.length) { $item.css('display', 'flex'); }
            } else {
                if ($item.length) { $item.hide(); }
            }
        }
    }

    function setSummaryPrice(value) {
        var hasValue = value && value.trim() !== '';
        var displayValue = hasValue ? value : 'Pendiente';

        $('#sdpi-summary-price').text(displayValue);
        $('#sdpi-summary-panel .sdpi-summary-total').toggleClass('pending', !hasValue);

        updateReviewSummaryPrice(value);
    }

    function updateReviewSummaryPrice(value) {
        var $price = $('#sdpi-review-summary-price');
        if ($price.length) {
            var hasValue = value && value.trim() !== '';
            var displayValue = hasValue ? value : 'Pendiente';
            $price.text(displayValue);
            $('#sdpi-review-summary-panel .sdpi-summary-total').toggleClass('pending', !hasValue);
        }

        updatePaymentSummaryPrice(value);
    }

    function updatePaymentSummaryPrice(value) {
        var $price = $('#sdpi-payment-summary-price');
        if (!$price.length) { return; }

        var hasValue = value && value.trim() !== '';
        var displayValue = hasValue ? value : 'Pendiente';

        $price.text(displayValue);
        $('#sdpi-payment-summary-panel .sdpi-summary-total').toggleClass('pending', !hasValue);
    }

    function setReviewSummaryValue(selector, value) {
        var $value = $(selector);
        if (!$value.length) { return; }
        var $item = $value.closest('.sdpi-summary-item');
        if (value && value.trim() !== '') {
            $value.text(value);
            if ($item.length) { $item.removeClass('pending'); }
        } else {
            $value.text('Pendiente');
            if ($item.length) { $item.addClass('pending'); }
        }
    }

    function setPaymentSummaryValue(selector, value) {
        var $value = $(selector);
        if (!$value.length) { return; }
        var $item = $value.closest('.sdpi-summary-item');
        if (value && value.trim() !== '') {
            $value.text(value);
            if ($item.length) { $item.removeClass('pending'); }
        } else {
            $value.text('Pendiente');
            if ($item.length) { $item.addClass('pending'); }
        }
    }

    function toggleSurchargeClass($element, shouldHighlight) {
        if (!$element || !$element.length) { return; }
        if (shouldHighlight) {
            $element.addClass('sdpi-summary-surcharge');
        } else {
            $element.removeClass('sdpi-summary-surcharge');
        }
    }

    function updateSurchargeDisplay(setter, selector, isSelected, highlight, amount, showOnlyWhenApplicable) {
        if (typeof setter !== 'function') { return; }

        var shouldHighlight = !!highlight;
        var surchargeAmount = (typeof amount !== 'undefined' && amount !== null) ? parseFloat(amount) : null;
        var requireApplicability = !!showOnlyWhenApplicable;
        var $value = $(selector);

        if (!$value.length) { return; }

        var $item = $value.closest('.sdpi-summary-item');
        var shouldShowRow = !requireApplicability || (isSelected && shouldHighlight);

        if ($item.length) {
            if (shouldShowRow) {
                $item.css('display', 'flex');
            } else {
                $item.hide();
            }
        }

        if (!shouldShowRow) {
            setter(selector, '');
            toggleSurchargeClass($value, false);
            return;
        }

        var value = isSelected ? 'Sí' : 'No';
        if (isSelected && surchargeAmount !== null && !isNaN(surchargeAmount) && surchargeAmount > 0) {
            value = 'Sí (+' + formatCurrency(surchargeAmount) + ')';
        }

        setter(selector, value);
        toggleSurchargeClass($value, isSelected && shouldHighlight);
    }

    function clearSurchargeDisplay(setter, selector) {
        if (typeof setter !== 'function') { return; }
        var $value = $(selector);
        if ($value.length) {
            var $item = $value.closest('.sdpi-summary-item');
            if ($item.length) {
                $item.hide();
            }
            toggleSurchargeClass($value, false);
        }
        setter(selector, '');
    }

    function resetPaymentSummary() {
        setPaymentSummaryValue('#sdpi-payment-summary-pickup', '');
        setPaymentSummaryValue('#sdpi-payment-summary-delivery', '');
        setPaymentSummaryValue('#sdpi-payment-summary-trailer', '');
        setPaymentSummaryValue('#sdpi-payment-summary-vehicle', '');
        setPaymentSummaryValue('#sdpi-payment-summary-transport-type', '');
        $('#sdpi-payment-summary-transport-type-row').hide();
        updatePaymentSummaryPrice('');
        clearSurchargeDisplay(setPaymentSummaryValue, '#sdpi-payment-summary-inoperable');
        clearSurchargeDisplay(setPaymentSummaryValue, '#sdpi-payment-summary-electric');
        setPhaseTotalValue('#sdpi-payment-summary-maritime-total', null);
        setPhaseTotalValue('#sdpi-payment-summary-terrestrial-total', null);
    }

    function resetReviewSummary() {
        setReviewSummaryValue('#sdpi-review-summary-pickup', '');
        setReviewSummaryValue('#sdpi-review-summary-delivery', '');
        setReviewSummaryValue('#sdpi-review-summary-trailer', '');
        setReviewSummaryValue('#sdpi-review-summary-vehicle', '');
        setReviewSummaryValue('#sdpi-review-summary-transport-type', '');
        $('#sdpi-review-summary-transport-type-row').hide();
        resetPaymentSummary();
        updateReviewSummaryPrice('');
        clearSurchargeDisplay(setReviewSummaryValue, '#sdpi-review-summary-inoperable');
        clearSurchargeDisplay(setReviewSummaryValue, '#sdpi-review-summary-electric');
        setPhaseTotalValue('#sdpi-review-summary-maritime-total', null);
        setPhaseTotalValue('#sdpi-review-summary-terrestrial-total', null);
        setPhaseTotalValue('#sdpi-summary-maritime-total', null);
        setPhaseTotalValue('#sdpi-summary-terrestrial-total', null);
    }

    function updateReviewSummary(details) {
        if (!details) { return; }

        setReviewSummaryValue('#sdpi-review-summary-pickup', details.pickup || '');
        setReviewSummaryValue('#sdpi-review-summary-delivery', details.delivery || '');
        setReviewSummaryValue('#sdpi-review-summary-trailer', details.trailer || '');
        setReviewSummaryValue('#sdpi-review-summary-vehicle', details.vehicle || '');

        if (details.transport && details.transport.trim() !== '') {
            $('#sdpi-review-summary-transport-type-row').css('display', 'flex');
            setReviewSummaryValue('#sdpi-review-summary-transport-type', details.transport);
        } else {
            $('#sdpi-review-summary-transport-type-row').hide();
            setReviewSummaryValue('#sdpi-review-summary-transport-type', '');
        }

        if (typeof details.inoperable !== 'undefined') {
            updateSurchargeDisplay(
                setReviewSummaryValue,
                '#sdpi-review-summary-inoperable',
                !!details.inoperable,
                !!details.surchargeHighlight,
                details.inoperableFee,
                true
            );
        } else {
            clearSurchargeDisplay(setReviewSummaryValue, '#sdpi-review-summary-inoperable');
        }

        if (typeof details.electric !== 'undefined') {
            updateSurchargeDisplay(
                setReviewSummaryValue,
                '#sdpi-review-summary-electric',
                !!details.electric,
                !!details.surchargeHighlight,
                details.electricSurcharge,
                true
            );
        } else {
            clearSurchargeDisplay(setReviewSummaryValue, '#sdpi-review-summary-electric');
        }

        var hasMaritimeTotal = typeof details.maritimeTotal !== 'undefined';
        var hasTerrestrialTotal = typeof details.terrestrialTotal !== 'undefined';
        if (hasMaritimeTotal || hasTerrestrialTotal) {
            updatePhaseTotals(
                hasMaritimeTotal ? details.maritimeTotal : null,
                hasTerrestrialTotal ? details.terrestrialTotal : null
            );
        }

        updatePaymentSummary(details);
        updateReviewSummaryPrice(details.price || '');
    }

    function updatePaymentSummary(details) {
        if (!details) { return; }

        setPaymentSummaryValue('#sdpi-payment-summary-pickup', details.pickup || '');
        setPaymentSummaryValue('#sdpi-payment-summary-delivery', details.delivery || '');
        setPaymentSummaryValue('#sdpi-payment-summary-trailer', details.trailer || '');
        setPaymentSummaryValue('#sdpi-payment-summary-vehicle', details.vehicle || '');

        if (details.transport && details.transport.trim() !== '') {
            $('#sdpi-payment-summary-transport-type-row').css('display', 'flex');
            setPaymentSummaryValue('#sdpi-payment-summary-transport-type', details.transport);
        } else {
            $('#sdpi-payment-summary-transport-type-row').hide();
            setPaymentSummaryValue('#sdpi-payment-summary-transport-type', '');
        }

        if (typeof details.inoperable !== 'undefined') {
            updateSurchargeDisplay(
                setPaymentSummaryValue,
                '#sdpi-payment-summary-inoperable',
                !!details.inoperable,
                !!details.surchargeHighlight,
                details.inoperableFee,
                true
            );
        } else {
            clearSurchargeDisplay(setPaymentSummaryValue, '#sdpi-payment-summary-inoperable');
        }

        if (typeof details.electric !== 'undefined') {
            updateSurchargeDisplay(
                setPaymentSummaryValue,
                '#sdpi-payment-summary-electric',
                !!details.electric,
                !!details.surchargeHighlight,
                details.electricSurcharge,
                true
            );
        } else {
            clearSurchargeDisplay(setPaymentSummaryValue, '#sdpi-payment-summary-electric');
        }

        updatePaymentSummaryPrice(details.price || '');

        var hasMaritimeTotal = typeof details.maritimeTotal !== 'undefined';
        if (hasMaritimeTotal) {
            setPhaseTotalValue('#sdpi-payment-summary-maritime-total', normalizePhaseAmount(details.maritimeTotal));
        }

        var hasTerrestrialTotal = typeof details.terrestrialTotal !== 'undefined';
        if (hasTerrestrialTotal) {
            setPhaseTotalValue('#sdpi-payment-summary-terrestrial-total', normalizePhaseAmount(details.terrestrialTotal));
        }
    }

    function lockPricingForm(data) {
        var $form = $('#sdpi-pricing-form');
        if (!$form.length) { return; }

        formLocked = true;
        $form.addClass('sdpi-form-locked');

        $form.find('input[type="text"], input[type="number"], input[type="tel"], input[type="email"], textarea')
            .not(':hidden')
            .prop('readonly', true);

        $form.find('select, input[type="checkbox"], input[type="radio"]').prop('disabled', true);

        $('#sdpi-submit-btn').hide();
        $('.sdpi-clear-btn').hide();

        var $inlineBtn = $('#sdpi-inline-continue-btn');
        if ($inlineBtn.length) {
            $inlineBtn.show().prop('disabled', false);
            if (data) {
                try {
                    $inlineBtn.attr('data-quote', JSON.stringify(data));
                } catch (error) {
                    $inlineBtn.removeAttr('data-quote');
                }
                $inlineBtn.data('quote', data);
            }
        }

        toggleContinueButton(data || $('#sdpi-summary-continue-btn').data('quote'));
    }

    function unlockPricingForm() {
        var $form = $('#sdpi-pricing-form');
        if (!$form.length) { return; }

        formLocked = false;
        $form.removeClass('sdpi-form-locked');

        $form.find('input[type="text"], input[type="number"], input[type="tel"], input[type="email"], textarea')
            .prop('readonly', false);

        $form.find('select, input[type="checkbox"], input[type="radio"]').prop('disabled', false);

        $('#sdpi-submit-btn').show();
        $('.sdpi-clear-btn').show();

        var $inlineBtn = $('#sdpi-inline-continue-btn');
        if ($inlineBtn.length) {
            $inlineBtn.hide().prop('disabled', false).removeAttr('data-quote').removeData('quote');
        }

        $('#sdpi-summary-panel').show();
        $('#sdpi-review-summary-panel').hide();
    }

    function updateLiveSummary() {
        var pickupCity = $('#sdpi_pickup_city').val();
        var pickupZip = $('#sdpi_pickup_zip').val();
        var deliveryCity = $('#sdpi_delivery_city').val();
        var deliveryZip = $('#sdpi_delivery_zip').val();
        var trailerType = $('#sdpi_trailer_type').val();
        var vehicleType = $('#sdpi_vehicle_type').val();
        var vehicleMake = $('#sdpi_vehicle_make').val();
        var vehicleModel = $('#sdpi_vehicle_model').val();
        var vehicleYear = $('#sdpi_vehicle_year').val();

        setSummaryValue('#sdpi-summary-pickup', formatCityDisplay(pickupCity, pickupZip));
        setSummaryValue('#sdpi-summary-delivery', formatCityDisplay(deliveryCity, deliveryZip));

        var trailerLabel = '';
        if (trailerType === 'open') {
            trailerLabel = 'Abierto';
        } else if (trailerType === 'enclosed') {
            trailerLabel = 'Cerrado';
        } else if (trailerType) {
            trailerLabel = trailerType;
        }
        setSummaryValue('#sdpi-summary-trailer', trailerLabel);

        var vehicleParts = [];
        if (vehicleYear) { vehicleParts.push(vehicleYear); }
        if (vehicleMake) { vehicleParts.push(vehicleMake); }
        if (vehicleModel) { vehicleParts.push(vehicleModel); }
        if (!vehicleParts.length && vehicleType) {
            vehicleParts.push(vehicleType);
        }
        setSummaryValue('#sdpi-summary-vehicle', vehicleParts.join(' '));

        var isInoperable = $('#sdpi_vehicle_inoperable').is(':checked');
        var isElectric = $('#sdpi_vehicle_electric').is(':checked');
        updateSurchargeDisplay(setSummaryValue, '#sdpi-summary-inoperable', isInoperable, false);
        updateSurchargeDisplay(setSummaryValue, '#sdpi-summary-electric', isElectric, false);

        setSummaryValue('#sdpi-summary-transport-type', '');
        if ($('#sdpi-pricing-form').is(':visible')) {
            if (vehicleType) {
                setProgressStep(2);
            } else {
                setProgressStep(1);
            }
        }
    }

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

        updateLiveSummary();
    });
    $("#sdpi_trailer_type, #sdpi_vehicle_type").on("change", updateLiveSummary);
    $("#sdpi_vehicle_make, #sdpi_vehicle_model, #sdpi_vehicle_year").on("input change", updateLiveSummary);
    $('#sdpi_vehicle_inoperable, #sdpi_vehicle_electric').on('change', updateLiveSummary);


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

        updateLiveSummary();
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
    
    // MODIFICADO: Handle form submission - Nuevo flujo con captura de contacto despuÃƒÆ’Ã‚Â©s
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
        toggleContinueButton();
        updateSummaryFooter('info', 'Calculando tu cotizacion...');
        $('#sdpi-submit-btn').prop('disabled', true).text('Calculando cotizacion...');
        setSummaryPrice('');
        setProgressStep(2);
        // Make AJAX request
        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                $('#sdpi-loading').hide();
                $('#sdpi-submit-btn').prop('disabled', false).text('Obtener Cotizacion');

                if (response.success) {
                    // NUEVO FLUJO: Verificar si necesitamos informacion de contacto
                    if (response.data.needs_contact_info) {
                        console.log('Necesita informacion de contacto, mostrando formulario...');
                        window.currentQuoteData = response.data.quote_data;
                        toggleContinueButton();
                        updateSummaryFooter('info', 'Ingresa tus datos de contacto para ver el total final.');
                        showContactForm();
                    } else {
                        // Este caso no deberia ocurrir en el nuevo flujo, pero lo dejamos por compatibilidad
                        displayQuoteResults(response.data);
                    lockPricingForm(response.data);
                    }
                } else {
                    toggleContinueButton();
                    setSummaryPrice('');
                    updateSummaryFooter('error', response.data || 'Error al obtener la cotizacion.');
                }
            },
            error: function(xhr, status, error) {
                $('#sdpi-loading').hide();
                $('#sdpi-submit-btn').prop('disabled', false).text('Obtener Cotizacion');

                var errorMessage = 'Error al conectar con el servidor.';
                if (status === 'timeout') {
                    errorMessage = 'La solicitud tardo demasiado. Intente nuevamente.';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                }

                toggleContinueButton();
                setSummaryPrice('');
                updateSummaryFooter('error', errorMessage);
            }
        });
    });
    
    // NUEVA FUNCIÃƒÆ’Ã¢â‚¬Å“N: Mostrar formulario de contacto
    function showContactForm() {
        $('#sdpi-contact-form-container').remove();
        toggleContinueButton();
        setSummaryPrice('');

        // Ocultar el formulario principal
        $('#sdpi-pricing-form').hide();
        setProgressStep(3);
        
        // Crear y mostrar el formulario de contacto
        var privacyUrl = (typeof sdpi_legal !== 'undefined' && sdpi_legal.privacy_policy_url) ? sdpi_legal.privacy_policy_url : '#';
        var termsUrl = (typeof sdpi_legal !== 'undefined' && sdpi_legal.terms_conditions_url) ? sdpi_legal.terms_conditions_url : '#';
        var privacyText = (typeof sdpi_legal !== 'undefined' && sdpi_legal.privacy_text) ? sdpi_legal.privacy_text : 'Política de Privacidad';
        var termsText = (typeof sdpi_legal !== 'undefined' && sdpi_legal.terms_text) ? sdpi_legal.terms_text : 'Términos y Condiciones';
        var legalCheckboxText = (typeof sdpi_legal !== 'undefined' && sdpi_legal.checkbox_text) ? sdpi_legal.checkbox_text : 'Al continuar aceptas nuestra {privacy_link} y nuestros {terms_link}.';

        var privacyLink = '<a href="' + privacyUrl + '" target="_blank" rel="noopener noreferrer">' + privacyText + '</a>';
        var termsLink = '<a href="' + termsUrl + '" target="_blank" rel="noopener noreferrer">' + termsText + '</a>';
        legalCheckboxText = legalCheckboxText.replace('{privacy_link}', privacyLink).replace('{terms_link}', termsLink);

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

                    <div class="sdpi-form-group sdpi-form-legal">
                        <label class="sdpi-checkbox-label" for="sdpi_contact_accept">
                            <input type="checkbox" id="sdpi_contact_accept" name="contact_accept">
                            <span class="sdpi-checkbox-text">` + legalCheckboxText + `</span>
                        </label>
                        <p class="sdpi-form-error" id="sdpi-contact-legal-error" style="display:none;"></p>
                    </div>

                    <div class="sdpi-form-submit">
                        <button type="submit" class="sdpi-submit-btn" id="sdpi-contact-submit-btn">Ver Mi Cotización</button>
                        <button type="button" class="sdpi-clear-btn" id="sdpi-contact-back-btn">Volver</button>
                    </div>
                </form>
            </div>
        `;
        
        // Insertar el formulario de contacto despues del formulario principal
        $('#sdpi-pricing-form').after(contactFormHtml);
        
        // Manejar el envÃƒÆ’Ã‚Â­o del formulario de contacto
        $('#sdpi-contact-form').on('submit', function(e) {
            e.preventDefault();
            submitContactInfo();
        });

        $('#sdpi_contact_accept').on('change', function() {
            if ($(this).is(':checked')) {
                $('#sdpi-contact-legal-error').hide().text('');
            }
        });
        
        // Manejar el boton de volver
        $('#sdpi-contact-back-btn').on('click', function() {
            $('#sdpi-contact-form-container').remove();
            setProgressStep($("#sdpi_vehicle_type").val() ? 2 : 1);
            updateLiveSummary();
            $('#sdpi-pricing-form').show();
            toggleContinueButton();
            updateSummaryFooter('info', defaultSummaryFooter);
        });
    }
    
    // NUEVA FUNCION: Enviar informacion de contacto y obtener precio final
    function submitContactInfo() {
        var legalErrorMessage = (typeof sdpi_legal !== 'undefined' && sdpi_legal.error_message) ? sdpi_legal.error_message : 'Debes aceptar la Política de Privacidad y los Términos y Condiciones para continuar.';
        var legalErrorContainer = $('#sdpi-contact-legal-error');
        legalErrorContainer.hide().text('');

        if (!$('#sdpi_contact_accept').is(':checked')) {
            legalErrorContainer.text(legalErrorMessage).show();
            return;
        }

        var contactData = {
            action: 'sdpi_finalize_quote_with_contact',
            nonce: sdpi_ajax.nonce,
            client_name: $('#sdpi_contact_name').val().trim(),
            client_phone: $('#sdpi_contact_phone').val().trim(),
            client_email: $('#sdpi_contact_email').val().trim(),
            quote_data: JSON.stringify(window.currentQuoteData)
        };

        // ValidaciON BASICA
        if (!contactData.client_name || !contactData.client_phone || !contactData.client_email) {
            alert('Todos los campos de contacto son requeridos.');
            return;
        }

        // vALIDACION de email
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(contactData.client_email)) {
            alert('Por favor ingrese un correo eletrónico válido.');
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
                    lockPricingForm(response.data);
                    
                    // Mostrar el formulario principal nuevamente (por si quieren hacer otra cotizaciÃƒÆ’Ã‚Â³n)
                    $('#sdpi-pricing-form').show();
                } else {
                    $('#sdpi-contact-submit-btn').prop('disabled', false).text('Ver Mi Cotización');
                    alert(response.data || 'Error al procesar la información de contacto.');
                    updateSummaryFooter('error', response.data || 'Error al procesar la informacion de contacto.');
                }
            },
            error: function(xhr, status, error) {
                $('#sdpi-loading').hide();
                $('#sdpi-contact-submit-btn').prop('disabled', false).text('Ver Mi Cotización');
                alert('Error de conexión. Por favor intente nuevamente.');
                updateSummaryFooter('error', 'Error de conexion. Por favor intente nuevamente.');
            }
        });
    }
    
    // FUNCIÓN MODIFICADA: Mostrar resultados de cotización
    function displayQuoteResults(data) {
        resetPaymentPanel();
        data.transport_type = data.transport_type || (data.maritime_involved ? 'maritime' : 'terrestrial');

        var formattedPrice = formatCurrency(data.final_price);
        setSummaryPrice(formattedPrice);

        if (data.transport_type) {
            setSummaryValue('#sdpi-summary-transport-type', formatTransportLabel(data.transport_type));
        }

        var isMaritime = data.transport_type === 'maritime';
        var inoperableFee = parseFloat(data.inoperable_fee || 0);
        var electricSurcharge = parseFloat(data.electric_surcharge || 0);

        if (isNaN(inoperableFee)) { inoperableFee = 0; }
        if (isNaN(electricSurcharge)) { electricSurcharge = 0; }

        updateSurchargeDisplay(
            setSummaryValue,
            '#sdpi-summary-inoperable',
            !!data.vehicle_inoperable,
            isMaritime,
            inoperableFee,
            true
        );

        updateSurchargeDisplay(
            setSummaryValue,
            '#sdpi-summary-electric',
            !!data.vehicle_electric,
            isMaritime,
            electricSurcharge,
            true
        );

        var phaseTotals = calculatePhaseTotals(data, isMaritime, electricSurcharge, inoperableFee);
        updatePhaseTotals(phaseTotals.maritime, phaseTotals.terrestrial);
        data.total_maritime_cost = phaseTotals.maritime;
        data.total_terrestrial_cost = phaseTotals.terrestrial;
        if (window.currentQuoteData && window.currentQuoteData !== data) {
            window.currentQuoteData.total_maritime_cost = phaseTotals.maritime;
            window.currentQuoteData.total_terrestrial_cost = phaseTotals.terrestrial;
        }

        setProgressStep(4);
        toggleContinueButton(data);
        updateSummaryFooter('success', 'Cotizacion lista. Revisa el total y continua.');
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
                if (response && response.success && response.data) {
                    var paymentData = response.data;
                    if (window.currentQuoteData) {
                        window.currentQuoteData.session_id = paymentData.session_id || window.currentQuoteData.session_id;
                    }
                    showPaymentPanel(paymentData);
                    if (btn) {
                        btn.prop('disabled', false).text('Proceder al Checkout');
                        btn.data('quote', quoteData);
                    }
                } else {
                    var errorMessage = 'No fue posible preparar el pago.';
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorMessage = response.data;
                        } else if (response.data.message) {
                            errorMessage = response.data.message;
                        }
                    }
                    alert(errorMessage);
                    updateSummaryFooter('error', errorMessage);
                    if (btn) {
                        btn.prop('disabled', false).text(originalText);
                    }
                }
            },
            error: function() {
                var message = 'Error de conexión al preparar el pago.';
                alert(message);
                updateSummaryFooter('error', 'Error de conexion. Por favor intente nuevamente.');
                if (btn) {
                    btn.prop('disabled', false).text(originalText);
                }
            }
        });
    }

    $(document).on('click', '#sdpi-payment-submit', function() {
        if (!paymentEnabled) {
            showPaymentError(paymentBlockedMessage || 'Los pagos no están disponibles en este momento.');
            return;
        }

        var $button = $(this);
        var cardNumber = ($('#sdpi-card-number').val() || '').replace(/\s+/g, '');
        var expMonth = ($('#sdpi-card-exp-month').val() || '').replace(/\D+/g, '');
        var expYear = ($('#sdpi-card-exp-year').val() || '').replace(/\D+/g, '');
        var cvv = ($('#sdpi-card-cvv').val() || '').replace(/\D+/g, '');
        var zip = ($('#sdpi-card-zip').val() || '').replace(/\s+/g, '');

        if (!/^\d{13,19}$/.test(cardNumber)) {
            showPaymentError('Número de tarjeta inválido.');
            return;
        }
        if (!/^\d{1,2}$/.test(expMonth)) {
            showPaymentError('Mes de expiración inválido.');
            return;
        }
        if (!/^\d{2,4}$/.test(expYear)) {
            showPaymentError('Año de expiración inválido.');
            return;
        }
        if (!/^\d{3,4}$/.test(cvv)) {
            showPaymentError('Código de seguridad inválido.');
            return;
        }

        if (expMonth.length === 1) {
            expMonth = '0' + expMonth;
        }
        if (expYear.length === 2) {
            expYear = '20' + expYear;
        }

        var payload = {
            action: 'sdpi_process_payment',
            nonce: sdpi_ajax.nonce,
            card_number: cardNumber,
            card_exp_month: expMonth,
            card_exp_year: expYear,
            card_cvv: cvv,
            card_zip: zip
        };
        if (paymentContext && paymentContext.session_id) {
            payload.session_id = paymentContext.session_id;
        }

        $button.prop('disabled', true).text('Procesando...');
        updateSummaryFooter('info', 'Procesando el pago, por favor espera...');
        $('#sdpi-payment-feedback').hide();

        $.ajax({
            url: sdpi_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: payload,
            success: function(response) {
                if (response && response.success && response.data) {
                    var successMessage = response.data.message || 'Pago procesado exitosamente.';
                    showPaymentSuccess(successMessage);
                    paymentContext = null;
                    var redirectUrl = response.data.redirect_url;
                    if (!redirectUrl && typeof sdpi_payment !== 'undefined' && sdpi_payment.success_url) {
                        redirectUrl = sdpi_payment.success_url;
                    }
                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }
                } else {
                    var errorData = response ? response.data : null;
                    var errorMessage = 'El pago fue rechazado.';
                    if (typeof errorData === 'string') {
                        errorMessage = errorData;
                    } else if (errorData && errorData.message) {
                        errorMessage = errorData.message;
                    }
                    showPaymentError(errorMessage);
                    var errorRedirect = errorData && errorData.redirect_url ? errorData.redirect_url : '';
                    if (!errorRedirect && typeof sdpi_payment !== 'undefined' && sdpi_payment.error_url) {
                        errorRedirect = sdpi_payment.error_url;
                    }
                    if (errorRedirect) {
                        window.location.href = errorRedirect;
                        return;
                    }
                }
            },
            error: function() {
                showPaymentError('Error de conexión al procesar el pago.');
            },
            complete: function() {
                if ($button) {
                    $button.prop('disabled', false).text('Pagar ahora');
                }
                $('#sdpi-card-number').val('');
                $('#sdpi-card-cvv').val('');
            }
        });
    });

    // Clear form button
    $('<button type="button" class="sdpi-clear-btn">Limpiar Formulario</button>').insertAfter('#sdpi-submit-btn');
    
    $('.sdpi-clear-btn').on('click', function() {
        $('#sdpi-pricing-form')[0].reset();
        $('#sdpi-loading').hide();
        toggleContinueButton();
        setSummaryPrice('');
        resetReviewSummary();
        updateSummaryFooter('info', defaultSummaryFooter);
        updateLiveSummary();
        setProgressStep(1);
        unlockPricingForm();
        resetPaymentPanel();
    });

    // Handle payment button click
    $(document).on('click', '.sdpi-pay-btn', function() {
        var buttonId = $(this).attr('id');
        if (buttonId !== 'sdpi-summary-continue-btn' && buttonId !== 'sdpi-inline-continue-btn') {
            return;
        }
        var quoteData = $(this).data('quote')
            || $('#sdpi-additional-info').data('quote')
            || window.currentQuoteData
            || {};
        quoteData.transport_type = quoteData.transport_type || (quoteData.maritime_involved ? 'maritime' : 'terrestrial');
        var isMaritime = quoteData.transport_type === 'maritime';
        var $additionalInfoContainer = $('#sdpi-additional-info');
        var $inlandForm = $('#sdpi-additional-info-form');
        var $maritimeForm = $('#sdpi-maritime-info-form');
        var $introTitle = $('#sdpi-additional-info .sdpi-review-intro h3');
        var $introSubtitle = $('#sdpi-additional-info .sdpi-review-intro p');
        var $introChecklist = $('#sdpi-additional-info .sdpi-review-checklist');

        $('#sdpi-payment-screen').hide();
        $('#sdpi-payment-summary-panel').hide();

        try {
            var pickupLabel = quoteData.pickup_city ? (quoteData.pickup_city + ' (' + quoteData.pickup_zip + ')') : ($('#sdpi_pickup_city').val() + ' (' + $('#sdpi_pickup_zip').val() + ')');
            var deliveryLabel = quoteData.delivery_city ? (quoteData.delivery_city + ' (' + quoteData.delivery_zip + ')') : ($('#sdpi_delivery_city').val() + ' (' + $('#sdpi_delivery_zip').val() + ')');
            var trailerText = $('#sdpi_trailer_type option[value="' + (quoteData.trailer_type || '') + '"]').text() || $('#sdpi_trailer_type option:selected').text();
            var vehicleYear = $('#sdpi_vehicle_year').val();
            var vehicleMake = $('#sdpi_vehicle_make').val();
            var vehicleModel = $('#sdpi_vehicle_model').val();
            var vehicleTypeLabel = $('#sdpi_vehicle_type option:selected').text() || (quoteData.vehicle_type || '');
            var vehicleSummary = (vehicleYear + ' ' + vehicleMake + ' ' + vehicleModel).trim();
            var isInoperable = !!quoteData.vehicle_inoperable;
            var isElectric = !!quoteData.vehicle_electric;
            var conditionsValue = isInoperable ? 'Non-Running' : 'Running';
            var fuelValue = isElectric ? 'Electric' : 'Gasoline';
            var inoperableFee = parseFloat(quoteData.inoperable_fee || 0);
            var electricSurcharge = parseFloat(quoteData.electric_surcharge || 0);

            if (isNaN(inoperableFee)) { inoperableFee = 0; }
            if (isNaN(electricSurcharge)) { electricSurcharge = 0; }

            if (!vehicleSummary && vehicleTypeLabel) {
                vehicleSummary = vehicleTypeLabel;
            }

            var formattedPrice = formatCurrency(quoteData.final_price);

            var phaseTotals = calculatePhaseTotals(quoteData, isMaritime, electricSurcharge, inoperableFee);
            updatePhaseTotals(phaseTotals.maritime, phaseTotals.terrestrial);
            quoteData.total_maritime_cost = phaseTotals.maritime;
            quoteData.total_terrestrial_cost = phaseTotals.terrestrial;
            if (window.currentQuoteData && window.currentQuoteData !== quoteData) {
                window.currentQuoteData.total_maritime_cost = phaseTotals.maritime;
                window.currentQuoteData.total_terrestrial_cost = phaseTotals.terrestrial;
            }

            setSummaryValue('#sdpi-summary-pickup', pickupLabel);
            setSummaryValue('#sdpi-summary-delivery', deliveryLabel);
            setSummaryValue('#sdpi-summary-trailer', trailerText);
            setSummaryValue('#sdpi-summary-vehicle', vehicleSummary);
            setSummaryPrice(formattedPrice);
            setSummaryValue('#sdpi-summary-transport-type', formatTransportLabel(isMaritime ? 'maritime' : 'terrestrial'));
            updateSurchargeDisplay(setSummaryValue, '#sdpi-summary-inoperable', isInoperable, isMaritime, inoperableFee, true);
            updateSurchargeDisplay(setSummaryValue, '#sdpi-summary-electric', isElectric, isMaritime, electricSurcharge, true);

            updateReviewSummary({
                pickup: pickupLabel,
                delivery: deliveryLabel,
                trailer: trailerText,
                vehicle: vehicleSummary,
                transport: formatTransportLabel(isMaritime ? 'maritime' : 'terrestrial'),
                price: formattedPrice,
                inoperable: isInoperable,
                electric: isElectric,
                surchargeHighlight: isMaritime,
                inoperableFee: inoperableFee,
                electricSurcharge: electricSurcharge,
                maritimeTotal: phaseTotals.maritime,
                terrestrialTotal: phaseTotals.terrestrial
            });

            if ($additionalInfoContainer.length) {
                $additionalInfoContainer.attr('data-transport', isMaritime ? 'maritime' : 'terrestrial');
            }

            $('#sdpi-summary-panel').hide();
            $('#sdpi-review-summary-panel').show();
            var summaryFooterMessage = isMaritime
                ? 'Completa la informacion maritima para continuar.'
                : 'Completa la informacion de recogida y entrega para continuar.';
            updateSummaryFooter('info', summaryFooterMessage);

            $('#sdpi_ai_vehicle_year').val(vehicleYear);
            $('#sdpi_ai_vehicle_make').val(vehicleMake);
            $('#sdpi_ai_vehicle_model').val(vehicleModel);

            $('#sdpi_m_vehicle_year').val(vehicleYear);
            $('#sdpi_m_vehicle_make').val(vehicleMake);
            $('#sdpi_m_vehicle_model').val(vehicleModel);
            $('#sdpi_m_vehicle_type').val(vehicleTypeLabel);
            $('#sdpi_m_vehicle_conditions').val(conditionsValue).prop('disabled', true);
            $('#sdpi_m_vehicle_conditions_value').val(conditionsValue);
            $('#sdpi_m_fuel_type').val(fuelValue).prop('disabled', true);
            $('#sdpi_m_fuel_type_value').val(fuelValue);

            $('#sdpi_ai_p_city').val($('#sdpi_pickup_city').val());
            $('#sdpi_ai_p_zip').val($('#sdpi_pickup_zip').val());
            $('#sdpi_ai_d_city').val($('#sdpi_delivery_city').val());
            $('#sdpi_ai_d_zip').val($('#sdpi_delivery_zip').val());

            if (isMaritime) {
                $('#sdpi_transport_type').val('maritime');
                $inlandForm.addClass('sdpi-hidden').hide();
                $maritimeForm.removeClass('sdpi-hidden').show();
                if ($introTitle.length) {
                    $introTitle.text('Informacion adicional para transporte maritimo');
                }
                if ($introSubtitle.length) {
                    $introSubtitle.text('Completa los datos requeridos para coordinar el envio maritimo.');
                }
                if ($introChecklist.length) {
                    $introChecklist.html('<li>Verifica los datos del shipper y consignee.</li><li>Confirma los detalles de Pick Up y Drop Off seg&uacute;n corresponda.</li><li>Guarda los cambios para generar el enlace de pago.</li>');
                }
                $('#sdpi-review-summary-footer-text').text(summaryFooterMessage);

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
                $maritimeForm.hide();
                toggleMaritimeSection('#sdpi-pickup-section', false, maritimePickupFields);
                toggleMaritimeSection('#sdpi-dropoff-section', false, maritimeDropoffFields);
                $inlandForm.removeClass('sdpi-hidden').show();
                if ($introTitle.length) {
                    $introTitle.text(additionalInfoDefaults.title);
                }
                if ($introSubtitle.length) {
                    $introSubtitle.text(additionalInfoDefaults.subtitle);
                }
                if ($introChecklist.length && typeof additionalInfoDefaults.checklist !== 'undefined') {
                    $introChecklist.html(additionalInfoDefaults.checklist);
                }
                if (additionalInfoDefaults.reviewFooter) {
                    $('#sdpi-review-summary-footer-text').text(additionalInfoDefaults.reviewFooter);
                }
                $('#sdpi_ai_p_city, #sdpi_ai_p_zip, #sdpi_ai_d_city, #sdpi_ai_d_zip').prop('readonly', true);
            }

            $('#sdpi-pricing-form').hide();
            $('#sdpi-additional-info').show();
            $('html, body').animate({ scrollTop: $('#sdpi-additional-info').offset().top - 40 }, 300);

            $('#sdpi-additional-info').data('quote', quoteData);
            window.currentQuoteData = quoteData;
        } catch (error) {
            console.error('Error preparando pantalla adicional:', error);
        }
    });



    // BotÃƒÆ’Ã‚Â³n cancelar en pantalla adicional
    $(document).on('click', '#sdpi-ai-cancel', function() {
        $('#sdpi-additional-info').hide();
        $('#sdpi-pricing-form').show();
        $('#sdpi-summary-panel').show();
        $('#sdpi-review-summary-panel').hide();
        $('#sdpi-payment-screen').hide();
        $('#sdpi-payment-summary-panel').hide();
        updateSummaryFooter('info', defaultSummaryFooter);
    });

    $(document).on('click', '#sdpi-payment-back-btn', function() {
        $('#sdpi-payment-screen').hide();
        $('#sdpi-payment-summary-panel').hide();
        $('#sdpi-additional-info').show();
        $('#sdpi-review-summary-panel').show();
        var transport = $('#sdpi-additional-info').attr('data-transport');
        var summaryFooterMessage = transport === 'maritime'
            ? 'Completa la informacion maritima para continuar.'
            : 'Completa la informacion de recogida y entrega para continuar.';
        updateSummaryFooter('info', summaryFooterMessage);
        $('#sdpi-ai-continue').prop('disabled', false).text('Proceder al Checkout');
        $('#sdpi-maritime-continue').prop('disabled', false).text('Proceder al Checkout');
        if ($('#sdpi-additional-info').length) {
            $('html, body').animate({ scrollTop: $('#sdpi-additional-info').offset().top - 40 }, 300);
        }
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
            alert('Por favor complete todos los campos obligatorios con Informacion válida.');
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
                    alert(resp.data || 'Error al guardar la Informacion adicional.');
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
                alert('Error de conexion al guardar la Informacion adicional.');
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    $(document).on('click', '#sdpi-maritime-cancel', function() {
        $('#sdpi-additional-info').hide();
        $('#sdpi-pricing-form').show();
        $('#sdpi-summary-panel').show();
        $('#sdpi-review-summary-panel').hide();
        updateSummaryFooter('info', defaultSummaryFooter);
        resetPaymentPanel();
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
            vehicle_conditions: $('#sdpi_m_vehicle_conditions_value').val() || $('#sdpi_m_vehicle_conditions').val(),
            fuel_type: $('#sdpi_m_fuel_type_value').val() || $('#sdpi_m_fuel_type').val(),
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
                    alert(resp.data || 'Error al guardar la Informacion maritima.');
                    btn.prop('disabled', false).text(originalText);
                    return;
                }

                quoteData.transport_type = 'maritime';
                quoteData.maritime_details = resp.data && resp.data.maritime_details ? resp.data.maritime_details : null;
                quoteData.maritime_direction = resp.data && resp.data.direction ? resp.data.direction : payload.maritime_direction;
                if (resp.data && resp.data.shipping_summary) {
                    quoteData.shipping = resp.data.shipping_summary;
                }
                window.currentQuoteData = quoteData;
                $('#sdpi-maritime-info-form').data('quote', quoteData);
                $('#sdpi-additional-info').data('quote', quoteData);

                initiateCheckout(btn, quoteData, originalText);
            },
            error: function() {
                alert('Error de conexion al guardar la Informacion maritima.');
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
    unlockPricingForm();
    setSummaryPrice('');
    toggleContinueButton();
    resetPaymentPanel();
    resetReviewSummary();
    updateSummaryFooter('info', defaultSummaryFooter);
    updateLiveSummary();
    if (!paymentEnabled && paymentBlockedMessage) {
        updateSummaryFooter('error', paymentBlockedMessage);
    }
});





<?php
/**
 * Maritime Transportation Logic
 * Handles San Juan, PR maritime routes and port selection
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDPI_Maritime {
    
    // Maritime rates (fixed)
    const MARITIME_RATES = [
        'san_juan_to_jacksonville' => 895.00,
        'san_juan_to_penn_terminals' => 1350.00,
        'jacksonville_to_san_juan' => 1150.00,
        'penn_terminals_to_san_juan' => 1675.00
    ];
    
    // Port ZIP codes - using major cities that API definitely recognizes
    const PENN_TERMINALS_ZIP = '10001'; // New York, NY (major city for Northeast)
    const JACKSONVILLE_ZIP = '32226'; // Jacksonville, FL (actual port ZIP)
    const SAN_JUAN_ZIP = '00901';
    
    // Port display names (different from API cities)
    const PENN_TERMINALS_NAME = 'Eddystone, PA';
    const JACKSONVILLE_NAME = 'Jacksonville, FL';
    
    const INOPERABLE_FEE = 500.00;
    
    // ZIP ranges for Penn Terminals (inclusive)
    const PENN_TERMINALS_RANGES = [
        'CT' => ['06002', '06903'],
        'DC' => ['20032', '20032'],
        'DE' => ['19701', '19901'],
        'MA' => ['01001', '02760'],
        'MD' => ['20601', '21826'],
        'ME' => ['03909', '04101'],
        'NH' => ['03060', '03865'],
        'NJ' => ['07001', '08902'],
        'NY' => ['10029', '14850'],
        'PA' => ['01843', '19610'],
        'RI' => ['02840', '02919'],
        'VA' => ['20120', '23502']
    ];
    
    /**
     * Check if a ZIP code belongs to Puerto Rico.
     * Any ZIP in the 00600-00999 range should be treated as San Juan (00901) for maritime routing.
     */
    public static function is_san_juan_zip($zip) {
        if ($zip === null) {
            return false;
        }

        // Extract the first 5 digits from the provided value
        $zip_digits = preg_replace('/\D/', '', (string) $zip);
        if (strlen($zip_digits) < 5) {
            $zip_digits = str_pad($zip_digits, 5, '0', STR_PAD_LEFT);
        }
        $zip5 = substr($zip_digits, 0, 5);
        if (strlen($zip5) !== 5) {
            return false;
        }

        $prefix = substr($zip5, 0, 3);
        return in_array($prefix, array('006', '007', '008', '009'), true);
    }
    
    /**
     * Check if a location involves maritime transport
     */
    public static function involves_maritime($pickup_zip, $delivery_zip) {
        return self::is_san_juan_zip($pickup_zip) || self::is_san_juan_zip($delivery_zip);
    }
    
    /**
     * Determine the appropriate US port based on continental ZIP
     */
    public static function get_us_port($continental_zip) {
        // Check if ZIP falls within Penn Terminals ranges
        foreach (self::PENN_TERMINALS_RANGES as $state => $range) {
            if (self::zip_in_range($continental_zip, $range[0], $range[1])) {
                return [
                    'port' => self::PENN_TERMINALS_NAME,
                    'zip' => self::PENN_TERMINALS_ZIP,
                    'state' => 'PA'
                ];
            }
        }
        
        // Default to Jacksonville for all other continental ZIPs
        return [
            'port' => self::JACKSONVILLE_NAME,
            'zip' => self::JACKSONVILLE_ZIP,
            'state' => 'FL'
        ];
    }
    
    /**
     * Check if ZIP is within a range (inclusive)
     */
    private static function zip_in_range($zip, $min, $max) {
        return $zip >= $min && $zip <= $max;
    }
    
    /**
     * Get maritime rate for a route
     */
    public static function get_maritime_rate($from_zip, $to_zip) {
        $from_san_juan = self::is_san_juan_zip($from_zip);
        $to_san_juan = self::is_san_juan_zip($to_zip);
        
        if (!$from_san_juan && !$to_san_juan) {
            return 0; // No maritime transport needed
        }
        
        // Determine continental port
        $continental_zip = $from_san_juan ? $to_zip : $from_zip;
        $port = self::get_us_port($continental_zip);
        
        // Determine rate based on direction and port
        if ($from_san_juan) {
            // From San Juan to continental
            return $port['zip'] === self::PENN_TERMINALS_ZIP ? 
                self::MARITIME_RATES['san_juan_to_penn_terminals'] : 
                self::MARITIME_RATES['san_juan_to_jacksonville'];
        } else {
            // From continental to San Juan
            return $port['zip'] === self::PENN_TERMINALS_ZIP ? 
                self::MARITIME_RATES['penn_terminals_to_san_juan'] : 
                self::MARITIME_RATES['jacksonville_to_san_juan'];
        }
    }
    
    /**
     * Calculate total cost with maritime transport
     */
    public static function calculate_maritime_cost($pickup_zip, $delivery_zip, $terrestrial_cost, $confidence_percentage, $vehicle_electric = false, $vehicle_inoperable = false) {
        $involves_maritime = self::involves_maritime($pickup_zip, $delivery_zip);

        if (!$involves_maritime) {
            // Electric vehicle surcharge for terrestrial transport
            $electric_surcharge = $vehicle_electric ? 600.00 : 0.00;
            return [
                'total_cost' => $terrestrial_cost + $electric_surcharge,
                'terrestrial_cost' => $terrestrial_cost + $electric_surcharge,
                'maritime_cost' => 0,
                'maritime_involved' => false,
                'us_port' => null,
                'breakdown' => null,
                'inoperable_fee' => 0,
                'electric_surcharge' => $electric_surcharge
            ];
        }

        // Determine which side is San Juan
        $pickup_is_san_juan = self::is_san_juan_zip($pickup_zip);
        $delivery_is_san_juan = self::is_san_juan_zip($delivery_zip);

        // Get continental ZIP and port
        $continental_zip = $pickup_is_san_juan ? $delivery_zip : $pickup_zip;
        $us_port = self::get_us_port($continental_zip);

        // Calculate maritime cost
        $maritime_cost = self::get_maritime_rate($pickup_zip, $delivery_zip);

        // Electric vehicle surcharge
        $electric_surcharge = $vehicle_electric ? 600.00 : 0.00;
        $inoperable_fee = $vehicle_inoperable ? self::INOPERABLE_FEE : 0.00;

        // Calculate terrestrial cost (only if there's a terrestrial leg)
        $terrestrial_cost_with_markup = 0;
        $terrestrial_cost_raw = 0;
        $markup_applied = false;
        $confidence_adjustment = 0;
        $confidence_description = '';

        if ($terrestrial_cost > 0) {
            // Apply existing markup logic to terrestrial portion only
            $terrestrial_cost_raw = $terrestrial_cost;
            $markup_result = self::apply_terrestrial_markup($terrestrial_cost, $confidence_percentage);
            $terrestrial_cost_with_markup = $markup_result['final_cost'];
            $confidence_adjustment = $markup_result['confidence_adjustment'];
            $confidence_description = $markup_result['confidence_description'];
            $markup_applied = true;
        }

        // Total cost (including surcharges)
        $total_cost = $terrestrial_cost_with_markup + $maritime_cost + $electric_surcharge + $inoperable_fee;

        // Create breakdown
        $breakdown = self::create_maritime_breakdown(
            $terrestrial_cost_raw,
            $terrestrial_cost_with_markup,
            $maritime_cost,
            $electric_surcharge,
            $total_cost,
            $us_port,
            $markup_applied,
            $confidence_adjustment,
            $confidence_description,
            $vehicle_electric,
            $vehicle_inoperable,
            $inoperable_fee
        );

        return [
            'total_cost' => $total_cost,
            'terrestrial_cost' => $terrestrial_cost_with_markup,
            'maritime_cost' => $maritime_cost,
            'maritime_involved' => true,
            'us_port' => $us_port,
            'breakdown' => $breakdown,
            'inoperable_fee' => $inoperable_fee,
            'electric_surcharge' => $electric_surcharge
        ];
    }
    
    /**
     * Apply markup to terrestrial portion only
     */
    private static function apply_terrestrial_markup($terrestrial_cost, $confidence_percentage) {
        // Fixed company profit
        $company_profit = 200.00;
        
        // Confidence-based adjustment
        $confidence_adjustment = 0;
        $confidence_description = '';
        
        if ($confidence_percentage >= 60 && $confidence_percentage <= 100) {
            // Add remaining percentage to reach 100%
            $remaining_percentage = 100 - $confidence_percentage;
            $confidence_adjustment = ($terrestrial_cost * $remaining_percentage) / 100;
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%% → 100%%): +$%s USD',
                number_format($confidence_percentage, 1),
                number_format($confidence_adjustment, 2)
            );
        } elseif ($confidence_percentage >= 30 && $confidence_percentage <= 59) {
            // Add fixed $150
            $confidence_adjustment = 150.00;
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%%): +$150.00 USD',
                number_format($confidence_percentage, 1)
            );
        } elseif ($confidence_percentage >= 0 && $confidence_percentage <= 29) {
            // Add fixed $200
            $confidence_adjustment = 200.00;
            $confidence_description = sprintf(
                'Ajuste por confianza (%s%%): +$200.00 USD',
                number_format($confidence_percentage, 1)
            );
        }
        
        return [
            'final_cost' => $terrestrial_cost + $company_profit + $confidence_adjustment,
            'confidence_adjustment' => $confidence_adjustment,
            'confidence_description' => $confidence_description
        ];
    }
    
    /**
     * Create detailed breakdown for maritime transport
     */
    private static function create_maritime_breakdown($terrestrial_raw, $terrestrial_with_markup, $maritime_cost, $electric_surcharge, $total_cost, $us_port, $markup_applied, $confidence_adjustment = 0, $confidence_description = '', $vehicle_electric = false, $vehicle_inoperable = false, $inoperable_fee = 0) {
        $breakdown = '<div class="sdpi-maritime-breakdown">';
        $breakdown .= '<h4>Desglose de Costos - Transporte Marítimo</h4>';

        if ($terrestrial_raw > 0) {
            $breakdown .= '<div class="sdpi-cost-section">';
            $breakdown .= '<h5>Tramo Terrestre</h5>';
            $breakdown .= '<div class="sdpi-price-item">';
            $breakdown .= '<span class="sdpi-price-label">Precio base de la API:</span>';
            $breakdown .= '<span class="sdpi-price-value">$' . number_format($terrestrial_raw, 2) . ' USD</span>';
            $breakdown .= '</div>';

            if ($markup_applied && $confidence_adjustment > 0) {
                $breakdown .= '<div class="sdpi-price-item">';
                $breakdown .= '<span class="sdpi-price-label">' . $confidence_description . '</span>';
                $breakdown .= '<span class="sdpi-price-value">+$' . number_format($confidence_adjustment, 2) . ' USD</span>';
                $breakdown .= '</div>';
            }

            $breakdown .= '<div class="sdpi-price-item">';
            $breakdown .= '<span class="sdpi-price-label">Ganancia de la empresa:</span>';
            $breakdown .= '<span class="sdpi-price-value">+$200.00 USD</span>';
            $breakdown .= '</div>';

            $breakdown .= '<div class="sdpi-price-item sdpi-price-subtotal">';
            $breakdown .= '<span class="sdpi-price-label"><strong>Subtotal Terrestre:</strong></span>';
            $breakdown .= '<span class="sdpi-price-value"><strong>$' . number_format($terrestrial_with_markup, 2) . ' USD</strong></span>';
            $breakdown .= '</div>';
            $breakdown .= '</div>';
        }

        $breakdown .= '<div class="sdpi-cost-section">';
        $breakdown .= '<h5>Tramo Marítimo</h5>';

        // Show correct port names and ZIPs
        if ($us_port['port'] === self::PENN_TERMINALS_NAME) {
            $breakdown .= '<p><strong>Puerto USA:</strong> ' . self::PENN_TERMINALS_NAME . ' (19022)</p>';
        } else {
            $breakdown .= '<p><strong>Puerto USA:</strong> ' . self::JACKSONVILLE_NAME . ' (32226)</p>';
        }

        $breakdown .= '<div class="sdpi-price-item">';
        $breakdown .= '<span class="sdpi-price-label">Tarifa Marítima:</span>';
        $breakdown .= '<span class="sdpi-price-value">$' . number_format($maritime_cost, 2) . ' USD</span>';
        $breakdown .= '</div>';
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
        $breakdown .= '<div class="sdpi-price-item sdpi-price-total">';
        $breakdown .= '<span class="sdpi-price-label"><strong>Costo Total:</strong></span>';
        $breakdown .= '<span class="sdpi-price-value"><strong>$' . number_format($total_cost, 2) . ' USD</strong></span>';
        $breakdown .= '</div>';
        // Show correct port name in note
        $port_display_name = ($us_port['port'] === self::PENN_TERMINALS_NAME) ? self::PENN_TERMINALS_NAME : self::JACKSONVILLE_NAME;
        $breakdown .= '<p class="sdpi-maritime-note">* Incluye transporte marítimo entre ' . $port_display_name . ' y San Juan, PR</p>';
        $breakdown .= '</div>';

        $breakdown .= '</div>';

        return $breakdown;
    }
}

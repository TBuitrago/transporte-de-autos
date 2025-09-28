<?php
/**
 * API class for Super Dispatch Pricing Insights
 */

class SDPI_API {

    private $api_key;
    private $endpoint;
    private $cache_time;

    public function __construct() {
        $this->api_key = get_option('sdpi_api_key');
        $this->endpoint = get_option('sdpi_api_endpoint');
        $this->cache_time = get_option('sdpi_cache_time', 300);
    }

    /**
     * Get pricing quote from Super Dispatch API
     *
     * @param array $data Form data
     * @return array|WP_Error
     */
    public function get_pricing_quote($data) {
        // Validate required data
        if (empty($data['pickup']['zip']) || empty($data['delivery']['zip'])) {
            return new WP_Error('missing_data', 'Pickup and delivery ZIP codes are required');
        }

        // Check cache first
        $cache_key = 'sdpi_quote_' . md5(serialize($data));
        $cached_response = get_transient($cache_key);

        if ($cached_response !== false) {
            if (WP_DEBUG) {
                error_log('SDPI: Using cached response for: ' . $cache_key);
            }
            return $cached_response;
        }

        // Prepare the request
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->api_key,
            ),
            'body' => wp_json_encode($data),
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'data_format' => 'body',
        );

        // Make the API request with retry logic
        $response = $this->make_request_with_retry($this->endpoint, $args);

        if (is_wp_error($response)) {
            if (WP_DEBUG) {
                error_log('SDPI API Error: ' . $response->get_error_message());
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            $error_message = "API returned status code: $response_code";
            if (WP_DEBUG) {
                error_log('SDPI API Error: ' . $error_message . ' - Response: ' . $response_body);
            }
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        $decoded_response = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Invalid JSON response from API';
            if (WP_DEBUG) {
                error_log('SDPI JSON Error: ' . json_last_error_msg() . ' - Response: ' . $response_body);
            }
            return new WP_Error('json_error', $error_message);
        }

        // Check for required fields in response
        if (!isset($decoded_response['recommended_price']) && !isset($decoded_response['price'])) {
            // Log the actual response structure for debugging
            if (WP_DEBUG) {
                error_log('SDPI: API response structure: ' . print_r($decoded_response, true));
                error_log('SDPI: Available fields: ' . implode(', ', array_keys($decoded_response)));
            }
            
            // Try to find price in different possible field names
            $price_found = false;
            $possible_price_fields = array(
                'recommended_price', 'price', 'total_price', 'shipping_price', 
                'freight_price', 'transport_price', 'quote_price', 'estimate_price',
                'cost', 'total_cost', 'shipping_cost', 'freight_cost'
            );
            
            // First, check the Super Dispatch API structure: data.price
            if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
                foreach ($possible_price_fields as $field) {
                    if (isset($decoded_response['data'][$field])) {
                        $decoded_response['recommended_price'] = $decoded_response['data'][$field];
                        $price_found = true;
                        if (WP_DEBUG) {
                            error_log('SDPI: Found price in data.' . $field . ' = ' . $decoded_response['data'][$field]);
                        }
                        break;
                    }
                }
            }
            
            // If still no price found, check root level
            if (!$price_found) {
                foreach ($possible_price_fields as $field) {
                    if (isset($decoded_response[$field])) {
                        $decoded_response['recommended_price'] = $decoded_response[$field];
                        $price_found = true;
                        if (WP_DEBUG) {
                            error_log('SDPI: Found price in root field: ' . $field . ' = ' . $decoded_response[$field]);
                        }
                        break;
                    }
                }
            }
            
            // If still no price found, check other nested structures
            if (!$price_found) {
                // Check if price is in a quote object
                if (isset($decoded_response['quote']) && is_array($decoded_response['quote'])) {
                    foreach ($possible_price_fields as $field) {
                        if (isset($decoded_response['quote'][$field])) {
                            $decoded_response['recommended_price'] = $decoded_response['quote'][$field];
                            $price_found = true;
                            if (WP_DEBUG) {
                                error_log('SDPI: Found price in quote field: ' . $field . ' = ' . $decoded_response['quote'][$field]);
                            }
                            break;
                        }
                    }
                }
                
                // Check if price is in a result object
                if (!$price_found && isset($decoded_response['result']) && is_array($decoded_response['result'])) {
                    foreach ($possible_price_fields as $field) {
                        if (isset($decoded_response['result'][$field])) {
                            $decoded_response['recommended_price'] = $decoded_response['result'][$field];
                            $price_found = true;
                            if (WP_DEBUG) {
                                error_log('SDPI: Found price in result field: ' . $field . ' = ' . $decoded_response['result'][$field]);
                            }
                            break;
                        }
                    }
                }
            }
            
            // If still no price found, return error with response details
            if (!$price_found) {
                $error_message = 'API response missing required price field. Available fields: ' . implode(', ', array_keys($decoded_response));
                if (WP_DEBUG) {
                    error_log('SDPI Validation Error: ' . $error_message . ' - Full Response: ' . print_r($decoded_response, true));
                }
                return new WP_Error('invalid_response', $error_message, array('response' => $decoded_response));
            }
        }

        // Normalize response fields
        if (isset($decoded_response['price']) && !isset($decoded_response['recommended_price'])) {
            $decoded_response['recommended_price'] = $decoded_response['price'];
        }
        
        // Also check for confidence in data.confidence (Super Dispatch API structure)
        if (isset($decoded_response['data']['confidence']) && !isset($decoded_response['confidence'])) {
            $decoded_response['confidence'] = $decoded_response['data']['confidence'];
        }

        // Cache the successful response
        set_transient($cache_key, $decoded_response, $this->cache_time);

        return $decoded_response;
    }

    /**
     * Make API request with retry logic for rate limiting
     *
     * @param string $url
     * @param array $args
     * @param int $max_retries
     * @return array|WP_Error
     */
    private function make_request_with_retry($url, $args, $max_retries = 3) {
        $retry_count = 0;
        $backoff = 1; // Start with 1 second backoff

        while ($retry_count <= $max_retries) {
            $response = wp_remote_post($url, $args);

            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                
                // If not rate limited, return the response
                if ($response_code !== 429) {
                    return $response;
                }

                // If rate limited, wait and retry
                if (WP_DEBUG) {
                    error_log('SDPI: Rate limited, retrying in ' . $backoff . ' seconds');
                }
                
                sleep($backoff);
                $backoff *= 2; // Exponential backoff
                $retry_count++;
            } else {
                // Network error, retry with backoff
                if (WP_DEBUG) {
                    error_log('SDPI: Network error, retrying in ' . $backoff . ' seconds: ' . $response->get_error_message());
                }
                
                sleep($backoff);
                $backoff *= 2;
                $retry_count++;
            }
        }

        return new WP_Error('max_retries_exceeded', 'Maximum retry attempts exceeded');
    }

    /**
     * Validate and sanitize form data
     *
     * @param array $form_data
     * @return array
     */
    public function sanitize_form_data($form_data) {
        $sanitized = array();

        // Pickup data
        if (!empty($form_data['pickup']['zip'])) {
            $sanitized['pickup']['zip'] = sanitize_text_field($form_data['pickup']['zip']);
        }

        // Delivery data
        if (!empty($form_data['delivery']['zip'])) {
            $sanitized['delivery']['zip'] = sanitize_text_field($form_data['delivery']['zip']);
        }

        // Trailer type
        if (!empty($form_data['trailer_type'])) {
            $sanitized['trailer_type'] = sanitize_text_field($form_data['trailer_type']);
        }

        // Vehicles
        if (!empty($form_data['vehicles']) && is_array($form_data['vehicles'])) {
            foreach ($form_data['vehicles'] as $index => $vehicle) {
                if (!empty($vehicle['type'])) {
                    $sanitized['vehicles'][$index]['type'] = sanitize_text_field($vehicle['type']);
                }
                if (isset($vehicle['is_inoperable'])) {
                    $sanitized['vehicles'][$index]['is_inoperable'] = (bool)$vehicle['is_inoperable'];
                }
                if (!empty($vehicle['make'])) {
                    $sanitized['vehicles'][$index]['make'] = sanitize_text_field($vehicle['make']);
                }
                if (!empty($vehicle['model'])) {
                    $sanitized['vehicles'][$index]['model'] = sanitize_text_field($vehicle['model']);
                }
                if (!empty($vehicle['year'])) {
                    $sanitized['vehicles'][$index]['year'] = sanitize_text_field($vehicle['year']);
                }
            }
        }

        return $sanitized;
    }
}

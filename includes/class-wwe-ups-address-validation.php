<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WWE_UPS_Address_Validation Class.
 * Handles address validation using UPS API for WWE context.
 * Inspired by Ph_Ups_Address_Validation.
 */
if (!class_exists('WWE_UPS_Address_Validation')) {
    class WWE_UPS_Address_Validation {

        public $settings;
        public $debug_mode;
        public $api_handler;

        /**
         * Constructor.
         * @param array $settings WWE plugin settings.
         */
        public function __construct($settings = array()) {
            $this->settings = $settings;
            $this->debug_mode = isset($settings['debug']) && 'yes' === $settings['debug'];
            // Assuming API Handler is instantiated elsewhere or passed if needed here
            // For simplicity, let's instantiate it here if credentials exist
            if (defined('UPS_WW_ECONOMY_CLIENT_ID') && UPS_WW_ECONOMY_CLIENT_ID) {
                 $this->api_handler = new WWE_UPS_API_Handler($settings);
            } else {
                $this->api_handler = null;
            }
        }

        /**
         * Validate an address.
         *
         * @param array $address Address array (keys: addressLine1, city, stateProvinceCode, postalCode, countryCode).
         * @return array|WP_Error ['validation_result' => 'Valid'|'Ambiguous'|'NoCandidates', 'address_type' => 'Residential'|'Commercial'|'Unknown', 'candidates' => array] or WP_Error
         */
        public function validate_address(array $address) {
            if (!$this->api_handler) {
                 return new WP_Error('api_handler_missing', __('WWE API Handler not available.', 'wwe-ups-woocommerce-shipping'));
            }

             // Basic validation of input address
             if (empty($address['countryCode']) || empty($address['postalCode']) || empty($address['city'])) {
                 return new WP_Error('incomplete_address', __('Address requires at least Country, Postal Code, and City for validation.', 'wwe-ups-woocommerce-shipping'));
             }

            $response = $this->api_handler->validate_address($address); // Assumes API handler has validate_address method

            if (is_wp_error($response)) {
                 return $response; // Forward API or WP error
            }

            // --- Process the response ---
            // This depends heavily on the *actual* JSON structure of the UPS Address Validation API
            $result = [
                'validation_result' => 'Unknown', // Valid, Ambiguous, NoCandidates
                'address_type' => 'Unknown',      // Commercial, Residential
                'candidates' => [],
            ];

             // Example parsing based on typical XAV responses (ADAPT FOR ACTUAL API)
             $xav_response = $response['body']['XAVResponse'] ?? null;

            if (!$xav_response) {
                return new WP_Error('invalid_api_response', __('Invalid response structure from Address Validation API.', 'wwe-ups-woocommerce-shipping'));
            }

            if (isset($xav_response['NoCandidatesIndicator'])) {
                 $result['validation_result'] = 'NoCandidates';
            } elseif (isset($xav_response['ValidAddressIndicator'])) {
                 $result['validation_result'] = 'Valid';
                 $result['candidates'][] = $xav_response['AddressKeyFormat'] ?? []; // The single valid address
                 if (isset($xav_response['AddressClassification']['Code'])) {
                     $result['address_type'] = $xav_response['AddressClassification']['Code'] == '1' ? 'Commercial' : ($xav_response['AddressClassification']['Code'] == '2' ? 'Residential' : 'Unknown');
                 }
            } elseif (isset($xav_response['AmbiguousAddressIndicator'])) {
                 $result['validation_result'] = 'Ambiguous';
                 // Candidates might be an array or single object depending on API response
                 if (isset($xav_response['AddressKeyFormat'][0])) { // Multiple candidates
                      $result['candidates'] = $xav_response['AddressKeyFormat'];
                 } elseif (isset($xav_response['AddressKeyFormat'])) { // Single candidate for ambiguous?
                      $result['candidates'][] = $xav_response['AddressKeyFormat'];
                 }
                 // Classification might be present even if ambiguous
                 if (isset($xav_response['AddressClassification']['Code'])) {
                     $result['address_type'] = $xav_response['AddressClassification']['Code'] == '1' ? 'Commercial' : ($xav_response['AddressClassification']['Code'] == '2' ? 'Residential' : 'Unknown');
                 }
            }

            return $result;
        }

        /**
         * Valide une adresse de livraison avant génération d'étiquette.
         * 
         * Vérifie le format du code postal selon le pays et d'autres règles
         * de validation spécifiques pour éviter des erreurs API.
         * 
         * @since 1.0.0
         * @param array $address_data Données d'adresse (country, postcode, city, etc.)
         * @return bool|WP_Error True si l'adresse est valide, WP_Error sinon
         */
        public function validate_shipping_address($address_data) {
            // Vérification des champs obligatoires
            $required_fields = ['country', 'postcode', 'city', 'address_1'];
            $missing_fields = [];
            foreach ($required_fields as $field) {
                if (empty($address_data[$field])) {
                    $missing_fields[] = $field;
                }
            }
            if (!empty($missing_fields)) {
                return new WP_Error(
                    'missing_address_fields',
                    sprintf(
                        __('Champs d\'adresse manquants: %s', 'wwe-ups-woocommerce-shipping'),
                        implode(', ', $missing_fields)
                    )
                );
            }

            // Validation spécifique par pays
            $country = $address_data['country'];
            $postcode = $address_data['postcode'];
            switch ($country) {
                case 'US':
                    if (!preg_match('/^\d{5}(-\d{4})?$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal US invalide. Doit être au format 12345 ou 12345-6789.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'CA':
                    if (!preg_match('/^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal canadien invalide. Doit être au format A1A 1A1.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'GB':
                    if (!preg_match('/^[A-Z]{1,2}[0-9R][0-9A-Z]? [0-9][ABD-HJLNP-UW-Z]{2}$/i', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal UK invalide.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'FR':
                    if (!preg_match('/^\d{5}$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal français invalide. Doit être au format 12345.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'DE':
                    if (!preg_match('/^\d{5}$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal allemand invalide. Doit être au format 12345.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'AU':
                    if (!preg_match('/^\d{4}$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal australien invalide. Doit être au format 1234.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
                case 'JP':
                    if (!preg_match('/^\d{3}-\d{4}$/', $postcode)) {
                        return new WP_Error(
                            'invalid_postcode',
                            __('Format de code postal japonais invalide. Doit être au format 123-4567.', 'wwe-ups-woocommerce-shipping')
                        );
                    }
                    break;
            }

            // Si on a passé toutes les validations basiques, on peut appeler l'API UPS
            if ($this->api_handler && !empty($this->settings['validate_addresses']) && $this->settings['validate_addresses'] === 'yes') {
                $address_for_api = [
                    'addressLine1'     => $address_data['address_1'],
                    'addressLine2'     => $address_data['address_2'] ?? '',
                    'city'             => $address_data['city'],
                    'stateProvinceCode'=> $address_data['state'] ?? '',
                    'postalCode'       => $address_data['postcode'],
                    'countryCode'      => $address_data['country'],
                ];
                $validation_result = $this->validate_address($address_for_api);
                if (is_wp_error($validation_result)) {
                    return $validation_result;
                }
                if ($validation_result['validation_result'] === 'NoCandidates') {
                    return new WP_Error(
                        'address_not_found',
                        __('L\'adresse fournie n\'a pas pu être validée par UPS.', 'wwe-ups-woocommerce-shipping')
                    );
                }
            }

            return true;
        }

        /**
         * Check if an address is likely residential.
         *
         * @param array $address Address array.
         * @return bool|null True if residential, false if commercial, null if unknown/error.
         */
        public function check_residential(array $address) {
            $validation_result = $this->validate_address($address);

            if (is_wp_error($validation_result)) {
                 wwe_ups_log('Error during residential check: ' . $validation_result->get_error_message(), 'error');
                 return null; // Error occurred
            }

            if ($validation_result['address_type'] === 'Residential') {
                return true;
            } elseif ($validation_result['address_type'] === 'Commercial') {
                return false;
            }

            // Fallback: If classification is unknown but address is valid, assume non-residential? Or query again?
            // For now, return null if unknown.
             wwe_ups_log('Address classification returned Unknown for residential check.', 'info');
            return null;
        }

    } // End class WWE_UPS_Address_Validation
}
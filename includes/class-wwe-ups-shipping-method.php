<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WWE_UPS_Shipping_Method Class.
 */
if (!class_exists('WWE_UPS_Shipping_Method') && class_exists('WC_Shipping_Method')) {

    class WWE_UPS_Shipping_Method extends WC_Shipping_Method {

        /** @var bool Debug mode */
        public $debug_mode;

        /** @var WWE_UPS_API_Handler */
        public $api_handler;

        /** @var string Shipper country code */
        public $origin_country;

        /** @var string Service code */
        public $service_code;

        /** @var string Incoterm */
        public $incoterm;

        /** @var float Flat handling fee added to each rate */
        public $handling_fee;

        /**
         * Constructor.
         *
         * @param int $instance_id
         */
        public function __construct($instance_id = 0) {
            $this->id = WWE_UPS_ID; // Use constant
            $this->instance_id = absint($instance_id);
            $this->method_title = __('UPS i-parcel Select DDU', 'wwe-ups-woocommerce-shipping');
            $this->method_description = __('Calculates shipping rates for UPS i-parcel Select DDU service using UPS i-parcel API.', 'wwe-ups-woocommerce-shipping');

            // Defines supports for instance settings
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init(); // Load settings and form fields

            // Instantiate API Handler only if credentials seem to be present (basic check)
            // A more robust check might happen within the handler itself
             if (defined('UPS_WW_ECONOMY_CLIENT_ID') && UPS_WW_ECONOMY_CLIENT_ID) {
                $this->api_handler = new WWE_UPS_API_Handler($this->settings);
             } else {
                 $this->api_handler = null; // No API handler if credentials aren't set up
                 wwe_ups_log('WWE Shipping Method: API Handler not instantiated due to missing credentials.', 'warning');
             }

            // Set properties from settings or constants
            $this->debug_mode     = 'yes' === $this->get_option('debug');
            $this->origin_country = defined('WWE_SHIPPER_COUNTRY_CODE') ? WWE_SHIPPER_COUNTRY_CODE : 'FR'; // Default fallback
            $this->service_code   = defined('UPS_WW_ECONOMY_SERVICE_CODE') ? UPS_WW_ECONOMY_SERVICE_CODE : '17'; // Default to i-parcel Select
            $this->incoterm       = defined('UPS_WW_ECONOMY_INCOTERM') ? UPS_WW_ECONOMY_INCOTERM : 'DDU';    // Default to DDU for i-parcel

            // Load the flat handling fee option
            $this->handling_fee = (float) $this->get_option('handling_fee', 0);
        }

        /**
         * Init settings and form fields.
         */
        public function init() {
            // Load the settings API
            $this->init_form_fields(); // Define instance settings fields.
            $this->init_settings();   // Load instance settings.

            // Define user set variables
            $this->title        = $this->get_option('title', $this->method_title);
            $this->enabled      = $this->get_option('enabled', 'yes');
            $this->availability = $this->get_option('availability'); // Handled by parent zones
            $this->countries    = $this->get_option('countries');   // Handled by parent zones

            // Save settings hook
            add_action('woocommerce_update_options_shipping_' . $this->id . '_' . $this->instance_id, array($this, 'process_admin_options'));
        }

        /**
         * Define settings fields for this instance.
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'wwe-ups-woocommerce-shipping'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable this shipping method', 'wwe-ups-woocommerce-shipping'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => __('Title', 'wwe-ups-woocommerce-shipping'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wwe-ups-woocommerce-shipping'),
                    'default'     => __('UPS Worldwide Economy', 'wwe-ups-woocommerce-shipping'),
                    'desc_tip'    => true,
                ),
                'debug' => array(
                    'title'       => __('Debug Mode', 'wwe-ups-woocommerce-shipping'),
                    'label'       => __('Enable debug mode', 'wwe-ups-woocommerce-shipping'),
                    'type'        => 'checkbox',
                    'default'     => 'no',
                    'description' => __('Enable logging for debugging purposes. Check logs under WooCommerce > Status > Logs.', 'wwe-ups-woocommerce-shipping'),
                ),
                'handling_fee' => array(
                    'title'       => __('Flat fee', 'wwe-ups-woocommerce-shipping'),
                    'type'        => 'price',
                    'description' => __('Small extra amount added to every WWE shipment (e.g. 1.00 or 0.50).', 'wwe-ups-woocommerce-shipping'),
                    'default'     => '0',
                    'desc_tip'    => true,
                ),
                // Future: Add fields for handling fees, maybe default HS code override etc.
            );
        }

        /**
         * Check if the method is available for the given package.
         *
         * @param array $package
         * @return bool
         */
        public function is_available($package) {
            if ('no' === $this->enabled) {
                return false;
            }

            // Check if API handler is available (basic credential check)
            if (!$this->api_handler) {
                 if ($this->debug_mode) { wwe_ups_log("WWE Method not available: API Handler not initialized (check credentials)."); }
                 return false;
            }

            // Ensure destination country exists and is international
            if (!isset($package['destination']['country']) || empty($package['destination']['country'])) {
                if ($this->debug_mode) { wwe_ups_log("WWE Method not available: Destination country is missing."); }
                return false;
            }
            if ($package['destination']['country'] === $this->origin_country) {
                 if ($this->debug_mode) { wwe_ups_log("WWE Method not available: Destination country ({$package['destination']['country']}) is domestic."); }
                return false; // WWE is international only
            }

            // Use WooCommerce's zone matching
            return parent::is_available($package);
        }

        /**
         * Calculate WWE negotiated rates based on your contract
         * UPDATED: Now uses unified calculation function
         * @param array $api_packages Array of packages
         * @return float Total shipping cost
         */
        private function calculate_wwe_negotiated_rate($api_packages) {
            // Get destination country from current package being processed
            $destination_country = 'US'; // Default
            
            // Try to get destination from current calculation context
            if (isset($this->current_package_destination)) {
                $destination_country = $this->current_package_destination;
            }
            
            // Use unified calculation function
            $total_cost = wwe_ups_calculate_unified_negotiated_rate(
                $api_packages, 
                $destination_country, 
                null, // Let function calculate weight
                'frontend'
            );
            
            if ($this->debug_mode) {
                wwe_ups_log("FRONTEND WWE Rate: Using unified calculation = €{$total_cost} for {$destination_country}");
            }
            
            return $total_cost;
        }

        /**
         * Get state code with intelligent fallback for countries that require it.
         * 
         * @param array $dest Destination address array
         * @return string State code or appropriate fallback
         */
        private function get_state_code_with_fallback($dest) {
            $state = $dest['state'] ?? '';
            $country = $dest['country'] ?? '';
            
            // If state is provided, use it (truncated to 5 chars for UPS)
            if (!empty($state)) {
                return substr($state, 0, 5);
            }
            
            // For countries that require state, provide intelligent fallbacks
            switch ($country) {
                case 'US':
                    // For US without state, try to guess from city or use a default
                    $city = strtolower($dest['city'] ?? '');
                    if (strpos($city, 'new york') !== false || strpos($city, 'nyc') !== false) {
                        return 'NY';
                    } elseif (strpos($city, 'los angeles') !== false || strpos($city, 'la') !== false) {
                        return 'CA';
                    } elseif (strpos($city, 'chicago') !== false) {
                        return 'IL';
                    }
                    // Default to NY for US if no state provided
                    if ($this->debug_mode) { 
                        wwe_ups_log("⚠️ US address without state - using NY as fallback for: " . ($city ?: 'unknown city')); 
                    }
                    return 'NY';
                    
                case 'CA':
                    // For Canada, use province codes
                    return 'ON'; // Default to Ontario
                    
                case 'AU':
                    return 'NSW'; // Default to New South Wales
                    
                default:
                    // For other countries, return empty string (most don't require state)
                    return '';
            }
        }

        /**
         * Modified calculate_shipping to store destination for rate calculation
         */
        public function calculate_shipping($package = array()) {
            // Store destination for use in rate calculation
            if (isset($package['destination']['country'])) {
                $this->current_package_destination = $package['destination']['country'];
            }
            
             if ($this->debug_mode) {
                 wwe_ups_log("== Starting calculate_shipping for WWE UPS (Instance ID: {$this->instance_id}) ==");
                 wwe_ups_log("Destination: " . ($package['destination']['country'] ?? 'N/A') . ", Postcode: " . ($package['destination']['postcode'] ?? 'N/A'));
             }

             // Check if API handler is available
             if (!$this->api_handler) {
                  if ($this->debug_mode) { wwe_ups_log("Calc stopped: API Handler not available."); }
                  return;
             }

             // 1. Calculate Total Weight & Check Items
             $total_weight_kg = 0;
             $items_in_package = 0;
             $missing_weight = false;
             $contents_cost = 0;

             if (empty($package['contents'])) {
                  if ($this->debug_mode) { wwe_ups_log("Calc stopped: Package contents empty."); }
                  return;
             }

             foreach ($package['contents'] as $item_id => $values) {
                 $_product = $values['data']; // Already a product object
                 $qty = $values['quantity'];
                 $items_in_package += $qty;

                 if ($_product && $_product->needs_shipping()) {
                     if (!$_product->has_weight()) {
                         wwe_ups_log("Product '{$_product->get_name()}' (ID: {$_product->get_id()}) is missing weight.", 'error');
                         $missing_weight = true;
                         break;
                     } else {
                         $item_weight = wc_get_weight($_product->get_weight(), 'kg'); // Standardize to KG
                         if (false === $item_weight || !is_numeric($item_weight)) {
                              wwe_ups_log("Product '{$_product->get_name()}' (ID: {$_product->get_id()}) has invalid weight value.", 'error');
                              $missing_weight = true; break;
                         }
                         $total_weight_kg += floatval($item_weight) * $qty;
                         // Use item subtotal for more accuracy if discounts apply per item
                         $contents_cost += floatval($values['line_subtotal'] ?? $values['line_total'] ?? ($_product->get_price() * $qty));
                     }
                 }
             }

             if ($items_in_package === 0) { if ($this->debug_mode) { wwe_ups_log("Calc stopped: No shippable items in package."); } return; }
             if ($missing_weight) { if ($this->debug_mode) { wwe_ups_log("Calc stopped: A product is missing weight."); } return; }
             if ($total_weight_kg <= 0) { if ($this->debug_mode) { wwe_ups_log("Calc stopped: Total weight is zero or less ({$total_weight_kg}kg)."); } return; }

            if ($this->debug_mode) { wwe_ups_log("Poids total calculé : {$total_weight_kg} kg. Coût contenu : {$contents_cost}"); }

            // *** NOUVELLE LOGIQUE DE DIVISION ***
            $max_package_weight = defined('WWE_MAX_WEIGHT') ? floatval(WWE_MAX_WEIGHT) : 20.0; // Limite par colis
            $api_packages = []; // Array pour les colis à envoyer à l'API

            if ($total_weight_kg > $max_package_weight) {
                // --- Logique de Division ---
                $num_packages = ceil($total_weight_kg / $max_package_weight);
                if ($num_packages > 10) {
                    wwe_ups_log("Calc arrêté : Trop de colis WWE nécessaires ({$num_packages}) pour le poids {$total_weight_kg}kg. Limite Max Colis WWE = {$max_package_weight}kg.", 'error');
                    return;
                }

                $weight_per_package = $total_weight_kg / $num_packages;
                $weight_per_package = max($weight_per_package, defined('WWE_MINIMUM_PACKAGE_WEIGHT') ? WWE_MINIMUM_PACKAGE_WEIGHT : 0.1);

                if ($weight_per_package > $max_package_weight) {
                    wwe_ups_log("Erreur de calcul interne: Poids par colis ({$weight_per_package}kg) dépasse la limite ({$max_package_weight}kg) après division.", 'critical');
                    return;
                }

                if ($this->debug_mode) { wwe_ups_log("Poids total {$total_weight_kg}kg > Limite {$max_package_weight}kg. Division en {$num_packages} colis d'environ {$weight_per_package}kg chacun."); }

                for ($i = 0; $i < $num_packages; $i++) {
                    // Utiliser la boîte 33x33x33cm pour tous les colis divisés
                    $package_details_for_split = wwe_ups_get_package_details_by_weight($weight_per_package, true);
                    if (!$package_details_for_split) {
                        wwe_ups_log("Erreur : Impossible de déterminer les détails pour un colis divisé de {$weight_per_package}kg.", 'error');
                        return;
                    }
                    $api_packages[] = [
                        'PackagingType' => ['Code' => '02'], // Customer Supplied Package (comme version GitHub qui fonctionnait)
                        'Dimensions' => [
                            'UnitOfMeasurement' => ['Code' => $package_details_for_split['dim_unit']],
                            'Length' => (string)$package_details_for_split['length'],
                            'Width'  => (string)$package_details_for_split['width'],
                            'Height' => (string)$package_details_for_split['height']
                        ],
                        'PackageWeight' => [
                            'UnitOfMeasurement' => ['Code' => $package_details_for_split['weight_unit']],
                            'Weight' => (string)$package_details_for_split['weight']
                        ]
                    ];
                }
            } else {
                // --- Cas Colis Unique (<= max_package_weight) ---
                $package_details = wwe_ups_get_package_details_by_weight($total_weight_kg, false);
                if (!$package_details) {
                    if ($this->debug_mode) { wwe_ups_log("Calc arrêté : Impossible de déterminer les détails du colis unique WWE (poids: {$total_weight_kg} kg)."); }
                    return;
                }
                $api_packages[] = [
                    'PackagingType' => ['Code' => '02'], // Customer Supplied Package (comme version GitHub qui fonctionnait)
                    'Dimensions' => [
                        'UnitOfMeasurement' => ['Code' => $package_details['dim_unit']],
                        'Length' => (string)$package_details['length'],
                        'Width'  => (string)$package_details['width'],
                        'Height' => (string)$package_details['height']
                    ],
                    'PackageWeight' => [
                        'UnitOfMeasurement' => ['Code' => $package_details['weight_unit']],
                        'Weight' => (string)$package_details['weight']
                    ]
                ];
            }
            // *** FIN NOUVELLE LOGIQUE DE DIVISION ***

            // 3. Prepare API Request Body
            $dest = $package['destination'];
            
            // DEBUG: Log destination data to understand missing state issue
            if ($this->debug_mode) { 
                wwe_ups_log("🔍 DEBUG Package Destination: " . print_r($dest, true)); 
                wwe_ups_log("🔍 State specifically: '" . ($dest['state'] ?? 'NOT SET') . "'");
            }
            
             if (empty($dest['country']) || empty($dest['postcode']) || empty($dest['city'])) { // Removed address_1 check for rating
                 if ($this->debug_mode) { wwe_ups_log("Calc stopped: Destination address incomplete (Country, Postcode, City required for rating).", 'error'); }
                 return;
             }

             // Attempt to get phone, fallback gracefully
             $ship_to_phone = preg_replace('/[^0-9]/', '', $dest['phone'] ?? '');
             if (empty($ship_to_phone)) {
                  $billing_phone = $package['user']['billing_phone'] ?? '';
                  $ship_to_phone = preg_replace('/[^0-9]/', '', $billing_phone);
                  if (empty($ship_to_phone)) {
                      wwe_ups_log("Warning: Ship To phone number is missing for Rate Request.", 'warning');
                      $ship_to_phone = '0000000000'; // Placeholder - UPS might still require a valid one later
                  }
             }

             // Get shipper details (consider making these settings fields)
             $shipper_details = [
                 'Name' => substr(defined('WWE_SHIPPER_NAME') ? WWE_SHIPPER_NAME : 'Shipper', 0, 35),
                 'AttentionName' => substr(defined('WWE_SHIPPER_ATTENTION_NAME') ? WWE_SHIPPER_ATTENTION_NAME : 'Shipping Dept', 0, 35),
                 'ShipperNumber' => $this->api_handler->account_number,
                 'Address' => [
                     'AddressLine' => array_filter([substr(defined('WWE_SHIPPER_ADDRESS_LINE_1') ? WWE_SHIPPER_ADDRESS_LINE_1 : '', 0, 35)]),
                     'City' => substr(defined('WWE_SHIPPER_CITY') ? WWE_SHIPPER_CITY : '', 0, 30),
                     'PostalCode' => substr(defined('WWE_SHIPPER_POSTAL_CODE') ? WWE_SHIPPER_POSTAL_CODE : '', 0, 10),
                     'CountryCode' => $this->origin_country,
                 ],
                 'Phone' => ['Number' => preg_replace('/[^0-9]/', '', defined('WWE_SHIPPER_PHONE') ? WWE_SHIPPER_PHONE : '0000000000')],
                 'EMailAddress' => defined('WWE_SHIPPER_EMAIL') ? WWE_SHIPPER_EMAIL : '',
             ];

            // Build Request Body
            $request_body = [
                'RateRequest' => [
                    'Request' => [
                        'RequestOption' => 'Rate',
                        'TransactionReference' => ['CustomerContext' => 'WWE WooCommerce Rate Request Inst ' . $this->instance_id]
                    ],
                    'Shipment' => [
                        'Shipper' => $shipper_details,
                        'ShipTo' => [
                            'Name' => substr(trim(($dest['first_name'] ?? '') . ' ' . ($dest['last_name'] ?? 'Customer')), 0, 35),
                            'AttentionName' => substr(trim(($dest['first_name'] ?? '') . ' ' . ($dest['last_name'] ?? 'Customer')), 0, 35),
                            'CompanyName' => substr($dest['company'] ?? '', 0, 35),
                            'Address' => [
                                'AddressLine' => array_filter([
                                    substr($dest['address_1'] ?? '', 0, 35),
                                    substr($dest['address_2'] ?? '', 0, 35)
                                ]),
                                'City' => substr($dest['city'] ?? '', 0, 30),
                                'StateProvinceCode' => $this->get_state_code_with_fallback($dest),
                                'PostalCode' => substr($dest['postcode'] ?? '', 0, 10),
                                'CountryCode' => $dest['country'] ?? ''
                            ],
                            'Phone' => ['Number' => $ship_to_phone],
                            'EMailAddress' => $dest['email'] ?? ($package['user']['billing_email'] ?? ''),
                        ],
                        'ShipFrom' => [
                            'Name' => $shipper_details['Name'],
                            'AttentionName' => $shipper_details['AttentionName'],
                            'Address' => $shipper_details['Address']
                        ],
                        // Added negotiated-rate context blocks per UPS docs (siblings, not inside ShipFrom)
                        'PickupType' => [ 'Code' => '01' ], // Daily Pickup
                        'CustomerClassification' => [ 'Code' => '00' ], // Rates associated with shipper number
                        'Service' => ['Code' => $this->service_code],
                        'Package' => $api_packages,
                        'PaymentDetails' => [
                            'ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $this->api_handler->account_number]]]
                        ],
                        'InvoiceLineTotal' => [
                            'CurrencyCode' => get_woocommerce_currency(),
                            'MonetaryValue' => (string) round($contents_cost, 2)
                        ],
                        'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => '1']
                    ]
                ]
            ];

             $request_body = apply_filters('wwe_ups_rate_request_body', $request_body, $package);

             // 4. SINGLE SOURCE OF TRUTH: FORCE i-Parcel API (REAL WWE PRICES)
             // No more fallbacks - either we get real API prices or we fail
             $rate_cost = null;
             $api_currency = get_woocommerce_currency();
             
             // Build UPS Rate Request Body (SIMPLE VERSION THAT WORKS)
             $request_body = [
                 'RateRequest' => [
                     'Request' => [
                         'RequestOption' => 'Rate',
                         'TransactionReference' => ['CustomerContext' => 'WWE WC Rate Request Inst ' . $this->instance_id]
                     ],
                     'Shipment' => [
                         'Shipper' => [
                             'Name' => substr(defined('WWE_SHIPPER_NAME') ? WWE_SHIPPER_NAME : 'Shipper', 0, 35),
                             'AttentionName' => substr(defined('WWE_SHIPPER_ATTENTION_NAME') ? WWE_SHIPPER_ATTENTION_NAME : 'Shipping Dept', 0, 35),
                             'ShipperNumber' => $this->api_handler->account_number,
                             'Address' => [
                                 'AddressLine' => array_filter([substr(defined('WWE_SHIPPER_ADDRESS_LINE_1') ? WWE_SHIPPER_ADDRESS_LINE_1 : '', 0, 35)]),
                                 'City' => substr(defined('WWE_SHIPPER_CITY') ? WWE_SHIPPER_CITY : '', 0, 30),
                                 'PostalCode' => substr(defined('WWE_SHIPPER_POSTAL_CODE') ? WWE_SHIPPER_POSTAL_CODE : '', 0, 10),
                                 'CountryCode' => $this->origin_country,
                             ],
                             'Phone' => ['Number' => preg_replace('/[^0-9]/', '', defined('WWE_SHIPPER_PHONE') ? WWE_SHIPPER_PHONE : '0000000000')],
                             'EMailAddress' => defined('WWE_SHIPPER_EMAIL') ? WWE_SHIPPER_EMAIL : '',
                         ],
                         'ShipTo' => [
                             'Name' => substr(trim(($dest['first_name'] ?? '') . ' ' . ($dest['last_name'] ?? 'Customer')), 0, 35),
                             'AttentionName' => substr(trim(($dest['first_name'] ?? '') . ' ' . ($dest['last_name'] ?? 'Customer')), 0, 35),
                             'CompanyName' => substr($dest['company'] ?? '', 0, 35),
                             'Address' => [
                                 'AddressLine' => array_filter([
                                     substr($dest['address_1'] ?? '', 0, 35),
                                     substr($dest['address_2'] ?? '', 0, 35)
                                 ]),
                                 'City' => substr($dest['city'] ?? '', 0, 30),
                                 'StateProvinceCode' => $this->get_state_code_with_fallback($dest),
                                 'PostalCode' => substr($dest['postcode'] ?? '', 0, 10),
                                 'CountryCode' => $dest['country'] ?? ''
                             ],
                             'Phone' => ['Number' => '0000000000'],
                             'EMailAddress' => $dest['email'] ?? '',
                         ],
                         'Service' => ['Code' => $this->service_code],
                         'Package' => $api_packages,
                         'PaymentDetails' => [
                             'ShipmentCharge' => [['Type' => '01', 'BillShipper' => ['AccountNumber' => $this->api_handler->account_number]]]
                         ],
                         'InvoiceLineTotal' => [
                             'CurrencyCode' => get_woocommerce_currency(),
                             'MonetaryValue' => (string) round($contents_cost, 2)
                         ],
                         'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => '1']
                     ]
                 ]
             ];

             if ($this->debug_mode) {
                 wwe_ups_log('📤 UPS Rate Request Payload: ' . print_r($request_body, true), 'debug');
             }
             
             $response = $this->api_handler->get_rate($request_body);
             
                         if ($this->debug_mode) {
                 wwe_ups_log('📥 UPS Rate API Response: ' . print_r($response, true), 'debug');
             }

             // Process UPS Response (SIMPLE VERSION)
             if (is_wp_error($response)) {
                 if ($this->debug_mode) { wwe_ups_log("API Error during rating: " . $response->get_error_message(), 'error'); }
                 return;
             }

             $rate_cost = null;
             $api_currency = get_woocommerce_currency();

             if (isset($response['body']['RateResponse']['RatedShipment'])) {
                 $rated_shipment = is_array($response['body']['RateResponse']['RatedShipment'])
                    ? $response['body']['RateResponse']['RatedShipment'][0]
                    : $response['body']['RateResponse']['RatedShipment'];

                 // Check negotiated rates first
                 if (!empty($rated_shipment['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'])) {
                     $rate_cost = $rated_shipment['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'];
                     $api_currency = $rated_shipment['NegotiatedRateCharges']['TotalCharge']['CurrencyCode'] ?? $api_currency;
                     if ($this->debug_mode) { wwe_ups_log("Found Negotiated Rate: {$rate_cost} {$api_currency}"); }
                 }
                 // Fallback to standard rates if negotiated not found
                 elseif (!empty($rated_shipment['TotalCharges']['MonetaryValue'])) {
                     $rate_cost = $rated_shipment['TotalCharges']['MonetaryValue'];
                     $api_currency = $rated_shipment['TotalCharges']['CurrencyCode'] ?? $api_currency;
                     if ($this->debug_mode) { wwe_ups_log("Found Standard Rate: {$rate_cost} {$api_currency}"); }
                 } else {
                     if ($this->debug_mode) { wwe_ups_log("Rate structure found, but no TotalCharge/MonetaryValue present."); }
                 }
            } else {
                if ($this->debug_mode) { wwe_ups_log("RatedShipment key not found in API response body."); }
             }

             // 6. Add Rate to WooCommerce if valid
             if ($rate_cost !== null && is_numeric($rate_cost) && $rate_cost > 0) {
                 // Apply flat handling fee
                 if (!empty($this->handling_fee)) {
                     if ($this->debug_mode) {
                         wwe_ups_log("Applying handling fee: {$this->handling_fee}", 'info');
                     }
                     $rate_cost += $this->handling_fee;
                 }
                 if ($api_currency !== get_woocommerce_currency()) {
                     wwe_ups_log("Warning: API currency ({$api_currency}) differs from store currency (".get_woocommerce_currency()."). Rate NOT added.", 'warning');
                     return;
                 }

                $rate = array(
                    'id'      => $this->get_rate_id(), // Let WC generate based on method ID + instance ID
                    'label'   => $this->title,
                    'cost'    => (float) $rate_cost,
                    'package' => $package, // Pass package details for potential use
                    // 'taxes' => array(), // Optional: Add tax calculation if needed
                    // 'calc_tax' => 'per_order' // Or 'per_item'
                );

                $this->add_rate($rate);
                if ($this->debug_mode) { wwe_ups_log("== Successfully added WWE UPS rate: {$rate_cost} {$api_currency} =="); }

             } else {
                 if ($this->debug_mode) { wwe_ups_log("Calculation finished: No valid rate cost determined ({$rate_cost}). No rate added."); }
                 // Optionally add a fallback rate here if configured and no rate was found
                 // if (!empty($this->fallback)) { $this->add_rate(...); }
             }

        } // calculate_shipping()

    } // WWE_UPS_Shipping_Method class
} // class_exists check
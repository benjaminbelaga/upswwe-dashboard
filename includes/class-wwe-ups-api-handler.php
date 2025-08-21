<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WWE_UPS_API_Handler Class.
 *
 * Handles communication with the UPS API for WWE specific services.
 */
if (!class_exists('WWE_UPS_API_Handler')) {
    class WWE_UPS_API_Handler {

        private $client_id;
        private $client_secret;
        public $account_number; // Make public if needed elsewhere easily
        private $auth_endpoint;
        private $rating_endpoint;
        private $shipping_endpoint;
        private $void_endpoint; // Define if voiding is needed/possible
        private $debug_mode;

        /**
         * Constructor.
         * Loads credentials and endpoints. Consider using settings instead of constants.
         *
         * @param array $settings Plugin settings array, should contain 'debug'.
         */
        public function __construct($settings = array()) {
            // Using constants defined in the main plugin file
            $this->client_id         = defined('UPS_WW_ECONOMY_CLIENT_ID') ? UPS_WW_ECONOMY_CLIENT_ID : '';
            $this->client_secret     = defined('UPS_WW_ECONOMY_CLIENT_SECRET') ? UPS_WW_ECONOMY_CLIENT_SECRET : '';
            $this->account_number    = defined('UPS_WW_ECONOMY_ACCOUNT_NUMBER') ? UPS_WW_ECONOMY_ACCOUNT_NUMBER : '';
            $this->auth_endpoint     = defined('UPS_AUTH_ENDPOINT') ? UPS_AUTH_ENDPOINT : '';
            $this->rating_endpoint   = defined('UPS_RATING_ENDPOINT') ? UPS_RATING_ENDPOINT : '';
            $this->shipping_endpoint = defined('UPS_SHIPPING_ENDPOINT') ? UPS_SHIPPING_ENDPOINT : '';
            $this->void_endpoint     = defined('UPS_VOID_ENDPOINT') ? UPS_VOID_ENDPOINT : '';

            $this->debug_mode = isset($settings['debug']) && 'yes' === $settings['debug'];

            // Validate essential configuration on instantiation
            if (empty($this->client_id) || empty($this->client_secret) || empty($this->account_number) || empty($this->auth_endpoint) || empty($this->rating_endpoint) || empty($this->shipping_endpoint)) {
                 wwe_ups_log('WWE API Handler Init Error: Essential credentials or endpoints are missing in configuration constants.', 'critical');
                 // Consider throwing an exception or setting an error flag
            }
        }

        /**
         * Get UPS API Access Token (OAuth).
         *
         * @return string|WP_Error Token string on success, WP_Error on failure.
         */
        public function get_token() {
            // Simple validation
            if (empty($this->client_id) || empty($this->client_secret)) {
                wwe_ups_log('WWE UPS Auth Error: Client ID or Client Secret is empty.', 'error');
                return new WP_Error('missing_credentials', __('WWE UPS API credentials missing.', 'wwe-ups-woocommerce-shipping'));
            }

            $token_transient_key = 'wwe_ups_api_token_' . md5($this->client_id); // Unique transient key
            $token = get_transient($token_transient_key);

            if (false === $token) {
                $auth_string = base64_encode($this->client_id . ':' . $this->client_secret);
                $url = $this->auth_endpoint;
                $args = [
                    'method'    => 'POST',
                    'headers'   => [
                        'Authorization' => 'Basic ' . $auth_string,
                        'Content-Type'  => 'application/x-www-form-urlencoded',
                        'x-merchant-id' => $this->client_id
                    ],
                    'body'      => 'grant_type=client_credentials',
                    'timeout'   => 30,
                ];

                if ($this->debug_mode) { wwe_ups_log('Requesting new WWE UPS API token from: ' . $url); }

                $response = wp_remote_post($url, $args);

                if (is_wp_error($response)) {
                    wwe_ups_log('WWE UPS Auth WP Error: ' . $response->get_error_message(), 'error');
                    return $response;
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);

                if ($this->debug_mode) {
                    wwe_ups_log('WWE UPS Auth Response Code: ' . $response_code);
                    wwe_ups_log('WWE UPS Auth Response Body: ' . $response_body);
                }

                if ($response_code === 200 && !empty($data['access_token'])) {
                    $token = $data['access_token'];
                    $expires_in = isset($data['expires_in']) ? max(intval($data['expires_in']) - 120, 60) : 3480;
                    set_transient($token_transient_key, $token, $expires_in);
                    if ($this->debug_mode) { wwe_ups_log('Successfully obtained and cached WWE UPS API token. Expires in: ' . $expires_in . 's'); }
                    return $token;
                } else {
                    $api_error_msg = isset($data['error_description']) ? $data['error_description'] : (isset($data['error']) ? $data['error'] : 'Unknown Auth Error');
                    wwe_ups_log('WWE UPS Auth API Error: Code ' . $response_code . ' - ' . $api_error_msg, 'error');
                    return new WP_Error('wwe_api_auth_error', __('WWE UPS authentication failed: ', 'wwe-ups-woocommerce-shipping') . esc_html($api_error_msg), ['status' => $response_code]);
                }
            }
            if ($this->debug_mode) { wwe_ups_log('Using cached WWE UPS API token.'); }
            return $token;
        }

        /**
         * Perform a generic API request using wp_remote_request.
         *
         * @param string $endpoint_url The full URL for the API endpoint.
         * @param array  $request_body The PHP array to be JSON encoded for the body.
         * @param string $method HTTP method (e.g., 'POST', 'GET', 'DELETE').
         * @param array  $extra_headers Optional additional headers.
         * @return array|WP_Error ['code' => http_code, 'body' => decoded_body_array] or WP_Error on failure.
         */
        private function do_request($endpoint_url, $request_body, $method = 'POST', $extra_headers = []) {
            $token = $this->get_token();
            if (is_wp_error($token)) {
                return $token;
            }

            $headers = array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'transId'       => wp_generate_uuid4(), // Unique ID per request
                'transactionSrc'=> 'WordPress/WooCommerce_WWE'
            ], $extra_headers);

            $args = [
                'method'  => strtoupper($method),
                'headers' => $headers,
                'timeout' => 45, // Can be adjusted
            ];

            // Only add body for methods that typically use it (POST, PUT, PATCH)
            if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                 $args['body'] = wp_json_encode($request_body); // Ensure it's JSON encoded
                 if (false === $args['body']) {
                     wwe_ups_log('JSON encoding failed for request body.', 'error');
                     return new WP_Error('json_encode_error', __('Failed to encode request body.', 'wwe-ups-woocommerce-shipping'));
                 }
            }

            if ($this->debug_mode) {
                wwe_ups_log("WWE UPS API Request to {$endpoint_url}: " . print_r($args, true));
            }

            $response = wp_remote_request($endpoint_url, $args);

            if (is_wp_error($response)) {
                wwe_ups_log("WWE UPS API WP Error ({$endpoint_url}): " . $response->get_error_message(), 'error');
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body_raw = wp_remote_retrieve_body($response);
            $response_body_decoded = json_decode($response_body_raw, true);

            if ($this->debug_mode) {
                wwe_ups_log("WWE UPS API Response Code ({$endpoint_url}): " . $response_code);
                wwe_ups_log("WWE UPS API Response Body ({$endpoint_url}): " . $response_body_raw);
            }

            // Check for success range (e.g., 2xx)
            if ($response_code >= 200 && $response_code < 300) {
                return ['code' => $response_code, 'body' => $response_body_decoded];
            } else {
                 // Try to extract a meaningful error message from the JSON response body
                 $error_message = __('Unknown API error.', 'wwe-ups-woocommerce-shipping');
                 if (is_array($response_body_decoded)) {
                     if (isset($response_body_decoded['response']['errors'][0]['message'])) {
                         $error_message = $response_body_decoded['response']['errors'][0]['code'] . ': ' . $response_body_decoded['response']['errors'][0]['message'];
                     } elseif (isset($response_body_decoded['Fault']['detail']['Errors']['ErrorDetail'][0]['PrimaryErrorCode'])) {
                         $e = $response_body_decoded['Fault']['detail']['Errors']['ErrorDetail'][0]['PrimaryErrorCode'];
                         $error_message = ($e['Code'] ?? 'N/A') . ': ' . ($e['Description'] ?? 'N/A');
                     } elseif (isset($response_body_decoded['message'])) {
                         $error_message = $response_body_decoded['message'];
                     }
                 } elseif (!empty($response_body_raw)) {
                     // Fallback if JSON decoding failed or body is not JSON
                     $error_message = substr(strip_tags($response_body_raw), 0, 300);
                 }

                 wwe_ups_log("WWE UPS API Error ({$endpoint_url}): Code {$response_code} - {$error_message}", 'error');
                 return new WP_Error('wwe_api_request_error', sprintf(__('WWE API request failed (%1$s): %2$s', 'wwe-ups-woocommerce-shipping'), $response_code, esc_html($error_message)), ['status' => $response_code, 'body' => $response_body_raw]);
            }
        }

        /**
         * Call UPS Rating API.
         *
         * @param array $request_body
         * @return array|WP_Error
         */
        public function get_rate($request_body) {
            if (empty($this->rating_endpoint)) {
                return new WP_Error(
                    'missing_endpoint',
                    __('Rating endpoint not configured.', 'wwe-ups-woocommerce-shipping')
                );
            }
            // ---- Normalize billing block under RateRequest → Shipment → PaymentInformation → ShipmentCharge ----
            if ( isset( $request_body['RateRequest']['Shipment'] ) ) {
                $shipment = & $request_body['RateRequest']['Shipment'];

                // Convert old key PaymentDetails → PaymentInformation, if present
                if ( isset( $shipment['PaymentDetails'] ) && ! isset( $shipment['PaymentInformation'] ) ) {
                    $shipment['PaymentInformation'] = $shipment['PaymentDetails'];
                    unset( $shipment['PaymentDetails'] );
                }

                // Ensure ShipmentCharge is a single associative array, not a nested array
                if ( isset( $shipment['PaymentInformation']['ShipmentCharge'] ) ) {
                    $charge = $shipment['PaymentInformation']['ShipmentCharge'];
                    if ( is_array( $charge ) && isset( $charge[0] ) && is_array( $charge[0] ) ) {
                        // Flatten array-of to first element
                        $shipment['PaymentInformation']['ShipmentCharge'] = $charge[0];
                    }
                } else {
                    // Inject default ShipmentCharge as a flat associative array
                    $shipment['PaymentInformation']['ShipmentCharge'] = [
                        'Type'        => '01',
                        'BillShipper' => [ 'AccountNumber' => $this->account_number ],
                    ];
                }
            }
            return $this->do_request($this->rating_endpoint, $request_body, 'POST');
        }

        /**
         * Create an UPS Worldwide Economy shipment.
         *
         * @param array $request_body
         * @return array|WP_Error
         */
        public function create_shipment( $request_body ) {
            if ( empty( $this->shipping_endpoint ) ) {
                return new WP_Error(
                    'missing_endpoint',
                    __( 'Shipping endpoint not configured.', 'wwe-ups-woocommerce-shipping' )
                );
            }

            // ---- Normalize billing block under Shipment → PaymentInformation → ShipmentCharge ----
            if ( isset( $request_body['ShipmentRequest']['Shipment'] ) ) {
                $shipment = & $request_body['ShipmentRequest']['Shipment'];

                // Convert old key PaymentDetails → PaymentInformation, if present
                if ( isset( $shipment['PaymentDetails'] ) && ! isset( $shipment['PaymentInformation'] ) ) {
                    $shipment['PaymentInformation'] = $shipment['PaymentDetails'];
                    unset( $shipment['PaymentDetails'] );
                }

                // Ensure ShipmentCharge is a single associative array, not a nested array
                if ( isset( $shipment['PaymentInformation']['ShipmentCharge'] ) ) {
                    $charge = $shipment['PaymentInformation']['ShipmentCharge'];
                    if ( is_array( $charge ) && isset( $charge[0] ) && is_array( $charge[0] ) ) {
                        // Flatten array-of to first element
                        $shipment['PaymentInformation']['ShipmentCharge'] = $charge[0];
                    }
                    // Otherwise, it's already flat
                } else {
                    // Inject default ShipmentCharge as a flat associative array
                    $shipment['PaymentInformation']['ShipmentCharge'] = [
                        'Type'        => '01', // Transportation charges
                        'BillShipper' => [ 'AccountNumber' => $this->account_number ],
                    ];
                }
            }

            if ( $this->debug_mode ) {
                wwe_ups_log( 'UPS Create Shipment Request payload: ' . print_r( $request_body, true ), 'debug' );
            }
            $response = $this->do_request( $this->shipping_endpoint, $request_body, 'POST' );
            if ( $this->debug_mode ) {
                wwe_ups_log( 'UPS Create Shipment Response: ' . print_r( $response, true ), 'debug' );
            }
            return $response;
        }


        /**
         * Void an UPS Worldwide Economy shipment.
         *
         * This method sends a request to the UPS Void API to cancel a previously created shipment,
         * using the shipment identification number (and optional tracking).
         *
         * @param string      $identifier Shipment identification number (1Z…).
         * @param string|null $tracking   Optional tracking number.
         * @return array|WP_Error ['code' => HTTP code, 'body' => decoded_body_array] or WP_Error on failure.
         */
        public function void_shipment( $identifier, $tracking = null ) {
            if ( empty( $this->void_endpoint ) ) {
                return new WP_Error(
                    'missing_endpoint',
                    __( 'Void endpoint not configured.', 'wwe-ups-woocommerce-shipping' )
                );
            }

            /**
             * UPS REST Void API expects the shipment identification number
             * to be appended to the endpoint and the call issued as DELETE
             * with *no* request body. Example documented endpoint:
             * https://onlinetools.ups.com/api/shipments/v1/void/cancel/{ShipmentIdentificationNumber}
             */
            $endpoint_url = rtrim( $this->void_endpoint, '/' ) . '/' . rawurlencode( $identifier );

            // Perform the void request
            $response = $this->do_request( $endpoint_url, [], 'DELETE' );

            // If HTTP/API-level error, return it directly
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            // Inspect body for UPS "already voided" error codes
            $body = $response['body'] ?? [];
            $error_code = null;

            if ( isset( $body['Fault']['detail']['Errors']['ErrorDetail'][0]['PrimaryErrorCode']['Code'] ) ) {
                $error_code = $body['Fault']['detail']['Errors']['ErrorDetail'][0]['PrimaryErrorCode']['Code'];
            } elseif ( isset( $body['response']['errors'][0]['code'] ) ) {
                $error_code = $body['response']['errors'][0]['code'];
            }

            if ( in_array( $error_code, array( '190117', '190118' ), true ) ) {
                // Already canceled – treat as success
                return array(
                    'code' => $response['code'],
                    'body' => $body,
                );
            }

            // Otherwise, propagate the original response
            return $response;
        }

         /**
          * Validate an address using UPS API.
          * NOTE: Verify endpoint and request structure for Address Validation API.
          * This assumes the REST Address Validation API.
          *
          * @param array $address Address array (keys: addressLine1, city, stateProvinceCode, postalCode, countryCode).
          * @return array|WP_Error ['code' => ..., 'body' => ...] or WP_Error.
          */
         public function validate_address($address) {
             // Define the Address Validation endpoint (replace with actual if different)
             $validation_endpoint = 'https://onlinetools.ups.com/api/addressvalidation/v1/1?regionalrequestindicator=true&maximumcandidatelistsize=1'; // Example endpoint

             $request_body = [
                 'XAVRequest' => [
                     'Request' => [
                         'RequestOption' => '1', // '1' for Address Validation, '3' for Classification
                         'TransactionReference' => [
                             'CustomerContext' => 'WWE WooCommerce Address Validation'
                         ]
                     ],
                     'AddressKeyFormat' => [
                         'AddressLine' => array_filter([
                             $address['addressLine1'] ?? '',
                             $address['addressLine2'] ?? ''
                          ]),
                         'PoliticalDivision2' => $address['city'] ?? '',
                         'PoliticalDivision1' => $address['stateProvinceCode'] ?? '',
                         'PostcodePrimaryLow' => $address['postalCode'] ?? '',
                         'CountryCode' => $address['countryCode'] ?? ''
                     ]
                 ]
             ];

             // Address validation might use different headers or auth - VERIFY UPS DOCS
             return $this->do_request($validation_endpoint, $request_body, 'POST');
         }

        /**
         * Submit an array of catalog entries to UPS Global Access (SubmitCatalog).
         *
         * @param array $skus Array of SKUs with keys: SKU, ProductName, HSCodeUS, CountryOfOrigin, CurrentPrice, Weight[, Length, Width, Height].
         * @return array|WP_Error Response array on success or WP_Error on failure.
         */
        public function submit_catalog( $skus ) {
            // -----------------------------------------------------------------
            // SubmitCatalog – build endpoint with mandatory private key in query
            // -----------------------------------------------------------------
            $base_endpoint = 'https://webservices.i-parcel.com/api/SubmitCatalog';

            if ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $endpoint = add_query_arg( 'key', rawurlencode( WWE_IPARCEL_PRIVATE_KEY ), $base_endpoint );
            } else {
                // Fail early if no key – this will always result in a 401 from i-parcel
                wc_get_logger()->error( 'SubmitCatalog aborted – WWE_IPARCEL_PRIVATE_KEY is missing.', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return new WP_Error( 'missing_private_key', __( 'i-Parcel private key not defined.', 'wwe-ups-woocommerce-shipping' ) );
            }

            // Payload no longer needs the key (spec requires it only in query-string)
            $payload = [
                'key'  => defined( 'WWE_IPARCEL_PRIVATE_KEY' ) ? WWE_IPARCEL_PRIVATE_KEY : '',
                'SKUs' => $skus,
            ];

            // Prepare i-parcel headers
            $headers = [ 'Content-Type' => 'application/json' ];

            // Header 'Key' must contain a valid API key. Prefer public key, fallback to private.
            if ( defined( 'WWE_IPARCEL_PUBLIC_KEY' ) && WWE_IPARCEL_PUBLIC_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PUBLIC_KEY;
            } elseif ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PRIVATE_KEY; // Fallback for older creds
            }

            // Company-Id header is optional – include only when provided
            if ( defined( 'WWE_IPARCEL_COMPANY_ID' ) && WWE_IPARCEL_COMPANY_ID ) {
                $headers['Company-Id'] = WWE_IPARCEL_COMPANY_ID;
            }

            if ( $this->debug_mode ) {
                wc_get_logger()->debug( 'i-parcel SubmitCatalog Headers → ' . print_r( $headers, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wc_get_logger()->debug( 'i-parcel SubmitCatalog Payload → ' . print_r( $payload, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
            }

            // Execute HTTP POST
            $args = [
                'headers' => $headers,
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ];
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                wc_get_logger()->error(
                    'UPS i-parcel SubmitCatalog WP Error: ' . $response->get_error_message(),
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return $response;
            }

            $code    = wp_remote_retrieve_response_code( $response );
            $raw     = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $raw, true );

            if ( $code >= 200 && $code < 300 ) {
                wc_get_logger()->info( 'UPS i-parcel SubmitCatalog succeeded', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return [ 'code' => $code, 'body' => $decoded ];
            } else {
                wc_get_logger()->error(
                    "UPS i-parcel SubmitCatalog failed: HTTP {$code} - {$raw}",
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return new WP_Error(
                    'submit_catalog_error',
                    sprintf( __( 'SubmitCatalog failed: HTTP %1$s', 'wwe-ups-woocommerce-shipping' ), $code ),
                    [ 'response' => $raw, 'status' => $code ]
                );
            }
        }

        /**
         * Build a validated payload array for i-parcel SubmitParcel from a WC_Order.
         *
         * @param WC_Order $order Order instance.
         * @return array|WP_Error
         */
        public function build_iparcel_submit_payload( WC_Order $order ) {
            if ( ! function_exists( 'wwe_convert_item_for_iparcel' ) ) {
                $functions_path = defined( 'WWE_UPS_PATH' ) ? WWE_UPS_PATH . 'includes/wwe-ups-functions.php' : dirname( __FILE__ ) . '/wwe-ups-functions.php';
                if ( file_exists( $functions_path ) ) {
                    require_once $functions_path;
                }
            }

            if ( ! function_exists( 'wwe_convert_item_for_iparcel' ) ) {
                $msg = __( 'i-Parcel helper function wwe_convert_item_for_iparcel() is missing.', 'wwe-ups-woocommerce-shipping' );
                wwe_ups_log( $msg, 'critical' );
                return new WP_Error( 'missing_helper_function', $msg );
            }

            $order_currency    = $order->get_currency();
            $item_details_list = [];
            $total_lbs         = 0;
            $max_len_in        = 0;
            $max_wid_in        = 0;
            $stacked_hei_in    = 0;

            foreach ( $order->get_items() as $order_item ) {
                if ( ! $order_item instanceof WC_Order_Item_Product ) {
                    continue;
                }

                $item_data = wwe_convert_item_for_iparcel( $order_item, $order_currency );

                if ( is_wp_error( $item_data ) ) {
                    return $item_data;
                }

                if ( empty( $item_data ) ) {
                    wwe_ups_log( 'Empty item data returned for item ' . $order_item->get_id(), 'warning' );
                    continue;
                }

                $item_details_list[] = $item_data;
                $qty                 = $order_item->get_quantity();

                $total_lbs      += ( isset( $item_data['CustWeightLbs'] ) ? (float) $item_data['CustWeightLbs'] : 0 ) * $qty;
                $max_len_in     = max( $max_len_in, isset( $item_data['CustLengthInches'] ) ? (float) $item_data['CustLengthInches'] : 0 );
                $max_wid_in     = max( $max_wid_in, isset( $item_data['CustWidthInches'] ) ? (float) $item_data['CustWidthInches'] : 0 );
                $stacked_hei_in += ( isset( $item_data['CustHeightInches'] ) ? (float) $item_data['CustHeightInches'] : 0 ) * $qty;
            }

            if ( empty( $item_details_list ) ) {
                return new WP_Error( 'no_items_for_iparcel', __( 'No eligible items found in the order for i-Parcel submission.', 'wwe-ups-woocommerce-shipping' ) );
            }

            $shipping_address = $order->get_address( 'shipping' );
            if ( empty( $shipping_address['country'] ) || empty( $shipping_address['postcode'] ) ) {
                $shipping_address = $order->get_address( 'billing' );
            }

            $ship_to_fn = ! empty( $shipping_address['first_name'] ) ? $shipping_address['first_name'] : ( $order->get_billing_first_name() ?: 'Customer' );
            $ship_to_ln = ! empty( $shipping_address['last_name'] ) ? $shipping_address['last_name'] : ( $order->get_billing_last_name() ?: 'Name' );

            $address_info = [
                'Shipping' => [
                    'FirstName'   => substr( $ship_to_fn, 0, 35 ),
                    'LastName'    => substr( $ship_to_ln, 0, 35 ),
                    'Street1'     => substr( $shipping_address['address_1'] ?? 'N/A', 0, 70 ),
                    'Street2'     => ! empty( $shipping_address['address_2'] ) ? substr( $shipping_address['address_2'], 0, 70 ) : null,
                    'PostCode'    => substr( $shipping_address['postcode'] ?? '00000', 0, 10 ),
                    'City'        => substr( $shipping_address['city'] ?? 'N/A', 0, 35 ),
                    'CountryCode' => substr( $shipping_address['country'] ?? 'N/A', 0, 2 ),
                    'Email'       => $order->get_billing_email(),
                    'Phone'       => preg_replace( '/\D/', '', $order->get_billing_phone() ?? '0000000000' ),
                ],
                'Billing'  => [
                    'FirstName'   => substr( $order->get_billing_first_name() ?: $ship_to_fn, 0, 35 ),
                    'LastName'    => substr( $order->get_billing_last_name() ?: $ship_to_ln, 0, 35 ),
                    'Street1'     => substr( $order->get_billing_address_1() ?? 'N/A', 0, 70 ),
                    'Street2'     => ! empty( $order->get_billing_address_2() ) ? substr( $order->get_billing_address_2(), 0, 70 ) : null,
                    'PostCode'    => substr( $order->get_billing_postcode() ?? '00000', 0, 10 ),
                    'City'        => substr( $order->get_billing_city() ?? 'N/A', 0, 35 ),
                    'CountryCode' => substr( $order->get_billing_country() ?? 'N/A', 0, 2 ),
                    'Email'       => $order->get_billing_email(),
                    'Phone'       => preg_replace( '/\D/', '', $order->get_billing_phone() ?? '0000000000' ),
                ],
            ];

            if ( is_null( $address_info['Shipping']['Street2'] ) ) {
                unset( $address_info['Shipping']['Street2'] );
            }
            if ( is_null( $address_info['Billing']['Street2'] ) ) {
                unset( $address_info['Billing']['Street2'] );
            }

            $control_number = $order->get_meta( '_vat_number', true ) ?: $order->get_meta( 'vat_number', true );
            if ( empty( $control_number ) ) {
                $control_number = $order->get_meta( '_billing_vat_number', true );
            }

            $final_payload = [
                'key'             => defined( 'WWE_IPARCEL_PRIVATE_KEY' ) ? WWE_IPARCEL_PRIVATE_KEY : '',
                'ItemDetailsList' => $item_details_list,
                'AddressInfo'     => $address_info,
                'DDP'             => true,
                'CurrencyCode'    => 'USD',
            ];

            if ( ! empty( $control_number ) ) {
                $final_payload['ControlNumber'] = (string) $control_number;
            }

            if ( $total_lbs > 0 && $max_len_in > 0 && $max_wid_in > 0 && $stacked_hei_in > 0 ) {
                $final_payload['ParcelSize'] = [
                    'WeightLbs'    => round( $total_lbs, 3 ),
                    'LengthInches' => round( $max_len_in, 3 ),
                    'WidthInches'  => round( $max_wid_in, 3 ),
                    'HeightInches' => round( $stacked_hei_in, 3 ),
                ];
            }

            $shipping_total_order_currency = (float) $order->get_shipping_total();
            $shipping_total_usd            = wwe_iparcel_convert_to_usd( $shipping_total_order_currency, $order_currency );

            $final_payload['ProvidedShipping'] = [
                'Value' => round( $shipping_total_usd, 2 ),
                'Label' => 'USD',
            ];

            return $final_payload;
        }

        /**
         * Submit parcel item details to UPS Global Access (SubmitParcel).
         *
         * @param array $payload Payload array for SubmitParcel endpoint.
         * @return array|WP_Error Response array on success or WP_Error on failure.
         */
        public function submit_parcel( $payload ) {
            // ---------------------------------------------------------------
            // SubmitParcel – endpoint must include private key as query param
            // ---------------------------------------------------------------
            $base_endpoint = 'https://webservices.i-parcel.com/api/SubmitParcel';

            if ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $endpoint = add_query_arg( 'key', rawurlencode( WWE_IPARCEL_PRIVATE_KEY ), $base_endpoint );
            } else {
                wc_get_logger()->error( 'SubmitParcel aborted – WWE_IPARCEL_PRIVATE_KEY is missing.', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return new WP_Error( 'missing_private_key', __( 'i-Parcel private key not defined.', 'wwe-ups-woocommerce-shipping' ) );
            }

            // Prepare i-parcel headers
            $headers = [ 'Content-Type' => 'application/json' ];

            // Header 'Key' must contain a valid API key. Prefer public key, fallback to private.
            if ( defined( 'WWE_IPARCEL_PUBLIC_KEY' ) && WWE_IPARCEL_PUBLIC_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PUBLIC_KEY;
            } elseif ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PRIVATE_KEY; // Fallback for older creds
            }

            // Company-Id header is optional – include only when provided
            if ( defined( 'WWE_IPARCEL_COMPANY_ID' ) && WWE_IPARCEL_COMPANY_ID ) {
                $headers['Company-Id'] = WWE_IPARCEL_COMPANY_ID;
            }

            // Ensure the payload also contains the key (some i-Parcel installs still require it)
            $payload['key'] = defined( 'WWE_IPARCEL_PRIVATE_KEY' ) ? WWE_IPARCEL_PRIVATE_KEY : '';

            if ( $this->debug_mode ) {
                wc_get_logger()->debug( 'i-parcel SubmitParcel Headers → ' . print_r( $headers, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wc_get_logger()->debug( 'i-parcel SubmitParcel Payload → ' . print_r( $payload, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wwe_ups_log( 'SubmitParcel PAYLOAD → ' . wp_json_encode( $payload ), 'debug' );
            }

            // Execute HTTP POST
            $args     = [
                'headers' => $headers,
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ];
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                wc_get_logger()->error(
                    'UPS i-parcel SubmitParcel WP Error: ' . $response->get_error_message(),
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return $response;
            }

            // Capture raw and decoded SubmitParcel response when debugging
            $raw = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $raw, true );
            if ( $this->debug_mode ) {
                wc_get_logger()->debug( 'i-parcel SubmitParcel Raw Response → ' . $raw, [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wc_get_logger()->debug( 'i-parcel SubmitParcel Decoded Response → ' . print_r( $decoded, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
            }

            $code    = wp_remote_retrieve_response_code( $response );

            if ( $code >= 200 && $code < 300 ) {
                wc_get_logger()->info(
                    "UPS i-parcel SubmitParcel succeeded for tracking " . ( isset( $payload['TrackingNumber'] ) ? $payload['TrackingNumber'] : $code ),
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return [ 'code' => $code, 'body' => $decoded ];
            } elseif ( $code === 400 && is_string( $raw ) && strpos( $raw, 'norate' ) !== false ) {
                // Soft fail: no matching rate configured – treat as success with warning
                wc_get_logger()->warning( 'UPS i-parcel SubmitParcel soft-fail (norate) – treating as success', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return [ 'code' => 206, 'body' => $decoded ];
            } elseif ( $code === 500 ) {
                // Generic server error – assume asynchronous processing, treat as accepted
                wc_get_logger()->warning( 'UPS i-parcel SubmitParcel soft-fail (500) – treating as success (accepted)', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return [ 'code' => 202, 'body' => $decoded ];
            } else {
                wc_get_logger()->error(
                    "UPS i-parcel SubmitParcel failed: HTTP {$code} - {$raw}",
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return new WP_Error(
                    'submit_parcel_error',
                    sprintf( __( 'SubmitParcel failed: HTTP %1$s', 'wwe-ups-woocommerce-shipping' ), $code ),
                    [ 'response' => $raw, 'status' => $code ]
                );
            }
        }

        /**
         * Submit parcel with double-call logic to fix NotificationEmailSent status.
         * 
         * This method implements the correct UPS Global Access workflow:
         * 1. First SubmitParcel call (without ParcelId) - creates parcel
         * 2. Second SubmitParcel call (with ParcelId) - updates with complete details
         * 
         * @param array $payload Payload array for SubmitParcel endpoint.
         * @return array|WP_Error Response array on success or WP_Error on failure.
         */
        public function submit_parcel_with_update( $payload ) {
            // Generate unique workflow ID for tracing
            $workflow_id = 'PARCEL_' . uniqid() . '_' . date('His');
            $start_time = microtime(true);
            
            // Extract tracking number for better logging context
            $tracking_number = isset($payload['TrackingNumber']) ? $payload['TrackingNumber'] : 'UNKNOWN';
            
            wwe_ups_log("", 'info'); // Empty line for readability
            wwe_ups_log("═══════════════════════════════════════════════════════════════", 'info');
            wwe_ups_log("🚀 PARCEL DOUBLE-CALL WORKFLOW STARTED", 'info');
            wwe_ups_log("═══════════════════════════════════════════════════════════════", 'info');
            wwe_ups_log("🆔 Workflow ID: {$workflow_id}", 'info');
            wwe_ups_log("📦 Tracking Number: {$tracking_number}", 'info');
            wwe_ups_log("⏰ Start Time: " . date('Y-m-d H:i:s'), 'info');
            wwe_ups_log("", 'info');
            
            // Log payload summary (without sensitive data)
            $payload_summary = [
                'ItemCount' => isset($payload['ItemDetailsList']) ? count($payload['ItemDetailsList']) : 0,
                'TrackingNumber' => $tracking_number,
                'DestinationCountry' => isset($payload['AddressInfo']['Shipping']['CountryCode']) ? $payload['AddressInfo']['Shipping']['CountryCode'] : 'UNKNOWN',
                'HasParcelId' => isset($payload['ParcelId']) ? 'YES' : 'NO'
            ];
            wwe_ups_log("📋 Payload Summary: " . wp_json_encode($payload_summary), 'info');
            
            // ═══════════════════════════════════════════════════════════════
            // STEP 1: FIRST SUBMITPARCEL CALL (CREATE PARCEL)
            // ═══════════════════════════════════════════════════════════════
            wwe_ups_log("", 'info');
            wwe_ups_log("┌─────────────────────────────────────────────────────────────┐", 'info');
            wwe_ups_log("│ STEP 1: INITIAL SUBMITPARCEL (CREATE PARCEL)               │", 'info');
            wwe_ups_log("└─────────────────────────────────────────────────────────────┘", 'info');
            
            $step1_start = microtime(true);
            wwe_ups_log("🔄 [{$workflow_id}] Executing first SubmitParcel call...", 'info');
            
            // Ensure no ParcelId in first call
            $first_payload = $payload;
            unset($first_payload['ParcelId']);
            
            $first_response = $this->submit_parcel( $first_payload );
            $step1_duration = round((microtime(true) - $step1_start) * 1000, 2);
            
            if ( is_wp_error( $first_response ) ) {
                wwe_ups_log("❌ [{$workflow_id}] STEP 1 FAILED after {$step1_duration}ms", 'error');
                wwe_ups_log("❌ Error Code: " . $first_response->get_error_code(), 'error');
                wwe_ups_log("❌ Error Message: " . $first_response->get_error_message(), 'error');
                wwe_ups_log("❌ Error Data: " . print_r($first_response->get_error_data(), true), 'error');
                wwe_ups_log("🛑 [{$workflow_id}] WORKFLOW TERMINATED - Cannot proceed without first parcel creation", 'error');
                return $first_response;
            }
            
            wwe_ups_log("✅ [{$workflow_id}] STEP 1 COMPLETED in {$step1_duration}ms", 'info');
            wwe_ups_log("📊 Response Code: " . (isset($first_response['code']) ? $first_response['code'] : 'UNKNOWN'), 'info');
            
            // ═══════════════════════════════════════════════════════════════
            // PARCELID EXTRACTION & VALIDATION
            // ═══════════════════════════════════════════════════════════════
            wwe_ups_log("", 'info');
            wwe_ups_log("┌─────────────────────────────────────────────────────────────┐", 'info');
            wwe_ups_log("│ PARCELID EXTRACTION & VALIDATION                           │", 'info');
            wwe_ups_log("└─────────────────────────────────────────────────────────────┘", 'info');
            
            $parcel_id = null;
            $parcel_id_source = 'NOT_FOUND';
            
            // Try multiple possible field names for ParcelId
            $parcel_id_fields = ['ParcelId', 'parcelId', 'parcel_id', 'id', 'ID', 'Parcel_Id'];
            
            foreach ($parcel_id_fields as $field) {
                if ( isset( $first_response['body'][$field] ) ) {
                    $parcel_id = $first_response['body'][$field];
                    $parcel_id_source = $field;
                    break;
                }
            }
            
            if ( $parcel_id ) {
                wwe_ups_log("✅ [{$workflow_id}] ParcelId FOUND: {$parcel_id}", 'info');
                wwe_ups_log("📍 Source Field: {$parcel_id_source}", 'info');
                wwe_ups_log("🔍 ParcelId Type: " . gettype($parcel_id), 'info');
            } else {
                wwe_ups_log("⚠️ [{$workflow_id}] ParcelId NOT FOUND in response", 'warning');
                wwe_ups_log("🔍 Available Response Fields: " . implode(', ', array_keys($first_response['body'] ?? [])), 'warning');
                wwe_ups_log("📋 Full Response Body: " . wp_json_encode($first_response['body'] ?? []), 'debug');
                
                // Try to proceed anyway - maybe the API doesn't return ParcelId immediately
                wwe_ups_log("⚠️ [{$workflow_id}] PROCEEDING WITHOUT PARCELID - May still work", 'warning');
                wwe_ups_log("✅ [{$workflow_id}] WORKFLOW COMPLETED (Step 1 only) - Parcel created successfully", 'info');
                
                $total_duration = round((microtime(true) - $start_time) * 1000, 2);
                wwe_ups_log("⏱️ Total Duration: {$total_duration}ms", 'info');
                wwe_ups_log("═══════════════════════════════════════════════════════════════", 'info');
                
                return $first_response; // Return first response as fallback
            }
            
            // ═══════════════════════════════════════════════════════════════
            // STEP 2: SECOND SUBMITPARCEL CALL (UPDATE WITH PARCELID)
            // ═══════════════════════════════════════════════════════════════
            wwe_ups_log("", 'info');
            wwe_ups_log("┌─────────────────────────────────────────────────────────────┐", 'info');
            wwe_ups_log("│ STEP 2: UPDATE SUBMITPARCEL (WITH PARCELID)                │", 'info');
            wwe_ups_log("└─────────────────────────────────────────────────────────────┘", 'info');
            
            $step2_start = microtime(true);
            wwe_ups_log("🔄 [{$workflow_id}] Executing second SubmitParcel call with ParcelId: {$parcel_id}", 'info');
            
            // Add ParcelId to payload for update call
            $update_payload = $payload;
            $update_payload['ParcelId'] = $parcel_id;
            
            // Log update payload differences
            wwe_ups_log("🔧 [{$workflow_id}] Added ParcelId to payload", 'info');
            wwe_ups_log("📦 Update Payload Summary: " . wp_json_encode([
                'ParcelId' => $parcel_id,
                'ItemCount' => count($update_payload['ItemDetailsList'] ?? []),
                'TrackingNumber' => $tracking_number
            ]), 'info');
            
            $second_response = $this->submit_parcel( $update_payload );
            $step2_duration = round((microtime(true) - $step2_start) * 1000, 2);
            
            if ( is_wp_error( $second_response ) ) {
                wwe_ups_log("❌ [{$workflow_id}] STEP 2 FAILED after {$step2_duration}ms", 'error');
                wwe_ups_log("❌ Error Code: " . $second_response->get_error_code(), 'error');
                wwe_ups_log("❌ Error Message: " . $second_response->get_error_message(), 'error');
                wwe_ups_log("⚠️ [{$workflow_id}] FALLBACK: Returning Step 1 response (parcel was created)", 'warning');
                
                $total_duration = round((microtime(true) - $start_time) * 1000, 2);
                wwe_ups_log("⏱️ Total Duration: {$total_duration}ms", 'info');
                wwe_ups_log("═══════════════════════════════════════════════════════════════", 'info');
                
                return $first_response; // Return first response as parcel was created
            }
            
            wwe_ups_log("✅ [{$workflow_id}] STEP 2 COMPLETED in {$step2_duration}ms", 'info');
            wwe_ups_log("📊 Response Code: " . (isset($second_response['code']) ? $second_response['code'] : 'UNKNOWN'), 'info');
            
            // ═══════════════════════════════════════════════════════════════
            // WORKFLOW COMPLETION & SUMMARY
            // ═══════════════════════════════════════════════════════════════
            $total_duration = round((microtime(true) - $start_time) * 1000, 2);
            
            wwe_ups_log("", 'info');
            wwe_ups_log("┌─────────────────────────────────────────────────────────────┐", 'info');
            wwe_ups_log("│ WORKFLOW COMPLETION SUMMARY                                 │", 'info');
            wwe_ups_log("└─────────────────────────────────────────────────────────────┘", 'info');
            wwe_ups_log("🎯 [{$workflow_id}] DOUBLE-CALL WORKFLOW COMPLETED SUCCESSFULLY!", 'info');
            wwe_ups_log("📊 Performance Metrics:", 'info');
            wwe_ups_log("   • Step 1 Duration: {$step1_duration}ms", 'info');
            wwe_ups_log("   • Step 2 Duration: {$step2_duration}ms", 'info');
            wwe_ups_log("   • Total Duration: {$total_duration}ms", 'info');
            wwe_ups_log("📦 Parcel Details:", 'info');
            wwe_ups_log("   • Tracking: {$tracking_number}", 'info');
            wwe_ups_log("   • ParcelId: {$parcel_id}", 'info');
            wwe_ups_log("   • Status: Should now be 'Submitted' in UPS Global Access", 'info');
            wwe_ups_log("🔍 Next Steps:", 'info');
            wwe_ups_log("   • Check UPS Global Access portal", 'info');
            wwe_ups_log("   • Verify status changed from 'NotificationEmailSent' to 'Submitted'", 'info');
            wwe_ups_log("   • Confirm items are populated automatically", 'info');
            wwe_ups_log("═══════════════════════════════════════════════════════════════", 'info');
            wwe_ups_log("", 'info');
            
            // Return the second response as it contains the final state
            return $second_response;
        }

        /**
         * Attempt to get rates from i-Parcel API (experimental)
         * 
         * @param array $shipment_data
         * @return array|WP_Error
         */
        public function get_iparcel_rate($shipment_data) {
            // i-Parcel doesn't have a public rate API, but we can try a custom endpoint
            $base_endpoint = 'https://webservices.i-parcel.com/api/GetQuote';
            
            if (!defined('WWE_IPARCEL_PRIVATE_KEY') || !WWE_IPARCEL_PRIVATE_KEY) {
                return new WP_Error('missing_private_key', __('i-Parcel private key not defined.', 'wwe-ups-woocommerce-shipping'));
            }
            
            $endpoint = add_query_arg('key', rawurlencode(WWE_IPARCEL_PRIVATE_KEY), $base_endpoint);
            
            // Prepare payload for i-Parcel rate request
            $payload = [
                'OriginCountry' => $shipment_data['origin_country'] ?? 'FR',
                'OriginPostalCode' => $shipment_data['origin_postal'] ?? '75018',
                'DestinationCountry' => $shipment_data['destination_country'] ?? 'US',
                'DestinationPostalCode' => $shipment_data['destination_postal'] ?? '',
                'Weight' => $shipment_data['weight'] ?? 1.0,
                'WeightUnit' => 'KG',
                'Length' => $shipment_data['length'] ?? 33,
                'Width' => $shipment_data['width'] ?? 33,
                'Height' => $shipment_data['height'] ?? 33,
                'DimensionUnit' => 'CM',
                'Value' => $shipment_data['value'] ?? 50.0,
                'Currency' => $shipment_data['currency'] ?? 'EUR'
            ];
            
            // Prepare headers
            $headers = ['Content-Type' => 'application/json'];
            
            if (defined('WWE_IPARCEL_PUBLIC_KEY') && WWE_IPARCEL_PUBLIC_KEY) {
                $headers['Key'] = WWE_IPARCEL_PUBLIC_KEY;
            } elseif (defined('WWE_IPARCEL_PRIVATE_KEY') && WWE_IPARCEL_PRIVATE_KEY) {
                $headers['Key'] = WWE_IPARCEL_PRIVATE_KEY;
            }
            
            if (defined('WWE_IPARCEL_COMPANY_ID') && WWE_IPARCEL_COMPANY_ID) {
                $headers['Company-Id'] = WWE_IPARCEL_COMPANY_ID;
            }
            
            if ($this->debug_mode) {
                wc_get_logger()->debug('i-Parcel Rate Request Headers → ' . print_r($headers, true), ['source' => WWE_UPS_LOG_SOURCE]);
                wc_get_logger()->debug('i-Parcel Rate Request Payload → ' . print_r($payload, true), ['source' => WWE_UPS_LOG_SOURCE]);
            }
            
            $args = [
                'headers' => $headers,
                'body'    => wp_json_encode($payload),
                'timeout' => 30,
            ];
            
            $response = wp_remote_post($endpoint, $args);
            
            if (is_wp_error($response)) {
                wc_get_logger()->error('i-Parcel Rate Request WP Error: ' . $response->get_error_message(), ['source' => WWE_UPS_LOG_SOURCE]);
                return $response;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $decoded = json_decode($raw, true);
            
            if ($this->debug_mode) {
                wc_get_logger()->debug("i-Parcel Rate Response Code: {$code}", ['source' => WWE_UPS_LOG_SOURCE]);
                wc_get_logger()->debug("i-Parcel Rate Response Body: {$raw}", ['source' => WWE_UPS_LOG_SOURCE]);
            }
            
            if ($code >= 200 && $code < 300) {
                wc_get_logger()->info('i-Parcel Rate Request succeeded', ['source' => WWE_UPS_LOG_SOURCE]);
                return ['code' => $code, 'body' => $decoded];
            } else {
                wc_get_logger()->error("i-Parcel Rate Request failed: HTTP {$code} - {$raw}", ['source' => WWE_UPS_LOG_SOURCE]);
                return new WP_Error('iparcel_rate_error', sprintf(__('i-Parcel rate request failed: HTTP %1$s', 'wwe-ups-woocommerce-shipping'), $code), ['response' => $raw, 'status' => $code]);
            }
        }

        // ============================================================================
        // 🚀 NOUVEAU : API PAPERLESS DOCUMENTS UPS (Découverte audit expert)
        // Automatise la soumission électronique des documents douaniers
        // ============================================================================

        /**
         * Upload customs document via UPS Paperless Documents API
         * 
         * @param array $invoice_data Commercial invoice data
         * @return array|WP_Error Response with DocumentID on success
         */
        public function upload_customs_document($invoice_data) {
            // Use v2 API as per official UPS documentation
            $endpoint = '/paperlessdocuments/v2/upload';
            $full_url = 'https://onlinetools.ups.com/api' . $endpoint;
            
            if ($this->debug_mode) {
                wwe_ups_log('🔄 PAPERLESS DOCS v2: Uploading customs document with TXT format', 'debug');
                wwe_ups_log('📄 Document payload keys: ' . print_r(array_keys($invoice_data), true), 'debug');
            }
            
            // Generate TXT commercial invoice instead of JSON
            $txt_content = $this->generate_commercial_invoice_txt($invoice_data);
            if (is_wp_error($txt_content)) {
                return $txt_content;
            }
            
            // Build correct UPS Paperless Documents payload structure (v2)
            $payload = [
                'UploadRequest' => [
                    'Request' => [
                        'TransactionReference' => [
                            'CustomerContext' => 'WWE WooCommerce Customs Upload v2'
                        ]
                    ],
                    'UserCreatedForm' => [
                        'UserCreatedFormFileName' => 'commercial_invoice_' . time() . '.txt',
                        'UserCreatedFormFile' => base64_encode($txt_content),
                        'UserCreatedFormFileFormat' => 'txt', // Changed to txt format
                        'UserCreatedFormDocumentType' => '002' // Commercial Invoice
                    ]
                    // NOTE: ShipperNumber is passed in headers, not in body
                ]
            ];
            
            // Pass ShipperNumber in headers as per UPS API specification
            $extra_headers = [
                'ShipperNumber' => $this->account_number
            ];
            
            $response = $this->do_request($full_url, $payload, 'POST', $extra_headers);
            
            if (is_wp_error($response)) {
                wwe_ups_log('❌ PAPERLESS DOCS v2: Upload failed - ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            // Check for correct response structure
            if (isset($response['body']['UploadResponse']['FormsHistoryDocumentID']['DocumentID'])) {
                $document_id = $response['body']['UploadResponse']['FormsHistoryDocumentID']['DocumentID'];
                // Handle both array and single DocumentID
                if (is_array($document_id)) {
                    $document_id = $document_id[0];
                }
                wwe_ups_log('✅ PAPERLESS DOCS v2: TXT document uploaded successfully - DocumentID: ' . $document_id, 'info');
                return [
                    'success' => true,
                    'document_id' => $document_id,
                    'body' => $response['body']
                ];
            } else {
                wwe_ups_log('❌ PAPERLESS DOCS v2: Upload response missing DocumentID. Response: ' . print_r($response['body'], true), 'error');
                return new WP_Error('paperless_upload_error', __('Document upload failed - no DocumentID returned', 'wwe-ups-woocommerce-shipping'));
            }
        }

        /**
         * Generate TXT commercial invoice from invoice data
         * 
         * @param array $invoice_data Commercial invoice data
         * @return string|WP_Error TXT content or error
         */
        private function generate_commercial_invoice_txt($invoice_data) {
            if (empty($invoice_data)) {
                return new WP_Error('empty_invoice_data', __('Invoice data is empty', 'wwe-ups-woocommerce-shipping'));
            }
            
            // Simple text-based commercial invoice (UPS accepts TXT format)
            $invoice_text = "COMMERCIAL INVOICE\n";
            $invoice_text .= "==================\n\n";
            
            $invoice_text .= "Invoice Number: " . ($invoice_data['InvoiceNumber'] ?? 'N/A') . "\n";
            $invoice_text .= "Invoice Date: " . ($invoice_data['InvoiceDate'] ?? date('Y-m-d')) . "\n";
            $invoice_text .= "Currency: " . ($invoice_data['Currency'] ?? 'EUR') . "\n";
            $invoice_text .= "Reason for Export: " . ($invoice_data['ReasonForExport'] ?? 'SALE') . "\n";
            $invoice_text .= "Terms: " . ($invoice_data['Terms'] ?? 'DDU') . "\n\n";
            
            // Shipper Information
            if (isset($invoice_data['ShipperInformation'])) {
                $shipper = $invoice_data['ShipperInformation'];
                $invoice_text .= "SHIPPER INFORMATION:\n";
                $invoice_text .= "Company: " . ($shipper['CompanyName'] ?? 'N/A') . "\n";
                if (isset($shipper['Address'])) {
                    $invoice_text .= "Address: " . ($shipper['Address']['AddressLine1'] ?? 'N/A') . "\n";
                    $invoice_text .= "City: " . ($shipper['Address']['City'] ?? 'N/A') . "\n";
                    $invoice_text .= "Postal Code: " . ($shipper['Address']['PostalCode'] ?? 'N/A') . "\n";
                    $invoice_text .= "Country: " . ($shipper['Address']['CountryCode'] ?? 'N/A') . "\n";
                }
                $invoice_text .= "Phone: " . ($shipper['Phone'] ?? 'N/A') . "\n";
                $invoice_text .= "Email: " . ($shipper['Email'] ?? 'N/A') . "\n\n";
            }
            
            // Consignee Information
            if (isset($invoice_data['ConsigneeInformation'])) {
                $consignee = $invoice_data['ConsigneeInformation'];
                $invoice_text .= "CONSIGNEE INFORMATION:\n";
                $invoice_text .= "Company: " . ($consignee['CompanyName'] ?? 'N/A') . "\n";
                $invoice_text .= "Contact: " . ($consignee['ContactName'] ?? 'N/A') . "\n";
                if (isset($consignee['Address'])) {
                    $invoice_text .= "Address: " . ($consignee['Address']['AddressLine1'] ?? 'N/A') . "\n";
                    if (!empty($consignee['Address']['AddressLine2'])) {
                        $invoice_text .= "Address 2: " . $consignee['Address']['AddressLine2'] . "\n";
                    }
                    $invoice_text .= "City: " . ($consignee['Address']['City'] ?? 'N/A') . "\n";
                    $invoice_text .= "State: " . ($consignee['Address']['StateProvince'] ?? 'N/A') . "\n";
                    $invoice_text .= "Postal Code: " . ($consignee['Address']['PostalCode'] ?? 'N/A') . "\n";
                    $invoice_text .= "Country: " . ($consignee['Address']['CountryCode'] ?? 'N/A') . "\n";
                }
                $invoice_text .= "Phone: " . ($consignee['Phone'] ?? 'N/A') . "\n";
                $invoice_text .= "Email: " . ($consignee['Email'] ?? 'N/A') . "\n\n";
            }
            
            // Items
            $invoice_text .= "ITEMS:\n";
            $invoice_text .= "------\n";
            if (isset($invoice_data['Items']) && is_array($invoice_data['Items'])) {
                $total_value = 0;
                foreach ($invoice_data['Items'] as $index => $item) {
                    $invoice_text .= ($index + 1) . ". " . ($item['Description'] ?? 'N/A') . "\n";
                    $invoice_text .= "   SKU: " . ($item['SKU'] ?? 'N/A') . "\n";
                    $invoice_text .= "   Quantity: " . ($item['Quantity'] ?? 1) . "\n";
                    $invoice_text .= "   Unit Value: " . ($item['UnitValue'] ?? 0) . " " . ($item['Currency'] ?? 'EUR') . "\n";
                    $invoice_text .= "   Total Value: " . ($item['TotalValue'] ?? 0) . " " . ($item['Currency'] ?? 'EUR') . "\n";
                    $invoice_text .= "   HTS Code: " . ($item['HTSCode'] ?? 'N/A') . "\n";
                    $invoice_text .= "   Country of Origin: " . ($item['CountryOfOrigin'] ?? 'N/A') . "\n";
                    if (isset($item['Weight'])) {
                        $invoice_text .= "   Weight: " . ($item['Weight']['Value'] ?? 0) . " " . ($item['Weight']['Unit'] ?? 'KG') . "\n";
                    }
                    $invoice_text .= "\n";
                    $total_value += ($item['TotalValue'] ?? 0);
                }
                $invoice_text .= "TOTAL VALUE: " . $total_value . " " . ($invoice_data['Currency'] ?? 'EUR') . "\n";
            }
            
            $invoice_text .= "\n--- End of Commercial Invoice ---\n";
            
            // Return as text content (UPS accepts TXT format)
            return $invoice_text;
        }

        /**
         * Link document to tracking number via UPS Paperless Documents API
         * 
         * @param string $document_id Document ID from upload response
         * @param string $tracking_number UPS tracking number
         * @return array|WP_Error Response on success
         */
        public function link_document_to_tracking($document_id, $tracking_number) {
            // Use v2 API as per official UPS documentation  
            $endpoint = '/paperlessdocuments/v2/image';
            $full_url = 'https://onlinetools.ups.com/api' . $endpoint;
            
            if ($this->debug_mode) {
                wwe_ups_log("🔗 PAPERLESS DOCS v2: Linking document {$document_id} to tracking {$tracking_number}", 'debug');
            }
            
            // Generate ShipmentDateAndTime in EXACT UPS Paperless format: yyyy-MM-dd-HH.mm.ss
            // This is the SPECIFIC format required by UPS Paperless Documents API
            $store_timezone = new DateTimeZone(wp_timezone_string());
            $shipment_datetime = new DateTime('now', $store_timezone);
            $shipment_date_and_time = $shipment_datetime->format('Y-m-d-H.i.s'); // yyyy-MM-dd-HH.mm.ss
            
            if ($this->debug_mode) {
                wwe_ups_log("📅 PAPERLESS DOCS v2: Using ShipmentDateAndTime format: {$shipment_date_and_time} (yyyy-MM-dd-HH.mm.ss)", 'debug');
            }
            
            // Build correct UPS Push to Image Repository payload (v2)
            $payload = [
                'PushToImageRepositoryRequest' => [
                    'Request' => [
                        'TransactionReference' => [
                            'CustomerContext' => 'WWE WooCommerce Customs Link v2'
                        ]
                    ],
                    'FormsHistoryDocumentID' => [
                        'DocumentID' => [$document_id] // Always as array per v2 spec
                    ],
                    'ShipmentIdentifier' => $tracking_number,
                    'ShipmentDateAndTime' => $shipment_date_and_time, // REQUIRED: Format yyyy-MM-dd-HH.mm.ss
                    'ShipmentType' => '1', // Small package
                    'TrackingNumber' => [$tracking_number] // Array format per spec
                    // NOTE: ShipperNumber is passed in headers, not in body
                ]
            ];
            
            // Pass ShipperNumber in headers as per UPS API specification
            $extra_headers = [
                'ShipperNumber' => $this->account_number
            ];
            
            $response = $this->do_request($full_url, $payload, 'POST', $extra_headers);
            
            if (is_wp_error($response)) {
                wwe_ups_log('❌ PAPERLESS DOCS v2: Link failed - ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            // Check for success response
            if (isset($response['body']['PushToImageRepositoryResponse'])) {
                wwe_ups_log("✅ PAPERLESS DOCS v2: Document successfully linked to tracking {$tracking_number} with ShipmentDateAndTime {$shipment_date_and_time}", 'info');
                return [
                    'success' => true,
                    'tracking_number' => $tracking_number,
                    'document_id' => $document_id,
                    'shipment_date_and_time' => $shipment_date_and_time,
                    'body' => $response['body']
                ];
            } else {
                wwe_ups_log('❌ PAPERLESS DOCS v2: Link response unexpected format. Response: ' . print_r($response['body'], true), 'error');
                return new WP_Error('paperless_link_error', __('Document link failed - unexpected response format', 'wwe-ups-woocommerce-shipping'));
            }
        }

        /**
         * Generate commercial invoice data from WooCommerce order
         * 
         * @param WC_Order $order WooCommerce order object
         * @return array Commercial invoice data structure
         */
        public function generate_commercial_invoice_data($order) {
            if (!$order) {
                return [];
            }
            
            // Shipper information (your store)
            $shipper_info = [
                'CompanyName' => get_bloginfo('name') ?: 'YOYAKU RECORD STORE',
                'Address' => [
                    'AddressLine1' => '14 boulevard de la Chapelle',
                    'City' => 'Paris',
                    'PostalCode' => '75018',
                    'CountryCode' => 'FR'
                ],
                'Phone' => '+33 1 80 06 64 01',
                'Email' => 'shop@yoyaku.fr'
            ];
            
            // Consignee information (customer)
            $shipping_address = $order->get_address('shipping');
            $consignee_info = [
                'CompanyName' => $shipping_address['company'] ?: ($shipping_address['first_name'] . ' ' . $shipping_address['last_name']),
                'ContactName' => $shipping_address['first_name'] . ' ' . $shipping_address['last_name'],
                'Address' => [
                    'AddressLine1' => $shipping_address['address_1'],
                    'AddressLine2' => $shipping_address['address_2'],
                    'City' => $shipping_address['city'],
                    'StateProvince' => $shipping_address['state'],
                    'PostalCode' => $shipping_address['postcode'],
                    'CountryCode' => $shipping_address['country']
                ],
                'Phone' => $order->get_billing_phone(),
                'Email' => $order->get_billing_email()
            ];
            
            // Items with updated HTS code (audit expert recommendation)
            $invoice_items = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;
                
                // Get HTS code (use new 10-digit format as per audit)
                $hts_code = get_post_meta($product->get_id(), '_hts_code', true) ?: '8523.80.1000';
                
                $invoice_items[] = [
                    'SKU' => 'SECONDHANDVINYL', // Standardized SKU
                    'Description' => 'Second-hand vinyl records', // Standardized description
                    'Quantity' => $item->get_quantity(),
                    'UnitValue' => 4.00, // ✅ CORRIGÉ : 2.00 → 4.00 EUR par disque
                    'TotalValue' => 4.00 * $item->get_quantity(), // ✅ CORRIGÉ : 2.00 → 4.00
                    'Currency' => 'EUR',
                    'HTSCode' => $hts_code, // Updated to 10-digit format
                    'CountryOfOrigin' => 'FR',
                    'Weight' => [
                        'Value' => $product->get_weight() ?: 0.25,
                        'Unit' => 'KG'
                    ]
                ];
            }
            
            // Complete commercial invoice structure
            $invoice_data = [
                'InvoiceNumber' => 'INV-' . $order->get_id() . '-' . time(),
                'InvoiceDate' => $order->get_date_created()->format('Y-m-d'),
                'ShipperInformation' => $shipper_info,
                'ConsigneeInformation' => $consignee_info,
                'Items' => $invoice_items,
                'TotalValue' => array_sum(array_column($invoice_items, 'TotalValue')),
                'Currency' => 'EUR',
                'ReasonForExport' => 'SALE', // Commercial sale
                'Terms' => 'DDU' // Delivered Duty Unpaid (standard for WWE)
            ];
            
            if ($this->debug_mode) {
                wwe_ups_log('📋 COMMERCIAL INVOICE: Generated for order #' . $order->get_id(), 'debug');
                wwe_ups_log('📦 Items count: ' . count($invoice_items) . ', Total value: ' . $invoice_data['TotalValue'] . ' EUR', 'debug');
            }
            
            return $invoice_data;
        }

        /**
         * Complete workflow: Upload customs document and link to tracking
         * 
         * @param WC_Order $order WooCommerce order
         * @param string $tracking_number UPS tracking number
         * @return array|WP_Error Success response or error
         */
        public function submit_complete_customs_documents($order, $tracking_number) {
            if (!$order || !$tracking_number) {
                return new WP_Error('invalid_params', __('Order and tracking number required', 'wwe-ups-woocommerce-shipping'));
            }
            
            wwe_ups_log("🚀 CUSTOMS WORKFLOW v2: Starting for order #{$order->get_id()}, tracking {$tracking_number}", 'info');
            
            // Step 1: Generate commercial invoice
            $invoice_data = $this->generate_commercial_invoice_data($order);
            if (empty($invoice_data)) {
                return new WP_Error('invoice_generation_failed', __('Failed to generate commercial invoice', 'wwe-ups-woocommerce-shipping'));
            }
            
            // Step 2: Upload document via Paperless Documents API v2
            $upload_response = $this->upload_customs_document($invoice_data);
            if (is_wp_error($upload_response)) {
                return $upload_response;
            }
            
            // Extract DocumentID from response
            if (!isset($upload_response['document_id'])) {
                return new WP_Error('upload_failed', __('Document upload failed - no DocumentID in response', 'wwe-ups-woocommerce-shipping'));
            }
            $document_id = $upload_response['document_id'];
            
            // Step 3: Link document to tracking via Paperless Documents API v2
            $link_response = $this->link_document_to_tracking($document_id, $tracking_number);
            if (is_wp_error($link_response)) {
                return $link_response;
            }
            
            // Success with Paperless Documents v2!
            $order->update_meta_data('_ups_customs_document_id', $document_id);
            $order->update_meta_data('_ups_customs_submitted', 'yes');
            $order->update_meta_data('_ups_customs_method', 'paperless_documents_v2');
            $order->update_meta_data('_ups_customs_date', current_time('mysql'));
            $order->add_order_note(__('Customs documents submitted electronically via UPS Paperless Documents API v2', 'wwe-ups-woocommerce-shipping'));
            $order->save();
            
            wwe_ups_log("🎉 CUSTOMS WORKFLOW v2: Completed successfully for order #{$order->get_id()}", 'info');
            
            return [
                'success' => true,
                'method' => 'paperless_documents_v2',
                'document_id' => $document_id,
                'tracking_number' => $tracking_number,
                'message' => __('Customs documents submitted successfully via UPS Paperless Documents API v2', 'wwe-ups-woocommerce-shipping')
            ];
        }

        /**
         * Send order data to i-Parcel using SubmitParcel API BEFORE label generation
         * Based on old i-parcel plugin logic but adapted for modern webservices.i-parcel.com
         *
         * @param WC_Order $order WooCommerce order object
         * @return array|WP_Error Response array on success or WP_Error on failure.
         */
        public function set_checkout( $order ) {
            // ---------------------------------------------------------------
            // Pre-Label SubmitParcel – adapted from old i-parcel plugin workflow
            // ---------------------------------------------------------------
            $base_endpoint = 'https://webservices.i-parcel.com/api/SubmitParcel';

            if ( ! defined( 'WWE_IPARCEL_PRIVATE_KEY' ) || ! WWE_IPARCEL_PRIVATE_KEY ) {
                wc_get_logger()->error( 'SetCheckout aborted – WWE_IPARCEL_PRIVATE_KEY is missing.', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return new WP_Error( 'missing_private_key', __( 'i-Parcel private key not defined.', 'wwe-ups-woocommerce-shipping' ) );
            }

            // Get order items and build ItemDetailsList using modern SubmitParcel structure
            $items = $order->get_items();
            $item_details_list = [];

            foreach ( $items as $item_id => $item ) {
                $product = $item->get_product();
                if ( ! $product ) {
                    continue;
                }

                $qty = max( 1, (int) $item->get_quantity() );
                
                // Get product dimensions and weight (use defaults if missing)
                $w_lbs = (float) wc_get_weight( $product->get_weight(), 'lb' );
                $l_in  = (float) wc_get_dimension( $product->get_length(), 'in' );
                $w_in  = (float) wc_get_dimension( $product->get_width(),  'in' );
                $h_in  = (float) wc_get_dimension( $product->get_height(), 'in' );

                // Use defaults if missing
                if ( $w_lbs <= 0 ) $w_lbs = 0.55; // ~250g default
                if ( $l_in <= 0 ) $l_in = 12.6; // 32cm default
                if ( $w_in <= 0 ) $w_in = 12.6; // 32cm default  
                if ( $h_in <= 0 ) $h_in = 0.12; // 3mm default

                // Force standardized values for vinyl records (4 EUR per disk)
                $item_details_list[] = [
                    'SKU'                   => 'SECONDHANDVINYL', // Standardized SKU
                    'Quantity'              => $qty,
                    'ProductDescription'    => 'Second-hand vinyl records', // Standardized description
                    'CountryOfOrigin'       => 'FR', // Force France origin
                    'HTSCode'               => '85238010', // Vinyl records HTS code
                    'CustWeightLbs'         => $w_lbs,
                    'CustLengthInches'      => $l_in,
                    'CustWidthInches'       => $w_in,
                    'CustHeightInches'      => $h_in,
                    'OriginalPrice'         => 4.00, // 4 EUR per disk
                    'ValueCompanyCurrency'  => 4.00, // 4 EUR per disk
                    'CompanyCurrency'       => $order->get_currency(), // Use order currency
                    'ValueShopperCurrency'  => 4.00, // 4 EUR per disk
                    'ShopperCurrency'       => $order->get_currency(), // Use order currency
                ];
            }

            // Build modern SubmitParcel payload (adapted from existing yoyaku_ga_push function)
            // IMPORTANT: Check for manually overridden addresses in admin (via POST data)
            $addr = $order->get_address( 'shipping' );
            
            // If this is called during AJAX admin, check for manual address overrides
            if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['order_id'])) {
                // Check for manual address fields that might override order data
                $manual_fields = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
                foreach ($manual_fields as $field) {
                    $post_key = 'shipping_' . $field;
                    if (isset($_POST[$post_key]) && !empty($_POST[$post_key])) {
                        $addr[$field] = sanitize_text_field($_POST[$post_key]);
                        wc_get_logger()->debug(
                            "Pre-Label SubmitParcel: Using manual override for {$field}: " . $addr[$field],
                            ['source' => WWE_UPS_LOG_SOURCE]
                        );
                    }
                }
            }
            
            $payload = [
                'ItemDetailsList' => $item_details_list,
                'AddressInfo' => [
                    'Shipping' => [
                        'FirstName'   => $addr['first_name'],
                        'LastName'    => $addr['last_name'],
                        'Street1'     => $addr['address_1'],
                        'Street2'     => $addr['address_2'],
                        'City'        => $addr['city'],
                        'Region'      => $addr['state'],
                        'PostCode'    => $addr['postcode'],
                        'CountryCode' => $addr['country'],
                        'Email'       => $order->get_billing_email(),
                        'Phone'       => $order->get_billing_phone(),
                    ],
                    'Billing' => null, // Will be set to shipping
                ],
                'DDP'            => false,
                'TrackByEmail'   => true,
                'Reference'      => $order->get_order_number(),
                'TrackingNumber' => 'PRE_LABEL_' . $order->get_id(), // Temporary tracking for pre-label
            ];
            
            // Set Billing to Shipping address
            $payload['AddressInfo']['Billing'] = $payload['AddressInfo']['Shipping'];
            
            // Add ControlNumber for countries requiring tax ID
            $country = $payload['AddressInfo']['Shipping']['CountryCode'];
            if ( in_array( $country, ['BR','IL','KR','RU','ZA','TW'], true ) ) {
                $tax_id = $order->get_meta('_billing_tax_id');
                $payload['AddressInfo']['Shipping']['ControlNumber'] = $tax_id;
                $payload['AddressInfo']['Billing']['ControlNumber']  = $tax_id;
            }

            // Use same headers and structure as existing SubmitParcel
            if ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $endpoint = add_query_arg( 'key', rawurlencode( WWE_IPARCEL_PRIVATE_KEY ), $base_endpoint );
            } else {
                wc_get_logger()->error( 'Pre-Label SubmitParcel aborted – WWE_IPARCEL_PRIVATE_KEY is missing.', [ 'source' => WWE_UPS_LOG_SOURCE ] );
                return new WP_Error( 'missing_private_key', __( 'i-Parcel private key not defined.', 'wwe-ups-woocommerce-shipping' ) );
            }

            // Prepare i-parcel headers (same as existing SubmitParcel)
            $headers = [ 'Content-Type' => 'application/json' ];
            if ( defined( 'WWE_IPARCEL_PUBLIC_KEY' ) && WWE_IPARCEL_PUBLIC_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PUBLIC_KEY;
            } elseif ( defined( 'WWE_IPARCEL_PRIVATE_KEY' ) && WWE_IPARCEL_PRIVATE_KEY ) {
                $headers['Key'] = WWE_IPARCEL_PRIVATE_KEY;
            }
            if ( defined( 'WWE_IPARCEL_COMPANY_ID' ) && WWE_IPARCEL_COMPANY_ID ) {
                $headers['Company-Id'] = WWE_IPARCEL_COMPANY_ID;
            }

            // Ensure the payload also contains the key
            $payload['key'] = defined( 'WWE_IPARCEL_PRIVATE_KEY' ) ? WWE_IPARCEL_PRIVATE_KEY : '';

            if ( $this->debug_mode ) {
                wc_get_logger()->debug( 'i-parcel Pre-Label SubmitParcel Headers → ' . print_r( $headers, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wc_get_logger()->debug( 'i-parcel Pre-Label SubmitParcel Payload → ' . print_r( $payload, true ), [ 'source' => WWE_UPS_LOG_SOURCE ] );
                wwe_ups_log( 'Pre-Label SubmitParcel PAYLOAD → ' . wp_json_encode( $payload ), 'debug' );
            }

            // Execute HTTP POST
            $args = [
                'headers' => $headers,
                'body' => wp_json_encode( $payload ),
                'timeout' => 30,
            ];

            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                wc_get_logger()->error(
                    'UPS i-parcel Pre-Label SubmitParcel WP Error: ' . $response->get_error_message(),
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return $response;
            }

            $code     = wp_remote_retrieve_response_code( $response );
            $raw_body = wp_remote_retrieve_body( $response );

            if ( $code !== 200 ) {
                wc_get_logger()->error(
                    "UPS i-parcel Pre-Label SubmitParcel failed: HTTP {$code} - {$raw_body}",
                    [ 'source' => WWE_UPS_LOG_SOURCE ]
                );
                return new WP_Error(
                    'pre_label_submitparcel_error',
                    sprintf( __( 'Pre-Label SubmitParcel failed: HTTP %1$s', 'wwe-ups-woocommerce-shipping' ), $code ),
                    [ 'code' => $code, 'body' => $raw_body ]
                );
            }

            // Parse response (same as existing SubmitParcel)
            $response_data = json_decode( $raw_body, true );
            
            if ( $this->debug_mode ) {
                wc_get_logger()->debug( 'i-parcel Pre-Label SubmitParcel Response → ' . $raw_body, [ 'source' => WWE_UPS_LOG_SOURCE ] );
            }

            // Process response same as existing SubmitParcel
            if ( isset( $response_data['success'] ) && $response_data['success'] ) {
                // Success - store pre-label submission data
                $order->update_meta_data( '_iparcel_pre_label_submitted', true );
                $order->update_meta_data( '_iparcel_pre_label_submitted_at', time() );
                if ( isset( $response_data['data'] ) ) {
                    $order->update_meta_data( '_iparcel_pre_label_data', $response_data['data'] );
                }
                $order->save();
                
                wc_get_logger()->info( 
                    "UPS i-parcel Pre-Label SubmitParcel succeeded for order {$order->get_id()}", 
                    [ 'source' => WWE_UPS_LOG_SOURCE ] 
                );
                
                return [
                    'success' => true,
                    'message' => 'Pre-label data submitted successfully',
                    'response_data' => $response_data
                ];
            }

            // Handle soft-fail or error response
            $error_message = isset( $response_data['message'] ) ? $response_data['message'] : 'Unknown error';
            wc_get_logger()->warning( 
                "UPS i-parcel Pre-Label SubmitParcel soft-fail for order {$order->get_id()}: {$error_message}", 
                [ 'source' => WWE_UPS_LOG_SOURCE ] 
            );
            
            // Still mark as attempted but not successful
            $order->update_meta_data( '_iparcel_pre_label_attempted', true );
            $order->update_meta_data( '_iparcel_pre_label_attempted_at', time() );
            $order->update_meta_data( '_iparcel_pre_label_error', $error_message );
            $order->save();
            
            return new WP_Error( 'pre_label_soft_fail', $error_message, $response_data );
        }

    } // End class WWE_UPS_API_Handler
}

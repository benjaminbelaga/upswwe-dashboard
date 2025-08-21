<?php
/**
 * Plugin Name:          WooCommerce UPS Worldwide Economy Shipping
 * Plugin URI:           https://github.com/yoyaku/woocommerce-ups-wwe
 * Description:          Intègre les tarifs et la génération d'étiquettes UPS Worldwide Economy (WWE) DDU dans WooCommerce, conçu pour coexister avec d'autres plugins UPS.
 * Version:              1.0.0
 * Author:               Benjamin Belaga
 * Author URI:           https://yoyaku.io
 * Text Domain:          wwe-ups-woocommerce-shipping
 * Domain Path:          /languages
 * WC requires at least: 3.0
 * WC tested up to:      8.0.0
 * WooCommerce tested up to: 8.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Woo: 12345:a1b2c3d4e5f6g7h8i9j0
 * @package WWE_UPS
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Activez le debug i-parcel dès le début du plugin
define('WWE_DEBUG_ON', true);

add_action('init', function(){
    // Avant chaque appel i-parcel → log payload
    add_filter('wwe_ups_api_request_payload', function($payload, $order){
        error_log('i-parcel REQUEST → ' . json_encode($payload));
        return $payload;
    }, 10, 2);

    // Après chaque appel i-parcel → log response
    add_filter('wwe_ups_api_response_body', function($body, $order){
        error_log('i-parcel RESPONSE → ' . json_encode($body));
        return $body;
    }, 10, 2);
});

// ────────────────────────────────────────────────────────────────────
// Yoyaku WWE: Create custom fields on activation if missing
// ────────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'yoyaku_wwe_create_custom_fields' );
/**
 * On plugin activation, ensure every product has the required metadata.
 */
function yoyaku_wwe_create_custom_fields() {
    // Load WordPress environment
    if ( ! function_exists( 'get_posts' ) ) {
        require_once ABSPATH . 'wp-load.php';
    }

    $product_ids = get_posts( [
        'post_type'   => 'product',
        'numberposts' => -1,
        'fields'      => 'ids',
    ] );

    $updated_count = 0;
    foreach ( $product_ids as $id ) {
        $needs_update = false;
        
        // Invoice description
        if ( ! metadata_exists( 'post', $id, 'ph_ups_invoice_desc' ) ) {
            $product = wc_get_product($id);
            $description = $product ? $product->get_name() : 'Vinyl record 12 inch';
            add_post_meta( $id, 'ph_ups_invoice_desc', substr($description ?: 'Vinyl record 12 inch', 0, 35) );
            $needs_update = true;
        }
        
        // HS Code
        if ( ! metadata_exists( 'post', $id, 'hscode_custom_field' ) ) {
            add_post_meta( $id, 'hscode_custom_field', defined( 'YY_UPS_HS_FALLBACK' ) ? YY_UPS_HS_FALLBACK : '85232910' );
            $needs_update = true;
        }
        
        // Origin Country
        if ( ! metadata_exists( 'post', $id, '_origin_country' ) ) {
            add_post_meta( $id, '_origin_country', defined( 'YY_UPS_ORIGIN_FALLBACK' ) ? YY_UPS_ORIGIN_FALLBACK : 'FR' );
            $needs_update = true;
        }
        
        // Ensure product has weight, dimensions, and price
        $product = wc_get_product($id);
        if ($product) {
            $product_updated = false;
            
            if (empty($product->get_weight()) || $product->get_weight() <= 0) {
                $product->set_weight(0.25); // 0.25 kg = ~0.55 lbs
                $product_updated = true;
            }
            
            if (empty($product->get_length()) || $product->get_length() <= 0) {
                $product->set_length(31.5); // 31.5 cm = ~12.375 inches
                $product_updated = true;
            }
            
            if (empty($product->get_width()) || $product->get_width() <= 0) {
                $product->set_width(31.5);
                $product_updated = true;
            }
            
            if (empty($product->get_height()) || $product->get_height() <= 0) {
                $product->set_height(0.4); // 0.4 cm = ~0.15 inches
                $product_updated = true;
            }
            
            if (empty($product->get_price()) || $product->get_price() <= 0) {
                $product->set_regular_price(7.00);
                $product_updated = true;
            }
            
            if (empty($product->get_sku())) {
                $product->set_sku('SKU-' . $id);
                $product_updated = true;
            }
            
            if ($product_updated) {
                $product->save();
                $needs_update = true;
            }
        }
        
        if ($needs_update) {
            $updated_count++;
        }
    }
    
    // Log the bulk update
    if (function_exists('wwe_ups_log')) {
        wwe_ups_log("WWE Plugin Activation: Updated metadata for {$updated_count} products to prevent i-parcel data issues.", 'info');
    }
}

// Headers WordPress ont été déplacés au début du fichier (lignes 1-20)

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Définir les Constantes du Plugin
define('WWE_UPS_VERSION', '1.0.0');
define('WWE_UPS_ID', 'wwe_ups_shipping'); // ID unique pour la méthode
define('WWE_UPS_PATH', plugin_dir_path(__FILE__));
define('WWE_UPS_URL', plugin_dir_url(__FILE__));
define('WWE_UPS_LOG_SOURCE', 'wwe-ups'); // Source unique pour les logs

// --- Manual override constants -------------------------------------------------
if ( ! defined( 'WWE_UPS_FORCE_MANUAL' ) ) {
    // Mode manuel activé par défaut pour afficher systématiquement les boutons admin UPS WWE
    define( 'WWE_UPS_FORCE_MANUAL', true );
}
if ( ! defined( 'WWE_UPS_DEFAULT_INSTANCE_ID' ) ) {
    // ID de l'instance UPS WWE à utiliser quand on force le mode manuel (à remplacer si besoin)
    define( 'WWE_UPS_DEFAULT_INSTANCE_ID', 0 );
}
// -------------------------------------------------------------------------------

// --- Identifiants API UPS & Compte ---
// IMPORTANT : Pour la production, il est préférable de stocker ces informations
// de manière sécurisée (ex: dans les réglages) plutôt qu'en dur ici.
// J'utilise les valeurs de ton snippet pour l'instant.
define('UPS_WW_ECONOMY_CLIENT_ID', 'MRbjYYhevcM3Syl15ZOKKaYXsCHPNYdnGI7c0JAAbRUveca4'); // <-- Vérifie si toujours valide
define('UPS_WW_ECONOMY_CLIENT_SECRET', 'wCOJvXnZYuZRMKq38QozFCEIaZxzV8eFxdSG4GYrUduhMctzccDMB5v5GgKt51BT'); // <-- Vérifie si toujours valide
define('UPS_WW_ECONOMY_ACCOUNT_NUMBER', 'R5J577'); // <-- Ton numéro de compte WWE

// --- Configuration i-parcel keys ---
define('WWE_IPARCEL_PUBLIC_KEY',  'cc1e34c8-d32e-4aba-9b8c-4d63f47e74f9');
define('WWE_IPARCEL_PRIVATE_KEY', '0732bcc8-34b4-4957-a3f7-e4c6dfc4ac2f');
define('WWE_IPARCEL_COMPANY_ID',  '7061');
// --- Fallbacks dimensions/poids : 12" vinyl (standard single, avec pochette) ---
// Unités : dimensions en IN, poids en LBS (conforme aux spécifications UPS US)
if ( ! defined( 'YY_UPS_DESC_FALLBACK' ) ) {
    define( 'YY_UPS_DESC_FALLBACK', 'Vinyl record 12 inch' );
}
if ( ! defined( 'YY_UPS_HS_FALLBACK' ) ) {
    define( 'YY_UPS_HS_FALLBACK', '85232910' );
}
if ( ! defined( 'YY_UPS_ORIGIN_FALLBACK' ) ) {
    define( 'YY_UPS_ORIGIN_FALLBACK', 'FR' );
}
if ( ! defined( 'YY_UPS_SKU_FALLBACK' ) ) {
    define( 'YY_UPS_SKU_FALLBACK', 'VINYL' );
}
if ( ! defined( 'YY_UPS_PRICE_FALLBACK' ) ) {
    define( 'YY_UPS_PRICE_FALLBACK', 7 );
}

if ( ! defined( 'YY_UPS_WEIGHT_FALLBACK' ) ) {
    define( 'YY_UPS_WEIGHT_FALLBACK', 0.55 ); // Poids en livre (approx 0.25 kg)
}
if ( ! defined( 'YY_UPS_LENGTH_FALLBACK' ) ) {
    define( 'YY_UPS_LENGTH_FALLBACK', 12.2 ); // Longueur en pouces (12" = 12.2")
}
if ( ! defined( 'YY_UPS_WIDTH_FALLBACK' ) ) {
    define( 'YY_UPS_WIDTH_FALLBACK', 12.2 ); // Largeur en pouces
}
if ( ! defined( 'YY_UPS_HEIGHT_FALLBACK' ) ) {
    define( 'YY_UPS_HEIGHT_FALLBACK', 0.2 ); // Hauteur en pouces (~0.5 cm)
}

// --- UPS API Endpoints ---
define('UPS_AUTH_ENDPOINT', 'https://onlinetools.ups.com/security/v1/oauth/token');
define('UPS_RATING_ENDPOINT', 'https://onlinetools.ups.com/api/rating/v2409/rate');
define('UPS_SHIPPING_ENDPOINT', 'https://onlinetools.ups.com/api/shipments/v1801/ship');
define('UPS_VOID_ENDPOINT', 'https://onlinetools.ups.com/api/shipments/v1/void/cancel');

// --- Configuration WWE Shipper Details ---
define('WWE_SHIPPER_NAME', 'YOYAKU RECORD STORE');
define('WWE_SHIPPER_ATTENTION_NAME', 'Yoyaku Shipping Dept');
define('WWE_SHIPPER_ADDRESS_LINE_1', '14 boulevard de la Chapelle');
define('WWE_SHIPPER_CITY', 'Paris');
define('WWE_SHIPPER_POSTAL_CODE', '75018');
define('WWE_SHIPPER_PHONE', '+33 1 80 06 64 01');
define('WWE_SHIPPER_EMAIL', 'shop@yoyaku.fr');
define('WWE_SHIPPER_COUNTRY_CODE', 'FR');

// --- Configuration Colis ---
// Measurement units depend on shipper country
$imperial_countries = ['US', 'LR', 'MM'];
$is_imperial = in_array( strtoupper(WWE_SHIPPER_COUNTRY_CODE), $imperial_countries, true );
define( 'WWE_PACKAGE_DIM_UNIT', $is_imperial ? 'IN' : 'CM' );
define( 'WWE_PACKAGE_WEIGHT_UNIT', $is_imperial ? 'LBS' : 'KGS' );
define('WWE_MINIMUM_PACKAGE_WEIGHT', 0.1); // Poids minimum pour éviter les erreurs API
define('WWE_MAX_WEIGHT', 15.0); // Poids max spécifique à WWE (seuil de division)
define('WWE_LABEL_FORMAT', 'GIF'); // Mettre 'ZPL', 'EPL', 'PNG' ou 'GIF'
if ( defined('WWE_DEBUG_ON') && WWE_DEBUG_ON ) {
    error_log('🟢 WWE DEBUG → WWE_LABEL_FORMAT maintenant = ' . WWE_LABEL_FORMAT);

    // Temporary debug logging for discounted product payload/response
    add_filter('wwe_ups_api_request_payload', function($payload, $order) {
        error_log('🔍 WWE DEBUG: API Payload = ' . print_r($payload, true));
        return $payload;
    }, 10, 2);

    add_filter('wwe_ups_api_response_body', function($body, $order) {
        error_log('📦 WWE DEBUG: API Response = ' . print_r($body, true));
        return $body;
    }, 10, 2);
}

// --- Informations Douanières (Mise à jour audit expert) ---
define('WWE_DEFAULT_PRODUCT_ORIGIN_COUNTRY', 'FR'); // Pays d'origine par défaut
define('WWE_DEFAULT_HS_CODE', '8523.80.1000'); // ⭐ Code HTS 10 chiffres (audit expert) - Phonograph Records
define('WWE_CUSTOMS_REASON_FOR_EXPORT', 'SALE'); // Raison de l'export

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Le plugin WooCommerce UPS Worldwide Economy Shipping requiert que WooCommerce soit activé.</strong></p></div>';
        });
        return;
    }

    $plugin_root = plugin_dir_path(__FILE__);

    $includes = [
        'includes/class-wwe-ups-shipping-method.php',
        'includes/class-wwe-ups-api-handler.php',
        'includes/wwe-ups-functions.php',
        'includes/class-wwe-ups-address-validation.php',
        'includes/class-wwe-ups-tracking.php',
        'includes/class-wwe-ups-customs-dashboard.php',
        'includes/wwe-ups-auto-customs.php', // ✅ Auto-customs system
        'includes/wwe-ups-pre-label-setup.php', // ✅ Pre-label i-Parcel setup
        'includes/wwe-ups-health-check.php', // ✅ Health monitoring system
    ];

    foreach ($includes as $file) {
        $full_path = $plugin_root . $file;
        if (file_exists($full_path)) {
            require_once $full_path;
        } else {
            error_log("❌ WWE ERROR → Fichier manquant : $file");
        }
    }

    if ( is_admin() ) {
        $admin_path = $plugin_root . 'includes/class-wwe-ups-admin.php';
        if ( file_exists( $admin_path ) ) {
            require_once $admin_path;
            $GLOBALS['wwe_ups_admin'] = new WWE_UPS_Admin();

            // Register AJAX callbacks unconditionally so bulk actions work even
            // when no screen information is available.
            add_action( 'wp_ajax_wwe_ups_generate_label', [ $GLOBALS['wwe_ups_admin'], 'ajax_generate_label' ] );
            add_action( 'wp_ajax_wwe_ups_void_shipment',  [ $GLOBALS['wwe_ups_admin'], 'ajax_void_shipment' ] );
            add_action( 'wp_ajax_wwe_ups_simulate_rate', [ $GLOBALS['wwe_ups_admin'], 'ajax_simulate_rate' ] );
            add_action( 'wp_ajax_wwe_ups_print_label',   [ $GLOBALS['wwe_ups_admin'], 'ajax_print_label' ] );
            add_action( 'wp_ajax_wwe_ups_download_all_labels', [ $GLOBALS['wwe_ups_admin'], 'ajax_download_all_labels' ] );

            // Push shipping info to dataLayer for GA tracking
            add_action( 'yoyaku_ga_push', 'yo_push_to_ga', 10, 2 );
            function yo_push_to_ga( $order_id, $tracking_number ) {
                if ( defined('WWE_DEBUG_ON') && WWE_DEBUG_ON ) {
                    error_log( sprintf( '🟢 GA PUSH → orderId=%s | tracking=%s', $order_id, $tracking_number ) );
                }
                echo "<script>
                  dataLayer = window.dataLayer || [];
                  dataLayer.push({
                    event: 'order_shipped',
                    orderId: '" . esc_js( $order_id ) . "',
                    tracking: '" . esc_js( $tracking_number ) . "'
                  });
                </script>";
            }
        } else {
            error_log("❌ WWE ERROR → Fichier admin manquant : includes/class-wwe-ups-admin.php");
        }
    }

    new WWE_UPS_Tracking();
    
    // Initialize Pre-Label Setup system
    if (class_exists('WWE_UPS_Pre_Label_Setup')) {
        new WWE_UPS_Pre_Label_Setup();
        if (defined('WWE_DEBUG_ON') && WWE_DEBUG_ON) {
            wwe_ups_log('🟢 WWE DEBUG → Pre-Label Setup system initialized', 'debug');
        }
    } else {
        error_log("❌ WWE ERROR → Classe Pre-Label Setup manquante : includes/wwe-ups-pre-label-setup.php");
    }

    add_filter('woocommerce_shipping_methods', function ($methods) {
        if (class_exists('WWE_UPS_Shipping_Method')) {
            $methods[WWE_UPS_ID] = 'WWE_UPS_Shipping_Method';
        }
        return $methods;
    });

}, 20);

// Charge les traductions du plugin au bon moment
// Load text domain early enough to avoid JIT warnings
add_action('init', function() {
    load_plugin_textdomain(
        'wwe-ups-woocommerce-shipping',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
});

// ────────────────────────────────────────────────────────────────────
// Yoyaku WWE Fallbacks: Description, HS Code & Origin for vinyl only
// ────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'yoyaku_wwe_fallbacks' ) ) {

    add_filter( 'wwe_ups_product_data', 'yoyaku_wwe_fallbacks', 10, 2 );

    /**
     * Inject Yoyaku defaults or Plughive fields into UPS WWE payload.
     *
     * @param array      $product_data Built by the WWE plugin.
     * @param WC_Product $product      The current product object.
     * @return array Modified $product_data with guaranteed values.
     */
    function yoyaku_wwe_fallbacks( $product_data, $product ) {

        // FORCE standardized values for ALL products (ignore real product data)
        
        // 1. Description - ALWAYS force to "Second-hand vinyl records"
        $product_data['Description'] = 'Second-hand vinyl records';

        // 2. Commodity (HS) Code - ALWAYS force to 85238010
        $product_data['CommodityCode'] = '85238010';

        // 3. Country of Origin - ALWAYS force to FR
        $product_data['OriginCountryCode'] = 'FR';

        // 4. SKU - ALWAYS force to SECONDHANDVINYL
        $product_data['SKU'] = 'SECONDHANDVINYL';

        // 5. Unit price - ALWAYS force to 2.00 EUR
        $product_data['OriginalPrice']        = 2.00;
        $product_data['ValueCompanyCurrency'] = 2.00;

        // 6. Keep real weight (but with fallback)
        if ( empty( $product_data['WeightLbs'] ) ) {
            $product_data['WeightLbs'] = YY_UPS_WEIGHT_FALLBACK;
        }

        // 7. Keep real dimensions (but with fallbacks)
        if ( empty( $product_data['LengthInches'] ) ) {
            $product_data['LengthInches'] = YY_UPS_LENGTH_FALLBACK;
        }
        if ( empty( $product_data['WidthInches'] ) ) {
            $product_data['WidthInches'] = YY_UPS_WIDTH_FALLBACK;
        }
        if ( empty( $product_data['HeightInches'] ) ) {
            $product_data['HeightInches'] = YY_UPS_HEIGHT_FALLBACK;
        }

        return $product_data;
    }
}

// ───────────────────────────────────────────────────────
// Ensure product metadata is complete (but don't auto-submit to i-Parcel)
// i-Parcel submissions happen only when orders are processed
// ───────────────────────────────────────────────────────
add_action('save_post_product', function($post_id, $post, $update) {
    // Only on new publishes, skip revisions and non-published statuses
    if ( wp_is_post_revision($post_id) || 'publish' !== get_post_status($post_id) ) {
        return;
    }
    $product = wc_get_product($post_id);
    if ( ! $product ) {
        return;
    }
    
    // Ensure all required meta fields are populated (but don't submit to i-Parcel)
    wwe_ensure_product_meta_complete($product);
    
    wwe_ups_log("Product {$post_id} metadata ensured. i-Parcel submission will happen only during order processing.", 'debug');
}, 10, 3 );

/**
 * Bulk resync all products to i-Parcel to fix missing information emails
 * Call this function manually or via WP-CLI to fix existing products
 */
function wwe_resync_all_products_to_iparcel() {
    wwe_ups_log("Starting bulk resync of all products to i-Parcel", 'info');
    
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish'
    ));
    
    $batch_size = 50; // Process in batches
    $batches = array_chunk($products, $batch_size);
    $total_processed = 0;
    
    foreach ($batches as $batch_index => $batch) {
        $catalog_payload = [];
        
        foreach ($batch as $product) {
            $product_id = $product->get_id();
            
            // Ensure metadata is complete
            wwe_ensure_product_meta_complete($product);
            
            // Force standardized values for ALL products (same as single product submission)
            $catalog_payload[] = [
                'SKU'             => 'SECONDHANDVINYL', // Force same SKU for all
                'ProductName'     => 'Second-hand vinyl records', // Force same name for all
                'HSCodeUS'        => '85238010', // Force same HS code for all
                'CountryOfOrigin' => 'FR', // Force same origin for all
                'CurrentPrice'    => 2.00, // Force same price for all (2 EUR)
                'Weight'          => wc_get_weight($product->get_weight() ?: 0.55, 'lb'), // Keep real weight
                'Length'          => wc_get_dimension($product->get_length() ?: 12.375, 'in'), // Keep real dimensions
                'Width'           => wc_get_dimension($product->get_width() ?: 12.375, 'in'),
                'Height'          => wc_get_dimension($product->get_height() ?: 0.15, 'in'),
            ];
            
            $total_processed++;
        }
        
        // Send batch to i-Parcel
        if (!empty($catalog_payload)) {
            $handler = new WWE_UPS_API_Handler();
            $result = $handler->submit_catalog($catalog_payload);
            
            if (is_wp_error($result)) {
                wwe_ups_log("Batch {$batch_index} failed: " . $result->get_error_message(), 'error');
            } else {
                wwe_ups_log("Batch {$batch_index} success: " . count($catalog_payload) . " products submitted", 'info');
            }
        }
        
        // Small delay between batches
        sleep(1);
    }
    
    wwe_ups_log("Bulk resync completed: {$total_processed} products processed", 'info');
    return $total_processed;
}

// Add admin action to trigger resync
add_action('wp_ajax_wwe_resync_products', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $count = wwe_resync_all_products_to_iparcel();
    wp_send_json_success("Resync completed: {$count} products processed");
});

/**
 * Ensure a product has all required metadata for UPS i-parcel submission.
 * This prevents the daily emails about missing product information.
 */
function wwe_ensure_product_meta_complete($product) {
    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();
    $updated = false;
    
    // Ensure SKU is set
    if (empty($product->get_sku())) {
        $product->set_sku('SKU-' . $product_id);
        $updated = true;
    }
    
    // Ensure invoice description is set
    if (empty(get_post_meta($product_id, 'ph_ups_invoice_desc', true))) {
        $description = $product->get_name() ?: 'Vinyl record 12 inch';
        update_post_meta($product_id, 'ph_ups_invoice_desc', substr($description, 0, 35));
        $updated = true;
    }
    
    // Ensure HS code is set  
    if (empty(get_post_meta($product_id, 'hscode_custom_field', true))) {
        update_post_meta($product_id, 'hscode_custom_field', '85232910'); // Vinyl records
        $updated = true;
    }
    
    // Ensure origin country is set
    if (empty(get_post_meta($product_id, '_origin_country', true))) {
        update_post_meta($product_id, '_origin_country', 'FR');
        $updated = true;
    }
    
    // Ensure weight is set
    if (empty($product->get_weight()) || $product->get_weight() <= 0) {
        $product->set_weight(0.25); // 0.25 kg = ~0.55 lbs
        $updated = true;
    }
    
    // Ensure dimensions are set
    if (empty($product->get_length()) || $product->get_length() <= 0) {
        $product->set_length(31.5); // 31.5 cm = ~12.375 inches
        $updated = true;
    }
    if (empty($product->get_width()) || $product->get_width() <= 0) {
        $product->set_width(31.5);
        $updated = true;
    }
    if (empty($product->get_height()) || $product->get_height() <= 0) {
        $product->set_height(0.4); // 0.4 cm = ~0.15 inches
        $updated = true;
    }
    
    // Ensure price is set
    if (empty($product->get_price()) || $product->get_price() <= 0) {
        $product->set_regular_price(7.00);
        $updated = true;
    }
    
    if ($updated) {
        $product->save();
        wwe_ups_log("Auto-populated missing metadata for product ID {$product_id}: " . $product->get_name(), 'info');
    }
}

/**
 * Trigger submission of order details to UPS i-parcel once the waybill is created.
 */
add_action( 'wwe_after_waybill_created', 'wwe_trigger_iparcel_submission_on_waybill', 10, 2 );
function wwe_trigger_iparcel_submission_on_waybill( $order_id, $waybill_api_response ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wwe_ups_log( "i-Parcel Trigger Error: Could not retrieve order {$order_id}.", 'error' );
        return;
    }

    $shipping_method_settings = get_option( 'woocommerce_' . WWE_UPS_ID . '_0_settings', [] );
    $api_handler              = new WWE_UPS_API_Handler( $shipping_method_settings );

    try {
        $payload = $api_handler->build_iparcel_submit_payload( $order );

        if ( is_wp_error( $payload ) ) {
            throw new Exception( $payload->get_error_message() );
        }

        $payload = apply_filters( 'wwe_iparcel_submit_request_payload', $payload, $order );

        if ( empty( $payload['key'] ) || empty( $payload['ItemDetailsList'] ) ) {
            throw new Exception( __( 'Invalid i-Parcel payload.', 'wwe-ups-woocommerce-shipping' ) );
        }

        $result = $api_handler->submit_parcel( $payload );
        $body   = is_wp_error( $result ) ? $result->get_error_message() : ( $result['body'] ?? null );
        $body   = apply_filters( 'wwe_iparcel_submit_response_body', $body, $order );

        if ( is_wp_error( $result ) ) {
            throw new Exception( 'i-Parcel API Error: ' . $result->get_error_message() );
        } elseif ( isset( $result['code'] ) && $result['code'] >= 200 && $result['code'] < 300 ) {
            wwe_ups_log( "Order #{$order_id}: i-Parcel SubmitParcel successful via hook.", 'info' );
            $order->add_order_note( __( 'Order details successfully submitted to UPS i-Parcel.', 'wwe-ups-woocommerce-shipping' ) );
            if ( isset( $result['body']['TrackingNumber'] ) ) {
                $order->update_meta_data( '_wwe_iparcel_tracking_number', sanitize_text_field( $result['body']['TrackingNumber'] ) );
                $order->add_order_note( sprintf( __( 'i-Parcel Tracking: %s', 'wwe-ups-woocommerce-shipping' ), sanitize_text_field( $result['body']['TrackingNumber'] ) ) );
            }
            if ( isset( $result['body']['ParcelID'] ) ) {
                $order->update_meta_data( '_wwe_iparcel_parcel_id', sanitize_text_field( $result['body']['ParcelID'] ) );
            }
        } else {
            $error_message = isset( $body ) ? wp_json_encode( $body ) : 'Unknown i-Parcel error.';
            throw new Exception( sprintf( 'i-Parcel SubmitParcel failed. %s', $error_message ) );
        }
        $order->save();
    } catch ( Exception $e ) {
        wwe_ups_log( "Order #{$order_id}: Failed to submit to i-Parcel. Error: " . $e->getMessage(), 'error' );
        $order->add_order_note( sprintf( __( 'i-Parcel submission failed: %s', 'wwe-ups-woocommerce-shipping' ), $e->getMessage() ) );
        $order->save();
    }
}

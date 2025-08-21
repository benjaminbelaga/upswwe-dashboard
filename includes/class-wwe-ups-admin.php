<?php
use setasign\Fpdi\Fpdi;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


// ------------------------------------------------------------
// FPDI autoloader – essaye d'abord Composer, puis lib-fpdi/
// ------------------------------------------------------------
$autoload_paths = [
    plugin_dir_path(__FILE__) . '../vendor/autoload.php',   // Composer install
    plugin_dir_path(__FILE__) . '../lib-fpdi/autoload.php', // Copie manuelle
];

$fpdi_loaded = false;
foreach ( $autoload_paths as $path ) {
    if ( file_exists( $path ) ) {
        require_once $path;
        $fpdi_loaded = true;
        break;
    }
}

if ( ! $fpdi_loaded ) {
    add_action( 'admin_notices', function() use ( $autoload_paths ) {
        echo '<div class="error"><p><strong>WWE UPS Error:</strong> FPDI autoload not found.<br>'
           . 'Vérifié sur :<br><code>' . implode( '</code><br><code>', array_map( 'esc_html', $autoload_paths ) ) . '</code></p></div>';
    } );
}

// S'assurer que les fonctions helpers sont chargées
if (!function_exists('wwe_ups_prepare_api_packages_for_request')) {
    $functions_path = WWE_UPS_PATH . 'includes/wwe-ups-functions.php';
    if (file_exists($functions_path)) {
        require_once $functions_path;
    } else {
        // Gérer l'erreur si le fichier de fonctions n'est pas trouvé
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>WWE UPS Error:</strong> Required functions file not found at ' . esc_html(WWE_UPS_PATH . 'includes/wwe-ups-functions.php') . '. Plugin functionality may be limited.</p></div>';
        });
        // Peut causer des erreurs fatales plus loin si les fonctions manquent vraiment.
    }
}

/**
 * WWE_UPS_Admin Class.
 * Handles admin features for UPS WWE Shipping.
 * ----- VERSION MISE A JOUR AVEC CORRECTIONS ET FONCTIONNALITES -----
 */
if (!class_exists('WWE_UPS_Admin')) {
    class WWE_UPS_Admin {

        /**
         * Constructor.
         */
        public function __construct() {
            // Only log initialization once per request to avoid spam
            static $logged_init = false;
            if (!$logged_init && defined('WWE_DEBUG_ON') && WWE_DEBUG_ON) {
                wwe_ups_log('🟢 WWE ADMIN DEBUG → WWE_UPS_Admin initialized', 'debug');
                $logged_init = true;
            }

            // Add metabox to order edit screen
            add_action('add_meta_boxes', [$this, 'add_wwe_metabox'], 10, 2);

            // Enqueue admin assets
            add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);

            // AJAX handlers are registered externally in the main plugin file

            // Add bulk actions for orders list
            add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_wwe_bulk_actions']);
            add_filter('bulk_actions-edit-shop_order', [$this, 'add_wwe_bulk_actions']);
            add_action('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_wwe_bulk_actions_hpo'], 10, 3);
            add_action('handle_bulk_actions-edit-shop_order', [$this, 'handle_wwe_bulk_actions_legacy'], 10, 3);
            add_action('admin_notices', [$this, 'show_legacy_bulk_action_notices']);

            // ============================================================================
            // HPOS COMPATIBLE - DUAL TRACKING COLUMNS SYSTEM
            // Supports both Legacy (shop_order) and HPOS (wc-orders) systems
            // ============================================================================
            
            // Add tracking column to orders list
            add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_wwe_order_column']);         // HPOS
            add_filter('manage_edit-shop_order_columns', [$this, 'add_wwe_order_column']);                   // Legacy
            add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_wwe_hpos_order_column'], 10, 2); // HPOS
            add_action('manage_shop_order_posts_custom_column', [$this, 'render_wwe_order_column'], 10, 2);  // Legacy

            // Add action buttons to order list
            add_action('woocommerce_admin_order_actions_end', [$this, 'render_wwe_list_action_button']);
        }

        /**
         * Clean output buffer and preserve content for debugging
         * @param string $context Optional context for logging
         * @return void
         */
        private function wwe_clean_output_buffer($context = '') {
            if (ob_get_level()) {
                $buffer_content = ob_get_clean();
                if (!empty(trim($buffer_content))) {
                    // Log any content that was in the buffer
                    wwe_ups_log("Output buffer content in {$context}: " . substr($buffer_content, 0, 500), 'debug');
                    
                    // Check for common PHP notices/warnings that might corrupt JSON
                    if (strpos($buffer_content, 'Notice:') !== false || 
                        strpos($buffer_content, 'Warning:') !== false || 
                        strpos($buffer_content, 'Deprecated:') !== false) {
                        wwe_ups_log("PHP notices/warnings detected in output buffer: " . $buffer_content, 'warning');
                    }
                }
            }
        }
        
        /**
         * Validate and format phone number for UPS API requirements
         * UPS requires minimum 10 alphanumeric characters
         * @param string $phone
         * @return string
         */
        private function wwe_validate_ups_phone($phone) {
            // Remove all non-alphanumeric characters
            $clean_phone = preg_replace('/[^0-9a-zA-Z]/', '', $phone);
            
            // If less than 10 characters, pad with zeros
            if (strlen($clean_phone) < 10) {
                // Try to preserve the original number and just add zeros
                $clean_phone = str_pad($clean_phone, 10, '0', STR_PAD_RIGHT);
                wwe_ups_log("Phone number '{$phone}' padded to '{$clean_phone}' for UPS API requirements", 'debug');
            }
            
            return $clean_phone;
        }

        /**
         * Returns true when manual override is enabled via constant.
         *
         * @return bool
         */
        private function allow_manual_override() {
            // Manual override disabled - use zone-based eligibility only
            return false;
        }

        /**
         * Check if the order's destination is inside a zone that has
         * the UPS Worldwide Economy method enabled.
         *
         * @param WC_Order $order
         * @return bool
         */
        private function order_in_wwe_zone( $order ) {
            // DEBUG LOG
            error_log( sprintf(
                'WWE DEBUG: checking zone for order %d → %s',
                $order ? $order->get_id() : 0,
                json_encode( array(
                    'country'  => $order->get_shipping_country(),
                    'state'    => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'city'     => $order->get_shipping_city(),
                ) )
            ) );
            if ( ! $order ) {
                return false;
            }
            $package = array(
                'destination' => array(
                    'country'  => $order->get_shipping_country(),
                    'state'    => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'city'     => $order->get_shipping_city(),
                ),
            );
            if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
                error_log( 'WWE ERROR: WC_Shipping_Zones class not found' );
                return false;
            }
            $zone = WC_Shipping_Zones::get_zone_matching_package( $package );
            error_log( 'WWE DEBUG: matched zone #' . ( $zone ? $zone->get_id() : 'none' ) );
            if ( ! $zone ) {
                return false;
            }
            
            // More robust method checking with detailed debug
            $methods = $zone->get_shipping_methods();
            error_log( 'WWE DEBUG: found ' . count($methods) . ' methods in zone' );
            
            foreach ( $methods as $method_id => $method ) {
                error_log( sprintf( 
                    'WWE DEBUG: checking method %s → id=%s, enabled=%s, method_id=%s',
                    $method_id,
                    $method->id ?? 'no-id',
                    $method->enabled ?? 'no-enabled',
                    property_exists($method, 'method_id') ? $method->method_id : 'no-method-id'
                ) );
                
                // Check both 'id' and 'method_id' properties and ensure enabled
                if ( ( $method->id === WWE_UPS_ID || (property_exists($method, 'method_id') && $method->method_id === WWE_UPS_ID) ) && 
                     ( $method->enabled === 'yes' || $method->enabled === true ) ) {
                    error_log( 'WWE DEBUG: ✅ Found enabled WWE method in zone!' );
                    return true;
                }
            }
            
            error_log( 'WWE DEBUG: ❌ No WWE method found in zone' );
            return false;
        }

        /** Enqueue admin scripts and styles */
        public function admin_enqueue_assets($hook) {
            // Determine relevant screens using the hook name and query vars only
            // because get_current_screen() is not available during some actions
            // such as bulk operations.
            $is_single_order_page =
                ( $hook === 'post.php' && isset( $_GET['post'] ) && get_post_type( $_GET['post'] ) === 'shop_order' ) ||
                strpos( $hook, 'shop_order' ) !== false;
            $is_order_list_page =
                ( $hook === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' ) ||
                strpos( $hook, 'wc-orders' ) !== false;
            $is_wwe_settings_page = isset( $_GET['page'], $_GET['tab'], $_GET['section'] ) &&
                $_GET['page'] === 'wc-settings' &&
                $_GET['tab'] === 'shipping' &&
                $_GET['section'] === WWE_UPS_ID;

            if ( $is_single_order_page || $is_wwe_settings_page || $is_order_list_page ) {
                wp_enqueue_style('wwe-ups-admin-styles', WWE_UPS_URL . 'assets/css/wwe-ups-admin.css', [], WWE_UPS_VERSION);
                wp_enqueue_script('wwe-ups-admin-scripts', WWE_UPS_URL . 'assets/js/wwe-ups-admin.js', ['jquery', 'wp-util'], time(), true);
                wp_localize_script(
                    'wwe-ups-admin-scripts', 'wwe_ups_admin_params',
                    array(
                        'ajax_url'       => admin_url('admin-ajax.php'),
                        'generate_nonce' => wp_create_nonce('wwe_ups_generate_label'),
                        'void_nonce'     => wp_create_nonce('wwe_ups_void_shipment'),
                        'simulate_nonce' => wp_create_nonce('wwe_ups_simulate_rate'),
                        'reset_nonce'    => wp_create_nonce('wwe_ups_reset_shipment'),
                        'download_all_nonce' => wp_create_nonce('wwe_ups_download_all_labels'),
                        'method_id'      => WWE_UPS_ID,
                    )
                );
            }
        }

        /** Add the WWE Meta Box */
        public function add_wwe_metabox($post_type, $post_or_order_object) {
             $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id('shop-order') : 'shop_order';
             add_meta_box( 'wwe_ups_metabox', __('UPS Worldwide Economy (WWE)', 'wwe-ups-woocommerce-shipping'), array($this, 'render_wwe_metabox'), $screen, 'side', 'high' );
        }

        /** Render Meta Box Content */
        public function render_wwe_metabox($post_or_order_object) {
            $order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
            if (!$order) { echo '<p>' . __('Could not load order data.', 'wwe-ups-woocommerce-shipping') . '</p>'; return; }
            $order_id = $order->get_id();

            error_log( sprintf( 'WWE DEBUG: render_metabox for order %d', $order->get_id() ) );

            $eligible = $this->order_in_wwe_zone( $order );
            error_log( sprintf( 'WWE DEBUG: metabox eligibility = %s', $eligible ? 'yes' : 'no' ) );
            if ( ! $eligible ) {
                // Show clear message and render both buttons disabled with correct styles.
                echo '<p class="wwe-ups-error-message wwe-ups-message-area">'
                   . esc_html__( 'Customer is outside UPS Worldwide Economy zone.', 'wwe-ups-woocommerce-shipping' )
                   . '</p>';

                echo '<div class="wwe-ups-metabox-actions">';
                echo '<button type="button" class="button button-secondary" disabled="disabled" style="opacity:.5;cursor:not-allowed;">'
                   . esc_html__( 'Generate UPS WWE Label(s)', 'wwe-ups-woocommerce-shipping' )
                   . '</button>';
                echo '<button type="button" class="button" disabled="disabled" style="opacity:.5;cursor:not-allowed;">'
                   . esc_html__( 'Simulate UPS WWE Rate', 'wwe-ups-woocommerce-shipping' )
                   . '</button>';
                echo '</div>';

                return;
            }

            $tracking_numbers_str = $order->get_meta('_wwe_ups_tracking_number', true);
            $shipment_id     = $order->get_meta('_wwe_ups_shipment_id', true);
            
            // HPOS-COMPATIBLE: Retrieve labels using WooCommerce order meta methods  
            $label_count = $order->get_meta('_wwe_ups_label_count', true);
            $label_data = null;
            
            if ($label_count > 0) {
                if ($label_count == 1) {
                    // Single label
                    $label_data = $order->get_meta('_wwe_ups_label_0', true);
                } else {
                    // Multiple labels - build array
                    $label_data = [];
                    for ($i = 0; $i < $label_count; $i++) {
                        $single_label = $order->get_meta("_wwe_ups_label_{$i}", true);
                        if (!empty($single_label)) {
                            $label_data[$i] = $single_label;
                        }
                    }
                }
            }

            // Debug the actual values retrieved
            wwe_ups_log("Metabox Debug for Order #{$order_id}: tracking_numbers_str=" . ($tracking_numbers_str ? 'YES' : 'NO') . ", label_count={$label_count}, label_data=" . (!empty($label_data) ? (is_array($label_data) ? "ARRAY[" . count($label_data) . "]" : "STRING") : "EMPTY"));

            // Check if we have labels to show download buttons
            if (!empty($label_data)) {
                wwe_ups_log("Metabox Debug: Label data found for Order #{$order_id} - buttons WILL be shown");
            } else {
                wwe_ups_log("Metabox Debug: Label data is empty for Order #{$order_id} - button NOT shown");
            }



            echo '<div class="wwe-ups-metabox-content">';
            
            // --- Affichage si étiquette générée ---
            if ($tracking_numbers_str) {
                echo '<div class="wwe-ups-shipment-info">';
                echo '<p><strong>' . __('Status:', 'wwe-ups-woocommerce-shipping') . '</strong> ' . __('Shipped (UPS WWE)', 'wwe-ups-woocommerce-shipping') . '</p>';
                if ($shipment_id) { echo '<p><strong>' . __('UPS Shipment ID:', 'wwe-ups-woocommerce-shipping') . '</strong> ' . esc_html($shipment_id) . '</p>'; }
                echo '<p><strong>' . __('Tracking #:', 'wwe-ups-woocommerce-shipping') . '</strong> ';
                $tracking_numbers = explode(',', $tracking_numbers_str); $links = [];
                foreach ($tracking_numbers as $tracking) { $tracking = trim($tracking); if (!empty($tracking)) { $links[] = '<a href="' . esc_url('https://www.ups.com/track?loc=en_US&tracknum=' . urlencode($tracking) . '&requester=WT/trackdetails') . '" target="_blank">' . esc_html($tracking) . '</a>'; } }
                echo implode(', ', $links); echo '</p>'; echo '</div>';
                echo '<div class="wwe-ups-metabox-actions">';
                if (!empty($label_data)) {
                    if (is_array($label_data)) { // Multi-colis
                        echo '<p><strong>' . __('Print Labels:', 'wwe-ups-woocommerce-shipping') . '</strong></p>';
                        foreach ($label_data as $index => $label_base64_single) { if (!empty($label_base64_single)) {
                            $tracking_for_button = isset($tracking_numbers[$index]) ? trim($tracking_numbers[$index]) : __('Package', 'wwe-ups-woocommerce-shipping') . ' ' . ($index + 1);
                            $print_nonce = wp_create_nonce('wwe_ups_print_label_' . $order_id . '_' . $index);
                            $print_url = admin_url('admin-ajax.php?action=wwe_ups_print_label&order_id=' . $order_id . '&label_index=' . $index . '&_wpnonce=' . $print_nonce);
                            echo '<a href="' . esc_url($print_url) . '" target="_blank" class="button wwe-ups-print-label-button">' . sprintf(__('Label %s', 'wwe-ups-woocommerce-shipping'), esc_html($tracking_for_button)) . '</a> '; }
                        }
                    } else { // Mono-colis
                        $print_nonce = wp_create_nonce('wwe_ups_print_label_' . $order_id . '_0');
                        $print_url = admin_url('admin-ajax.php?action=wwe_ups_print_label&order_id=' . $order_id . '&label_index=0&_wpnonce=' . $print_nonce);
                        echo '<a href="' . esc_url($print_url) . '" target="_blank" class="button wwe-ups-print-label-button">' . __('Print UPS WWE Label', 'wwe-ups-woocommerce-shipping') . '</a>';
                    }
                    // Add "Download All Labels" button for both single and multi-package (only once)
                    echo '<br/><br/><button type="button" class="button button-primary wwe-ups-download-all-labels" data-order-id="' . esc_attr($order_id) . '">' . __('Download All Labels (PDF)', 'wwe-ups-woocommerce-shipping') . '</button>';
                    wwe_ups_log("Metabox Debug: Download All Labels button SHOULD be visible for Order #{$order_id}");
                } else { 
                    echo '<p><i>' . __('Label data not found.', 'wwe-ups-woocommerce-shipping') . '</i></p>'; 
                    wwe_ups_log("Metabox Debug: Label data is empty for Order #{$order_id} - button NOT shown");
                }
                // Identifiant pour Void
                $identifier_for_void = !empty($shipment_id) ? $shipment_id : (isset($tracking_numbers[0]) ? trim($tracking_numbers[0]) : '');
                if (!empty($identifier_for_void)) {
                     // *** data-shipment-id est utilisé par le JS maintenant ***
                     echo '<br/><button type="button" class="button wwe-ups-void-button" data-order-id="' . esc_attr($order_id) . '" data-shipment-id="' . esc_attr($identifier_for_void) . '">' . __('Void UPS WWE Shipment', 'wwe-ups-woocommerce-shipping') . '</button>';
                } else { echo '<p><i>' . __('Cannot void: Missing shipment identifier.', 'wwe-ups-woocommerce-shipping') . '</i></p>'; }
                echo '</div>';
            // --- Affichage si PAS encore d'étiquette ---
            } else {
                echo '<p>' . __('UPS WWE shipment not yet created.', 'wwe-ups-woocommerce-shipping') . '</p>';
                echo '<div class="wwe-ups-metabox-actions">';
                echo '<button type="button" class="button button-primary wwe-ups-generate-label-button" data-order-id="' . esc_attr($order_id) . '">' . __('Generate UPS WWE Label(s)', 'wwe-ups-woocommerce-shipping') . '</button>';
                echo '<button type="button" class="button wwe-ups-simulate-rate-button" data-order-id="' . esc_attr($order_id) . '">' . __('Simulate UPS WWE Rate', 'wwe-ups-woocommerce-shipping') . '</button>';
                echo '<div class="wwe-simulation-result" style="margin-top: 5px;"></div>';
                echo '</div>';
            }
            echo '</div>';
        }

        // --- AJAX Handlers ---

        /** Servir l'étiquette via AJAX (PNG ou ZPL/EPL) */
        public function ajax_print_label() {
            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            $label_index = isset($_GET['label_index']) ? absint($_GET['label_index']) : 0;
            $nonce_action = 'wwe_ups_print_label_' . $order_id . '_' . $label_index;

            // ** Vérification Nonce & Permissions **
            check_ajax_referer($nonce_action);
            if (!$order_id || (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_order', $order_id))) {
                wwe_ups_log("Print Label Access Denied: Order ID {$order_id}, Index {$label_index}", 'error');
                wp_die(__('Accès refusé ou ID commande invalide.', 'wwe-ups-woocommerce-shipping'));
            }

            $order = wc_get_order($order_id);
            if (!$order) { wp_die(__('Commande non trouvée.', 'wwe-ups-woocommerce-shipping')); }

            wwe_ups_log("Print Label Request: Order ID {$order_id}, Label Index {$label_index}");

            // HPOS-COMPATIBLE: Retrieve labels using WooCommerce order meta methods
            $label_count = $order->get_meta('_wwe_ups_label_count', true);
            $label_data = null;
            
            if ($label_count > 0) {
                if ($label_count == 1) {
                    // Single label
                    $label_data = $order->get_meta('_wwe_ups_label_0', true);
                } else {
                    // Multiple labels - build array
                    $label_data = [];
                    for ($i = 0; $i < $label_count; $i++) {
                        $single_label = $order->get_meta("_wwe_ups_label_{$i}", true);
                        if (!empty($single_label)) {
                            $label_data[$i] = $single_label;
                        }
                    }
                }
            }
            
            $label_format = $order->get_meta('_wwe_ups_label_format', true) ?: (defined('WWE_LABEL_FORMAT') ? WWE_LABEL_FORMAT : 'PNG');
            $tracking_numbers_str = $order->get_meta('_wwe_ups_tracking_number', true);
            $tracking_numbers = explode(',', $tracking_numbers_str);

            // ** Vérification 0: Données Base64 existent dans la méta **
            if (empty($label_data)) {
                wwe_ups_log("Print Label Error: No label data found for Order {$order_id} (label_count: {$label_count})", 'error');
                wp_die(__('Données d\'étiquette non trouvées. Veuillez rafraîchir la page et réessayer.', 'wwe-ups-woocommerce-shipping'));
            }

            $label_base64 = null; $filename_suffix = '';
            
            // Debug: Log the structure of label_data
            wwe_ups_log("Print Label Debug: label_data type=" . gettype($label_data) . ", is_array=" . (is_array($label_data) ? 'true' : 'false') . ", label_index={$label_index}");
            if (is_array($label_data)) {
                wwe_ups_log("Print Label Debug: Array keys=" . implode(',', array_keys($label_data)));
            }
            
            if (is_array($label_data)) { // Multi-colis
                if (isset($label_data[$label_index])) {
                    $label_base64 = $label_data[$label_index];
                    $tracking_for_file = isset($tracking_numbers[$label_index]) ? '-' . trim($tracking_numbers[$label_index]) : '-Pkg-' . ($label_index + 1);
                    $filename_suffix = $tracking_for_file;
                    wwe_ups_log("Print Label Info: Found label for index {$label_index} in array.");
                } else { 
                    wwe_ups_log("Print Label Error: Index {$label_index} not found in label array. Available indices: " . implode(',', array_keys($label_data)), 'error');
                    wp_die(__('Index d\'étiquette invalide (multi-colis). Indices disponibles: ' . implode(',', array_keys($label_data)), 'wwe-ups-woocommerce-shipping')); 
                }
            } elseif ($label_index === 0) { // Mono-colis
                $label_base64 = $label_data;
                $tracking_for_file = isset($tracking_numbers[0]) ? '-' . trim($tracking_numbers[0]) : '';
                $filename_suffix = $tracking_for_file;
                wwe_ups_log("Print Label Info: Label data is a string (single package).");
            } else { 
                wwe_ups_log("Print Label Error: Requested index {$label_index} but label_data is not an array", 'error');
                wp_die(__('Index d\'étiquette invalide (données non-array).', 'wwe-ups-woocommerce-shipping')); 
            }

            // ** Vérification 1: Base64 non vide après sélection **
            if (empty($label_base64)) {
                wwe_ups_log("Print Label Error: Label base64 string is empty after selection for Order {$order_id}, Index {$label_index}", 'error');
                wp_die(__('Données d\'étiquette vides pour cet index.', 'wwe-ups-woocommerce-shipping'));
            }

            // ** Vérification 2: Validité Base64 (basique pour images) **
            if (strtoupper($label_format) !== 'ZPL' && strtoupper($label_format) !== 'EPL') {
                 if (!preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $label_base64)) { /* ... (erreur format base64 invalide) ... */ wp_die(__('Données étiquette corrompues (format base64 invalide).', 'wwe-ups-woocommerce-shipping')); }
            }
            wwe_ups_log("Print Label Info: Base64 length before decode: " . strlen($label_base64));

            $decoded_data = base64_decode($label_base64, true); // Mode strict

            // ** Vérification 3: Échec décodage ou données trop courtes **
            if (false === $decoded_data || strlen($decoded_data) < 50) {
                wwe_ups_log("Print Label Error: base64_decode failed or returned empty/tiny string for Order {$order_id}, Index {$label_index}. Decoded length: " . strlen($decoded_data), 'error');
                wp_die(__('Erreur décodage ou données étiquette trop petites/corrompues.', 'wwe-ups-woocommerce-shipping'));
            }

            wwe_ups_log("Print Label Info: Decoded data length: " . strlen($decoded_data));
            // Convert GIF to PDF on-the-fly
            if ( strtoupper( $label_format ) === 'GIF' ) {
                try {
                    $img = new \Imagick();
                    $img->readImageBlob( $decoded_data );
                    // Rotate 90° to force portrait orientation
                    $img->rotateImage(new \ImagickPixel('white'), 90);
                    $img->setImageFormat( 'pdf' );
                    $decoded_data = $img->getImagesBlob();
                    $img->clear(); $img->destroy();
                    $label_format    = 'PDF';
                    $file_extension  = 'pdf';
                } catch ( \Exception $e ) {
                    wwe_ups_log( 'Imagick conversion error (ajax_print_label): ' . $e->getMessage(), 'error' );
                }
            }

            $file_extension = strtolower($label_format);
            $filename = 'WWE-Label-' . $order->get_order_number() . $filename_suffix . '.' . $file_extension;

            // --- Gestion ZPL/EPL ---
            if ($label_format === 'ZPL' || $label_format === 'EPL') {
                $content_type = 'application/octet-stream'; // Force téléchargement
                $disposition = 'attachment';
                wwe_ups_log("Print Label: Sending raw {$label_format} data for Order {$order_id}, Index {$label_index}");
            } else {
                if (strtolower($file_extension) === 'pdf') {
                    $content_type = 'application/pdf';
                } else {
                    $content_type = 'image/' . $file_extension;
                }
                $disposition = 'inline'; // Afficher image/PDF
                wwe_ups_log("Print Label: Sending {$label_format} image data for Order {$order_id}, Index {$label_index}");
            }
            // --- Fin Gestion ZPL/EPL ---

            header('Content-Type: ' . $content_type);
            header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($decoded_data));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            if (ob_get_level()) { ob_end_clean(); }
            echo $decoded_data;
            exit;
        }

        /** AJAX handler for generating label */
        public function ajax_generate_label() {
            // Buffer any output (notices, warnings) to avoid corrupting JSON response
            if ( ob_get_level() === 0 ) {
                ob_start();
            }
            if ( defined('WWE_DEBUG_ON') && WWE_DEBUG_ON ) {
                wwe_ups_log('🟢 WWE ADMIN DEBUG → ajax_generate_label called with POST: ' . print_r($_POST, true), 'debug');
            }
            if ( ! check_ajax_referer( 'wwe_ups_generate_label', 'security', false ) ) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'wwe-ups-woocommerce-shipping' ) ], 403 );
            }
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            if (!$order_id) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Invalid Order ID.', 'wwe-ups-woocommerce-shipping')], 400);
            }
            if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_order', $order_id)) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Permission denied.', 'wwe-ups-woocommerce-shipping')], 403);
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Order not found.', 'wwe-ups-woocommerce-shipping')], 404);
            }
            if ($order->get_meta('_wwe_ups_tracking_number', true)) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Label already generated for this order.', 'wwe-ups-woocommerce-shipping')], 400);
            }

            wwe_ups_log("AJAX: Attempting WWE label generation for order {$order_id}.");
            $result = $this->generate_label_for_order($order);

            if (is_wp_error($result)) {
                wwe_ups_log("AJAX: Label generation failed for order {$order_id}. Error: " . $result->get_error_message(), 'error');
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            } elseif (isset($result['tracking_number'])) {
                wwe_ups_log("AJAX: Label generation successful for order {$order_id}. Tracking: " . $result['tracking_number']);
                do_action('yoyaku_ga_push', $order_id, $result['tracking_number']);
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_success(['message' => __('UPS WWE Label generated successfully!', 'wwe-ups-woocommerce-shipping'), 'tracking' => $result['tracking_number']]);
            } else {
                wwe_ups_log("AJAX: Label generation failed for order {$order_id} with unknown error.", 'error');
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Unknown error during label generation.', 'wwe-ups-woocommerce-shipping')], 500);
            }
        }

        /** AJAX handler for voiding shipment */
        public function ajax_void_shipment() {
            // Buffer any output (notices, warnings) to avoid corrupting JSON response
            if ( ob_get_level() === 0 ) {
                ob_start();
            }
            if ( ! check_ajax_referer( 'wwe_ups_void_shipment', 'security', false ) ) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'wwe-ups-woocommerce-shipping' ) ], 403 );
            }
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

            // ** Vérification Permission **
            if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_order', $order_id)) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Permission denied.', 'wwe-ups-woocommerce-shipping')], 403);
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Order not found.', 'wwe-ups-woocommerce-shipping')], 404);
            }

            // ** Logique d'identifiant améliorée **
            $identifier_to_void_str = '';
            if (!empty($_POST['shipment_id'])) { $identifier_to_void_str = sanitize_text_field($_POST['shipment_id']); }
            elseif (!empty($order->get_meta('_wwe_ups_shipment_id', true))) { $identifier_to_void_str = $order->get_meta('_wwe_ups_shipment_id', true); }
            else { $identifier_to_void_str = $order->get_meta('_wwe_ups_tracking_number', true); }

            if (empty($identifier_to_void_str)) {
                wp_send_json_error(['message' => __('Could not find any Shipment ID or Tracking Number to void.', 'wwe-ups-woocommerce-shipping')], 400);
            }
            
            $identifiers = array_filter(array_map('trim', explode(',', $identifier_to_void_str)));
            $success_count = 0;
            $error_messages = [];

            foreach ($identifiers as $identifier) {
                $result = $this->void_wwe_shipment($order, $identifier);
                if (is_wp_error($result)) {
                    // Handle "already voided" as a special case
                    if (strpos($result->get_error_message(), '190117') !== false || strpos($result->get_error_message(), 'already been voided') !== false) {
                        wwe_ups_log("Shipment {$identifier} was already voided - treating as success", 'info');
                        $success_count++;
                    } else {
                        $error_messages[] = "({$identifier}) " . $result->get_error_message();
                    }
                } else {
                    $success_count++;
                }
            }

            // CORRECTION CRITIQUE: Nettoyer dès qu'au moins un void réussit
            // Car même avec des erreurs partielles, l'étiquette UPS est invalidée
            if ($success_count > 0) {
                // Get tracking number before deletion for i-Parcel cleanup
                $tracking_number = $order->get_meta('_wwe_ups_tracking_number', true);
                
                $order->delete_meta_data('_wwe_ups_tracking_number');
                $order->delete_meta_data('_wwe_ups_shipment_id');
                $order->delete_meta_data('_wwe_ups_label_format');
                
                // Delete individual label meta keys using HPOS-compatible methods
                $label_count = $order->get_meta('_wwe_ups_label_count', true);
                if ($label_count > 0) {
                    for ($i = 0; $i < $label_count; $i++) {
                        $order->delete_meta_data("_wwe_ups_label_{$i}");
                    }
                }
                $order->delete_meta_data('_wwe_ups_label_count');
                
                // ═══════════════════════════════════════════════════════════════
                // CLEANUP i-PARCEL DATA AFTER VOID
                // Clean up Pre-Label Setup metadata to prevent orphaned entries
                // ═══════════════════════════════════════════════════════════════
                $this->cleanup_iparcel_after_void($order, $tracking_number);
                
                // Note différente selon succès complet ou partiel
                if (empty($error_messages)) {
                    $order->add_order_note(__('All UPS WWE Shipments successfully voided.', 'wwe-ups-woocommerce-shipping'));
                    $message = sprintf(__('%d UPS WWE Shipment(s) successfully voided!', 'wwe-ups-woocommerce-shipping'), $success_count);
                } else {
                    $order->add_order_note(sprintf(__('UPS WWE Shipments partially voided (%d success, %d errors). Data cleaned for safety.', 'wwe-ups-woocommerce-shipping'), $success_count, count($error_messages)));
                    $error_text = implode('; ', $error_messages);
                    $message = sprintf(__('Partial void success (%d/%d). Errors: %s. Order data cleaned for safety.', 'wwe-ups-woocommerce-shipping'), $success_count, $success_count + count($error_messages), $error_text);
                }
                
                $order->save();
                
                // Clear caches to ensure UI updates immediately
                wc_delete_shop_order_transients($order->get_id());
                wp_cache_delete('order-' . $order->get_id(), 'orders');
                clean_post_cache($order->get_id());
                
                wp_send_json_success(['message' => $message, 'voided' => true]);
            } else {
                $error_text = implode('; ', $error_messages);
                wp_send_json_error(['message' => sprintf(__('Failed to void all shipments. Success: %d, Failures: %d. Errors: %s', 'wwe-ups-woocommerce-shipping'), $success_count, count($error_messages), $error_text)]);
            }
        }
        
        /**
         * Clean up i-Parcel Pre-Label Setup data after voiding a shipment
         * 
         * @param WC_Order $order Order object
         * @param string $tracking_number Voided tracking number
         */
        private function cleanup_iparcel_after_void($order, $tracking_number) {
            // Clean up Pre-Label Setup metadata
            $pre_label_submitted = $order->get_meta('_iparcel_pre_label_submitted', true);
            $pre_label_data = $order->get_meta('_iparcel_pre_label_data', true);
            
            if ($pre_label_submitted || $pre_label_data) {
                // Delete Pre-Label Setup metadata
                $order->delete_meta_data('_iparcel_pre_label_submitted');
                $order->delete_meta_data('_iparcel_pre_label_data');
                $order->delete_meta_data('_iparcel_pre_label_submitted_at');
                $order->delete_meta_data('_iparcel_pre_label_attempted_at');
                $order->delete_meta_data('_iparcel_pre_label_error');
                
                // Mark as voided to prevent future Pre-Label Setup attempts
                $order->update_meta_data('_iparcel_pre_label_voided', true);
                $order->update_meta_data('_iparcel_pre_label_voided_at', time());
                $order->update_meta_data('_iparcel_pre_label_voided_tracking', $tracking_number);
                
                // Add explanatory note
                $order->add_order_note(sprintf(
                    __('i-Parcel Pre-Label data cleaned up after voiding tracking %s. Note: Entry may still appear in UPS Missing Items Update (cannot be removed from i-Parcel side).', 'wwe-ups-woocommerce-shipping'),
                    $tracking_number
                ));
                
                wwe_ups_log("i-Parcel Pre-Label cleanup completed for voided tracking: {$tracking_number}", 'info');
            }
        }

        /** AJAX handler for simulating rate */
        public function ajax_simulate_rate() {
            // Buffer any output (notices, warnings) to avoid corrupting JSON response
            if ( ob_get_level() === 0 ) {
                ob_start();
            }
            if ( defined('WWE_DEBUG_ON') && WWE_DEBUG_ON ) {
                wwe_ups_log('🟢 WWE ADMIN DEBUG → ajax_simulate_rate called with POST: ' . print_r($_POST, true), 'debug');
            }
            if ( ! check_ajax_referer( 'wwe_ups_simulate_rate', 'security', false ) ) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'wwe-ups' ) ], 403 );
            }
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            if (!$order_id) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Invalid Order ID.', 'wwe-ups-woocommerce-shipping')], 400);
            }

            // ** Permission Check CORRIGÉE **
            if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_order', $order_id)) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Permission denied.', 'wwe-ups-woocommerce-shipping')], 403);
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Order not found.', 'wwe-ups-woocommerce-shipping')], 404);
            }

            wwe_ups_log("AJAX: Simulating WWE rate for order {$order_id}.");
            $rate_data = $this->simulate_rate_for_order($order, true); // true = return detailed data
            
            // Handle both old format (just rate) and new format (array with rate and weight)
            if (is_array($rate_data)) {
                $rate = $rate_data['rate'];
                $total_weight = $rate_data['weight'];
            } else {
                $rate = $rate_data;
                $total_weight = null;
            }

            // ** Gestion Erreur CORRIGÉE **
            if (is_wp_error($rate)) {
                wwe_ups_log("AJAX: Rate simulation failed for order {$order_id}. Error: " . $rate->get_error_message(), 'error');
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => 'Simulation failed: ' . $rate->get_error_message()], 400); // Status 400 ou 500 selon le type d'erreur
            } elseif ($rate !== false && is_numeric($rate)) {
                // --- Fin Correction ---
                wwe_ups_log("AJAX: Rate simulation successful for order {$order_id}. Rate: " . $rate);
                
                if ($rate == 0) {
                    $message = __('UPS WWE Rate: Not available via public API (use WorldShip for actual rates)', 'wwe-ups-woocommerce-shipping');
                } else {
                    $formatted_rate = wc_price($rate, ['currency' => $order->get_currency()]);
                    if ($total_weight !== null) {
                        $message = sprintf(__('Simulated UPS WWE Rate: %s (Weight: %.2f kg)', 'wwe-ups-woocommerce-shipping'), $formatted_rate, $total_weight);
                    } else {
                        $message = sprintf(__('Simulated UPS WWE Rate: %s', 'wwe-ups-woocommerce-shipping'), $formatted_rate);
                    }
                }
                
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_success([
                    'message' => $message,
                    'rate'    => $rate
                ]);
            } else {
                wwe_ups_log("AJAX: Rate simulation failed for order {$order_id}. No rate returned or non-numeric.", 'error');
                $this->wwe_clean_output_buffer('ajax_handler');
                wp_send_json_error(['message' => __('Could not simulate rate (unknown reason).', 'wwe-ups-woocommerce-shipping')], 500);
            }
        }

        /** AJAX handler for downloading all labels as PDF */
        public function ajax_download_all_labels() {
            if (!check_ajax_referer('wwe_ups_download_all_labels', 'security', false)) {
                wp_send_json_error(['message' => __('Invalid security token.', 'wwe-ups-woocommerce-shipping')], 403);
            }
            
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            if (!$order_id) {
                wp_send_json_error(['message' => __('Invalid Order ID.', 'wwe-ups-woocommerce-shipping')], 400);
            }
            
            if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_order', $order_id)) {
                wp_send_json_error(['message' => __('Permission denied.', 'wwe-ups-woocommerce-shipping')], 403);
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error(['message' => __('Order not found.', 'wwe-ups-woocommerce-shipping')], 404);
            }
            
            // Use existing bulk PDF generation logic for single order
            $label_data = $this->get_bulk_wwe_label_data([$order_id]);
            
            if (empty($label_data)) {
                wp_send_json_error(['message' => __('No label data found for this order.', 'wwe-ups-woocommerce-shipping')], 404);
            }
            
            // Generate and download PDF
            $this->generate_bulk_wwe_pdf($label_data);
            exit; // generate_bulk_wwe_pdf() calls exit, but just in case
        }

        // --- Core Logic Methods ---

        /** Génère l'étiquette pour une commande */
        private function generate_label_for_order(WC_Order $order) {
            $order_id = $order->get_id();
            wwe_ups_log("Starting WWE label generation for Order #{$order_id}");
            $instance_id = $this->get_instance_id_from_order($order);
            $shipping_method_settings = get_option('woocommerce_' . WWE_UPS_ID . '_' . $instance_id . '_settings', []);
            $api_handler = new WWE_UPS_API_Handler($shipping_method_settings);
            if (!$api_handler || empty($api_handler->account_number)) { return new WP_Error('config_error', __('WWE API Handler not configured or account missing.', 'wwe-ups-woocommerce-shipping')); }

            // 1. Préparer les colis (tableau de packages prêts)
            $api_packages = wwe_ups_prepare_api_packages_for_request($order);
            if (false === $api_packages || empty($api_packages)) {
                return new WP_Error('package_error', __('Error preparing packages for label generation.', 'wwe-ups-woocommerce-shipping'));
            }
            wwe_ups_log("Packages prepared for Order #{$order_id}: " . count($api_packages) . " package(s)");

            // 2. Adresses expéditeur / destinataire
            $shipper_details = $this->get_shipper_details($order);
            $ship_to_details = $this->get_shipto_details($order);
            if (!$shipper_details || !$ship_to_details) { return new WP_Error('address_error', __('Could not retrieve address details.', 'wwe-ups-woocommerce-shipping')); }

            // 3. Données douanières (toujours identiques pour chaque child)
            $products_for_customs = $this->get_international_forms_products($order);
            $contents_cost = (float) $order->get_total();
            if ($contents_cost <= 0) { $contents_cost = 1.00; }

            $international_forms_base = [
                'FormType'        => '01',
                'InvoiceNumber'   => (string) $order_id,
                'InvoiceDate'     => date('Ymd'),
                'ReasonForExport' => defined('WWE_CUSTOMS_REASON_FOR_EXPORT') ? WWE_CUSTOMS_REASON_FOR_EXPORT : 'SALE',
                'TermsOfShipment' => defined('UPS_WW_ECONOMY_INCOTERM') ? UPS_WW_ECONOMY_INCOTERM : 'DAP',
                'CurrencyCode'    => $order->get_currency(),
                'Product'         => $products_for_customs,
                'InvoiceLineTotal'=> [ 'CurrencyCode' => $order->get_currency(), 'MonetaryValue' => (string) round($contents_cost,2) ],
            ];
            if ($ship_to_details['Address']['CountryCode'] === 'MX') {
                $international_forms_base['MerchandiseDescription'] = 'Vinyl Records';
            }

            // 4. Boucle sur chaque colis ⇒ un appel create_shipment()
            $label_images      = [];
            $tracking_numbers  = [];
            $received_format   = 'UNKNOWN';
            $main_shipment_ids = [];
            $label_format_code = defined('WWE_LABEL_FORMAT') ? WWE_LABEL_FORMAT : 'PNG';

            // ═══════════════════════════════════════════════════════════════
            // HOOK: PRE-LABEL SETUP - Execute before any shipment creation
            // This allows i-Parcel data submission before UPS label generation
            // ═══════════════════════════════════════════════════════════════
            do_action('wwe_before_label_generation', $order_id);
            
            foreach ($api_packages as $pkg_idx => $single_pkg) {
                // Format package specifically for Shipment API
                $shipment_pkg = wwe_ups_format_package_for_shipment_api($single_pkg);
                
                $request_body = [
                    'ShipmentRequest' => [
                        'Request' => [ 'RequestOption' => 'nonvalidate', 'TransactionReference' => ['CustomerContext' => "WWE WooCommerce Label Gen - Order {$order_id} - Pkg ".$pkg_idx ] ],
                        'Shipment' => [
                            'Description' => substr('Order ' . $order->get_order_number(), 0, 35),
                            'Shipper'     => $shipper_details,
                            'ShipTo'      => $ship_to_details,
                            'ShipFrom'    => [ 'Name' => $shipper_details['Name'], 'Address' => $shipper_details['Address'] ],
                            'PaymentInformation' => [ 'ShipmentCharge' => [ 'Type' => '01', 'BillShipper' => [ 'AccountNumber' => $api_handler->account_number ] ] ],
                            'Service'     => ['Code' => '17'], // UPS Worldwide Economy - your contracted service
                            'Package'     => [ $shipment_pkg ],
                            'InternationalForms' => $international_forms_base,
                            // Added for negotiated rates context
                            'PickupType' => [ 'Code' => '01' ], // Daily Pickup
                            'CustomerClassification' => [ 'Code' => '00' ], // Rates associated
                        ],
                        'labelSpecification' => [
                            'labelImageFormat' => ['code' => strtolower($label_format_code)],
                            'labelStockSize'   => ['height' => '6', 'width' => '4'],
                        ],
                    ],
                ];

                wwe_ups_log("Shipment Request (pkg {$pkg_idx}) JSON: " . wp_json_encode($request_body));
                $response = $api_handler->create_shipment($request_body);
                if (is_wp_error($response)) { return $response; }

                $shipment_results = $response['body']['ShipmentResponse']['ShipmentResults'] ?? null;
                if (!$shipment_results) { return new WP_Error('api_response_error', 'Invalid API response (no ShipmentResults).'); }

                // Récupérer tracking & label
                $main_id = $shipment_results['ShipmentIdentificationNumber'] ?? null;
                if ($main_id) { $main_shipment_ids[] = $main_id; }
                $package_results = $shipment_results['PackageResults'] ?? [];
                if (isset($package_results['TrackingNumber'])) { $package_results = [ $package_results ]; }

                foreach ($package_results as $pkg_res) {
                    $tracking = $pkg_res['TrackingNumber'] ?? null;
                    $image    = $pkg_res['ShippingLabel']['GraphicImage'] ?? null;
                    $format   = $pkg_res['ShippingLabel']['ImageFormat']['Code'] ?? 'UNKNOWN';
                    if ($tracking && $image) {
                        $tracking_numbers[] = $tracking;
                        $label_images[]     = $image;
                        $received_format    = $format;
                        wwe_ups_log("Label OK (pkg {$pkg_idx}) Order #{$order_id} → Tracking {$tracking} Format {$format} (len=" . strlen($image) . ")");
                    }
                }
            }

            if (empty($tracking_numbers) || empty($label_images)) {
                return new WP_Error('api_parse_error', 'Missing tracking or label image data.');
            }

            // 5. Stocker les méta-données sur la commande
            $tracking_numbers_str = implode(', ', $tracking_numbers);
            $order->update_meta_data('_wwe_ups_tracking_number', $tracking_numbers_str);
            if (!empty($main_shipment_ids)) {
                $order->update_meta_data('_wwe_ups_shipment_id', implode(', ', $main_shipment_ids));
            }
            
            // HPOS-COMPATIBLE FIX: Store labels using WooCommerce order meta methods
            $order_id = $order->get_id();
            
            // Clear any existing label data first
            $order->delete_meta_data('_wwe_ups_label_image_base64');
            
            // Store each label with individual meta keys using HPOS-compatible methods
            $label_count = count($label_images);
            $order->update_meta_data('_wwe_ups_label_count', $label_count);
            
            for ($i = 0; $i < $label_count; $i++) {
                if (!empty($label_images[$i])) {
                    $order->update_meta_data("_wwe_ups_label_{$i}", $label_images[$i]);
                    wwe_ups_log("Stored label {$i} for Order #{$order_id} (length: " . strlen($label_images[$i]) . ")");
                }
            }
            
            $order->update_meta_data('_wwe_ups_label_format', $received_format);

            // Save the order to ensure WC meta is persisted
            $order->save();

            // CRITICAL: Immediate verification using HPOS-compatible methods
            $verification_count = $order->get_meta('_wwe_ups_label_count', true);
            $verification_success = true;
            for ($i = 0; $i < $verification_count; $i++) {
                $label_data = $order->get_meta("_wwe_ups_label_{$i}", true);
                if (empty($label_data)) {
                    $verification_success = false;
                    break;
                }
            }
            
            if ($verification_success && $verification_count > 0) {
                wwe_ups_log("HPOS VERIFICATION SUCCESS - Order #{$order_id}: {$verification_count} labels stored individually");
            } else {
                wwe_ups_log("HPOS VERIFICATION FAILED - Order #{$order_id}: label storage failed");
            }

            $order->add_order_note(sprintf(__('UPS WWE label(s) generated. Tracking: %s. Format: %s', 'wwe-ups-woocommerce-shipping'), $tracking_numbers_str, $received_format));
            
            // Persist all data and aggressively clear caches.
            $order->save();
            
            // HPOS verification already done above with individual label keys
            
            // Force cache clearing and object refresh
            wc_delete_shop_order_transients($order->get_id());
            wp_cache_delete( 'order-' . $order->get_id(), 'orders' );
            wp_cache_delete( $order->get_id(), 'posts' );
            clean_post_cache( $order->get_id() );
            
            // Force WordPress to reload the order from database
            wp_cache_flush();

            do_action('wwe_after_waybill_created', $order_id, $tracking_numbers);

            return ['tracking_number' => $tracking_numbers_str];
        }

        /** Annule une expédition WWE */
        private function void_wwe_shipment(WC_Order $order, $identifier) {
             $order_id = $order->get_id();
             wwe_ups_log("Attempting WWE void for Order #{$order_id} using identifier: {$identifier}");
             $instance_settings = get_option('woocommerce_' . WWE_UPS_ID . '_settings_' . $this->get_instance_id_from_order($order), []);
             $api_handler = new WWE_UPS_API_Handler($instance_settings);
             if (!$api_handler) { return new WP_Error('config_error', 'API Handler not configured.'); }

             $response = $api_handler->void_shipment($identifier);

             if (is_wp_error($response)) {
                  // Log détaillé de l'erreur API
                  wwe_ups_log("Void API Error for Order #{$order_id}: " . $response->get_error_message(), 'error');
                  if (isset($response->error_data['body'])) { wwe_ups_log("Void API Error Body: " . $response->error_data['body']); }
                  $order->add_order_note(__('Attempted WWE void failed: ', 'wwe-ups-woocommerce-shipping') . $response->get_error_message());
                  $order->save();
                  return $response;
             } else {
                  // Analyser la réponse de succès
                  $void_response_body = $response['body']['VoidShipmentResponse'] ?? ($response['body'] ?? null);
                  
                  // Check both ResponseStatus and SummaryResult for success
                  $response_success = isset($void_response_body['Response']['ResponseStatus']['Code']) 
                                    && $void_response_body['Response']['ResponseStatus']['Code'] == '1';
                  $summary_success = isset($void_response_body['SummaryResult']['Status']['Code']) 
                                   && $void_response_body['SummaryResult']['Status']['Code'] == '1';
                  
                  $is_success = $response_success && $summary_success;

                  if ($is_success) {
                      wwe_ups_log("Void successful for Order #{$order_id} identifier {$identifier}");
                      $order->add_order_note(sprintf(__('UPS WWE Shipment with identifier %s successfully voided.', 'wwe-ups-woocommerce-shipping'), $identifier));
                      $order->save(); // Save the note
                      return true;
                  } else {
                       $error_detail=$void_response_body['Response']['Error']??($void_response_body['Fault']??null);$error_message=__('Void command sent, but UPS did not confirm success.','wwe-ups-woocommerce-shipping');if(isset($error_detail['ErrorDescription']))$error_message=$error_detail['ErrorDescription'];elseif(isset($error_detail['faultstring']))$error_message=$error_detail['faultstring'];wwe_ups_log("Void Error Response Order #{$order_id}: {$error_message} | Body: ".print_r($response['body'],true),'error');$order->add_order_note(__('UPS WWE Void Failed: ','wwe-ups-woocommerce-shipping').$error_message);$order->save();return new WP_Error('api_void_error',$error_message);
                  }
             }
        }

        /** Simule le tarif pour une commande */
        private function simulate_rate_for_order(WC_Order $order, $return_details = false) {
            $order_id = $order->get_id();
            wwe_ups_log("Starting WWE rate simulation for Order #{$order_id}");
            $instance_settings = get_option('woocommerce_' . WWE_UPS_ID . '_settings_' . $this->get_instance_id_from_order($order), []);
            $api_handler = new WWE_UPS_API_Handler($instance_settings);
            if (!$api_handler) { return new WP_Error('config_error', 'API Handler not configured.'); }

            // --- Préparation des colis (toujours renvoie un tableau de colis prêts pour API) ---
            $api_packages = wwe_ups_prepare_api_packages_for_request($order);
            if (false === $api_packages || empty($api_packages)) {
                return new WP_Error('package_error', 'Error preparing packages for simulation.');
            }
            
            // Calculate total weight for display
            $total_weight_kg = 0;
            foreach ($api_packages as $package) {
                if (isset($package['PackageWeight']['Weight'])) {
                    $total_weight_kg += (float) $package['PackageWeight']['Weight'];
                }
            }

            $shipper_details = $this->get_shipper_details($order);
            $ship_to_details = $this->get_shipto_details($order);
            if (!$shipper_details || !$ship_to_details) { return new WP_Error('address_error', 'Missing address details.'); }

            $total_rate_cost = 0.0;
            
            // SINGLE SOURCE OF TRUTH: Force i-Parcel API for REAL WWE prices
            // No more 111212 workaround, no fallbacks - only real API prices
            
            if ($this->debug_mode) {
                wwe_ups_log("🎯 ADMIN: Using i-Parcel API as SINGLE SOURCE OF TRUTH for real WWE prices");
            }
            
            // Prepare i-Parcel data from first package
            if (!empty($api_packages)) {
                $first_package = $api_packages[0];
                $shipper_details = $this->get_shipper_details($order);
                $ship_to_details = $this->get_shipto_details($order);
                
                $shipment_data = [
                    'origin_country' => defined('WWE_SHIPPER_COUNTRY_CODE') ? WWE_SHIPPER_COUNTRY_CODE : 'FR',
                    'origin_postal' => defined('WWE_SHIPPER_POSTAL_CODE') ? WWE_SHIPPER_POSTAL_CODE : '75018',
                    'destination_country' => $ship_to_details['Address']['CountryCode'],
                    'destination_postal' => $ship_to_details['Address']['PostalCode'],
                    'weight' => floatval($first_package['PackageWeight']['Weight']),
                    'length' => floatval($first_package['Dimensions']['Length']),
                    'width' => floatval($first_package['Dimensions']['Width']),
                    'height' => floatval($first_package['Dimensions']['Height']),
                    'value' => (float) $order->get_total(),
                    'currency' => $order->get_currency()
                ];
                
                // SINGLE SOURCE OF TRUTH: Use same UPS API logic as frontend
                wwe_ups_log("📦 ADMIN UPS Direct API request for Order #{$order_id}");
                
                // Get shipper and ship-to details
                $shipper_details = $this->get_shipper_details($order);
                $ship_to_details = $this->get_shipto_details($order);
                
                if (!$shipper_details || !$ship_to_details) {
                    $error_msg = 'Unable to get shipper or ship-to details';
                    wwe_ups_log("❌ ADMIN CRITICAL: {$error_msg}");
                    return new WP_Error('address_failed', "Address details failed: {$error_msg}");
                }
                
                // Build UPS Rate Request like frontend
                $service_code = defined('UPS_WW_ECONOMY_SERVICE_CODE') ? UPS_WW_ECONOMY_SERVICE_CODE : '17'; // UPS Worldwide Economy
                
                $ups_request_body = [
                    'RateRequest' => [
                        'Request' => [
                            'RequestOption' => 'Rate',
                            'TransactionReference' => ['CustomerContext' => 'WWE Admin Rate Request Order ' . $order_id]
                        ],
                        'Shipment' => [
                            'Shipper' => $shipper_details,
                            'ShipTo' => $ship_to_details,
                            'ShipFrom' => [
                                'Name' => $shipper_details['Name'],
                                'AttentionName' => $shipper_details['AttentionName'],
                                'Address' => $shipper_details['Address']
                            ],
                            'PickupType' => ['Code' => '01'],
                            'CustomerClassification' => ['Code' => '00'],
                            'Service' => ['Code' => $service_code],
                            'Package' => $api_packages,
                            'PaymentDetails' => [
                                'ShipmentCharge' => [[
                                    'Type' => '01',
                                    'BillShipper' => ['AccountNumber' => defined('UPS_WW_ECONOMY_ACCOUNT_NUMBER') ? UPS_WW_ECONOMY_ACCOUNT_NUMBER : '']
                                ]]
                            ],
                            'InvoiceLineTotal' => [
                                'CurrencyCode' => $order->get_currency(),
                                'MonetaryValue' => number_format((float) $order->get_total(), 2, '.', '')
                            ],
                            'ShipmentRatingOptions' => ['NegotiatedRatesIndicator' => '1']
                        ]
                    ]
                ];
                
                wwe_ups_log("📤 ADMIN UPS Rate Request Body: " . print_r($ups_request_body, true));
                
                $api_handler = new WWE_UPS_API_Handler();
                $ups_response = $api_handler->get_rate($ups_request_body);
                
                if (!is_wp_error($ups_response)) {
                    // Extract negotiated rate like frontend
                    $negotiated_rate = null;
                    if (isset($ups_response['body']['RateResponse']['RatedShipment'][0]['NegotiatedRateCharges']['TotalCharge']['MonetaryValue'])) {
                        $negotiated_rate = floatval($ups_response['body']['RateResponse']['RatedShipment'][0]['NegotiatedRateCharges']['TotalCharge']['MonetaryValue']);
                    } elseif (isset($ups_response['body']['RateResponse']['RatedShipment'][0]['TotalCharges']['MonetaryValue'])) {
                        $negotiated_rate = floatval($ups_response['body']['RateResponse']['RatedShipment'][0]['TotalCharges']['MonetaryValue']);
                    }
                    
                    if ($negotiated_rate) {
                        $handling_fee = defined('WWE_HANDLING_FEE') ? floatval(WWE_HANDLING_FEE) : 1.0;
                        $total_rate_cost = $negotiated_rate + $handling_fee;
                        
                        wwe_ups_log("✅ ADMIN SINGLE SOURCE OF TRUTH: Found Negotiated Rate: {$negotiated_rate} EUR");
                        wwe_ups_log("✅ ADMIN FINAL PRICE: {$negotiated_rate}€ + {$handling_fee}€ handling = {$total_rate_cost}€ total");
                    } else {
                        wwe_ups_log("❌ ADMIN: No negotiated rate found in UPS response", 'error');
                        $error_msg = 'No negotiated rate found in UPS API response';
                    }
                } else {
                    // NO FALLBACK - Real UPS API failed
                    $error_msg = $ups_response->get_error_message();
                    wwe_ups_log("❌ ADMIN CRITICAL: i-Parcel API failed - NO RATE AVAILABLE: {$error_msg}");
                    return new WP_Error('real_api_failed', "Real WWE API failed: {$error_msg}");
                }
            }
            
            // OLD LOGIC REMOVED - No more UPS Rate API attempts, no more fallbacks
            // API-ONLY MODE - NO FALLBACK ALLOWED (Ben requirement 08-08-2025)
            // If no rate from API, return error - never use hardcoded rates
            if ($total_rate_cost == 0.0) {
                wwe_ups_log("ERROR WWE API: No rate returned from UPS API - NO FALLBACK ALLOWED", "error");
                return new WP_Error("api_no_rate", __("UPS API did not return a rate. Please contact support.", "wwe-ups-woocommerce-shipping"));
            }

            if ($return_details) {
                return [
                    'rate' => $total_rate_cost,
                    'weight' => $total_weight_kg
                ];
            }
            
            return $total_rate_cost;
        }

        /**
         * Calculate WWE negotiated rates based on your contract
         * UPDATED: Now uses unified calculation function
         * @param array $api_packages Array of packages
         * @param WC_Order $order The order object
         * @return float Total shipping cost
         */
        private function calculate_wwe_negotiated_rate($api_packages, WC_Order $order) {
            // Get destination country from order
            $destination_country = $order->get_shipping_country();
            if (empty($destination_country)) {
                $destination_country = $order->get_billing_country();
            }
            if (empty($destination_country)) {
                $destination_country = 'US'; // Safe fallback
            }
            
            // Calculate total weight for logging purposes
            $total_weight = 0;
            foreach ($api_packages as $package) {
                if (isset($package['PackageWeight']['Weight'])) {
                    $total_weight += (float) $package['PackageWeight']['Weight'];
                }
            }
            
            // Use unified calculation function
            $total_cost = wwe_ups_calculate_unified_negotiated_rate(
                $api_packages, 
                $destination_country, 
                $total_weight,
                'admin'
            );
            
            wwe_ups_log("ADMIN WWE Rate: Using unified calculation = €{$total_cost} for Order #{$order->get_id()} to {$destination_country}");
            
            return $total_cost;
        }

        // --- Helper methods (inchangés) ---
        private function get_instance_id_from_order( WC_Order $order ) {
            foreach ( $order->get_shipping_methods() as $m ) {
                if ( strpos( $m->get_method_id(), WWE_UPS_ID ) === 0 ) {
                    return $m->get_instance_id();
                }
            }
            // Fallback in manual override mode
            return defined( 'WWE_UPS_DEFAULT_INSTANCE_ID' ) ? WWE_UPS_DEFAULT_INSTANCE_ID : 0;
        }
        private function get_shipper_details(WC_Order $order) { $ph=preg_replace('/[^0-9]/','',defined('WWE_SHIPPER_PHONE')?WWE_SHIPPER_PHONE:'0000000000');$ac=defined('UPS_WW_ECONOMY_ACCOUNT_NUMBER')?UPS_WW_ECONOMY_ACCOUNT_NUMBER:'';if(empty($ac)){wwe_ups_log("Erreur: Numéro compte UPS WWE manquant.",'error');return false;}return['Name'=>substr(defined('WWE_SHIPPER_NAME')?WWE_SHIPPER_NAME:'Shipper',0,35),'AttentionName'=>substr(defined('WWE_SHIPPER_ATTENTION_NAME')?WWE_SHIPPER_ATTENTION_NAME:'Shipping',0,35),'ShipperNumber'=>$ac,'Address'=>['AddressLine'=>array_filter([substr(defined('WWE_SHIPPER_ADDRESS_LINE_1')?WWE_SHIPPER_ADDRESS_LINE_1:'',0,35)]),'City'=>substr(defined('WWE_SHIPPER_CITY')?WWE_SHIPPER_CITY:'',0,30),'PostalCode'=>substr(defined('WWE_SHIPPER_POSTAL_CODE')?WWE_SHIPPER_POSTAL_CODE:'',0,10),'CountryCode'=>defined('WWE_SHIPPER_COUNTRY_CODE')?WWE_SHIPPER_COUNTRY_CODE:''],'Phone'=>['Number'=>$ph],'EMailAddress'=>defined('WWE_SHIPPER_EMAIL')?WWE_SHIPPER_EMAIL:'']; }
        private function get_shipto_details(WC_Order $order) {
            $order_id = $order->get_id();
            
            // Try shipping address first, fallback to billing
            $address = $order->get_address('shipping');
            if (empty($address['country']) || empty($address['postcode'])) {
                $address = $order->get_address('billing');
            }
            
            // Debug: Log what we have in the address
            wwe_ups_log("🔍 ADMIN DEBUG Order #{$order_id} Address: " . print_r($address, true));
            
            // Validation intelligente - certains pays n'ont pas de codes postaux obligatoires
            $country = $address['country'] ?? '';
            $postcode = $address['postcode'] ?? '';
            $city = $address['city'] ?? '';
            
            // Pays sans codes postaux obligatoires
            $countries_without_mandatory_postcode = ['AE', 'AF', 'AO', 'AG', 'AW', 'BH', 'BJ', 'BW', 'BF', 'BI', 'CM', 'CF', 'TD', 'CG', 'CD', 'CI', 'DJ', 'DM', 'GQ', 'ER', 'FJ', 'GA', 'GM', 'GH', 'GD', 'GW', 'GY', 'HK', 'KI', 'KW', 'LY', 'MO', 'MW', 'ML', 'MR', 'MU', 'NR', 'NE', 'NG', 'NU', 'OM', 'PW', 'QA', 'RW', 'KN', 'LC', 'VC', 'WS', 'ST', 'SA', 'SC', 'SL', 'SB', 'SO', 'SR', 'TL', 'TK', 'TO', 'TV', 'UG', 'VU', 'YE', 'ZM', 'ZW'];
            
            if (empty($country)) {
                wwe_ups_log("❌ ADMIN: Order #{$order_id} - Country manquant", 'error');
                wwe_ups_log("Erreur: Adresse ShipTo incomplète Order #{$order_id}", 'error');
                return false;
            }
            
            // Pour les pays avec codes postaux obligatoires, vérifier qu'il est présent
            if (!in_array($country, $countries_without_mandatory_postcode) && empty($postcode)) {
                wwe_ups_log("❌ ADMIN: Order #{$order_id} - Code postal requis pour {$country} mais manquant", 'error');
                wwe_ups_log("Erreur: Adresse ShipTo incomplète Order #{$order_id}", 'error');
                return false;
            }
            
            // Vérifier que la ville est présente (toujours obligatoire)
            if (empty($city)) {
                wwe_ups_log("❌ ADMIN: Order #{$order_id} - Ville manquante", 'error');
                wwe_ups_log("Erreur: Adresse ShipTo incomplète Order #{$order_id}", 'error');
                return false;
            }
            
            // Log success pour debug
            if (in_array($country, $countries_without_mandatory_postcode)) {
                wwe_ups_log("✅ ADMIN: Order #{$order_id} - {$country} ne nécessite pas de code postal, validation OK");
            }
            
            // Better phone number handling
            $phone = '';
            
            // Try multiple sources for phone number
            if (!empty($address['phone'])) {
                $phone = $address['phone'];
            } elseif (!empty($order->get_billing_phone())) {
                $phone = $order->get_billing_phone();
            } elseif (!empty($order->get_shipping_phone())) {
                $phone = $order->get_shipping_phone();
            }
            
            // Clean and validate phone number using UPS requirements
            $clean_phone = $this->wwe_validate_ups_phone($phone);
            if (empty($phone)) {
                wwe_ups_log("Warning: Téléphone ShipTo manquant Order #{$order_id}. Utilisation d'un numéro par défaut.", 'warning');
            }
            
            return [
                'Name' => substr(trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? 'Customer')), 0, 35),
                'AttentionName' => substr(trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? 'Customer')), 0, 35),
                'CompanyName' => substr($address['company'] ?? '', 0, 35),
                'Address' => [
                    'AddressLine' => array_filter([
                        substr($address['address_1'] ?? '', 0, 35),
                        substr($address['address_2'] ?? '', 0, 35)
                    ]),
                    'City' => substr($address['city'] ?? '', 0, 30),
                    'StateProvinceCode' => $this->get_state_code_with_fallback($address),
                    'PostalCode' => substr($address['postcode'] ?? '', 0, 10),
                    'CountryCode' => $address['country'] ?? ''
                ],
                'Phone' => ['Number' => $clean_phone],
                'EMailAddress' => $address['email'] ?? $order->get_billing_email()
            ];
        }
        private function get_international_forms_products(WC_Order $order) {
            $items = [];

            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $product = $item->get_product();
                $qty     = $item->get_quantity();

                if ( ! $product || $qty < 1 || ! $product->needs_shipping() ) {
                    continue;
                }

                $defaults = function_exists( 'yoyaku_default_vinyl_item' ) ? yoyaku_default_vinyl_item() : [];

                $sku  = $product->get_sku() ?: ( $defaults['sku'] ?? 'UNKNOWN' );
                $name = $product->get_name() ?: ( $defaults['desc'] ?? 'Item' );
                $desc = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 35 ) : substr( $name, 0, 35 );

                $hs = $product->get_meta( '_wps_hs_code' ) ?: $product->get_meta( '_hs_code' );
                if ( ! $hs ) {
                    // HPOS-compatible: Using get_meta instead of get_post_meta
                    $hs = $product->get_meta( '_wps_hs_code' ) ?: $product->get_meta( '_hs_code' );
                }
                if ( empty( $hs ) && isset( $defaults['hs'] ) ) {
                    $hs = $defaults['hs'];
                }
                $hs = substr( preg_replace( '/[^0-9]/', '', $hs ), 0, 15 );

                $origin = $product->get_meta( '_origin_country' );
                if ( ! $origin ) {
                    // HPOS-compatible: Using get_meta instead of get_post_meta
                    $origin = $product->get_meta( '_origin_country' );
                }
                if ( empty( $origin ) && isset( $defaults['origin'] ) ) {
                    $origin = $defaults['origin'];
                }

                // Force unit value to 2.00 for i-Parcel compatibility
                $unit_value = 2.00;
                wwe_ups_log( "International Forms: Product {$sku} - OriginalPrice forced to 2.00 for i-Parcel compatibility" );

                $weight_kg = wc_get_weight( $product->get_weight(), 'kg' );
                if ( false === $weight_kg || ! is_numeric( $weight_kg ) || $weight_kg <= 0 ) {
                    $fallback_w = $defaults['weight_lbs'] ?? 0.1;
                    $weight_kg  = wc_get_weight( $fallback_w, 'kg' );
                }

                $items[] = [
                    'SKU'                  => $sku,
                    'ProductDescription'   => $desc,
                    'Quantity'             => (string) $qty,
                    'HTSCode'              => $hs,
                    'CountryOfOrigin'      => $origin ?: 'FR',
                    'OriginalPrice'        => '2.00',
                    'ValueCompanyCurrency' => '2.00',
                    'CompanyCurrency'      => $order->get_currency(),
                    'ProductWeight'        => [
                        'UnitOfMeasurement' => [ 'Code' => WWE_PACKAGE_WEIGHT_UNIT ],
                        'Weight'            => number_format( (float) $weight_kg, 3, '.', '' ),
                    ],
                ];
            }

            return $items;
        }


        // --- Bulk Action Methods ---

        /** Ajouter les actions groupées WWE */
        public function add_wwe_bulk_actions($bulk_actions) {
            $new_actions = []; $marker_found = false;
            foreach ($bulk_actions as $key => $label) {
                if (in_array($key, ['mark_processing', 'mark_completed'])) {
                    $new_actions[$key] = $label;
                    if (!$marker_found) {
                        $new_actions['wwe_ups_bulk_generate_labels'] = __('Generate UPS WWE Labels', 'wwe-ups-woocommerce-shipping');
                        $new_actions['wwe_ups_bulk_print_labels_pdf'] = __('Print UPS WWE Labels (PDF)', 'wwe-ups-woocommerce-shipping');
                        $new_actions['wwe_ups_bulk_submit_customs'] = __('🚀 Submit Customs Documents (Auto)', 'wwe-ups-woocommerce-shipping');
                        // $new_actions['wwe_ups_bulk_void_shipments'] = __('Void UPS WWE Shipments', 'wwe-ups-woocommerce-shipping'); // Décommenter pour ajouter Void
                        $marker_found = true;
                    }
                } else { $new_actions[$key] = $label; }
            }
            if (!$marker_found) { // Ajouter à la fin si marqueurs absents
                 $new_actions['wwe_ups_bulk_generate_labels'] = __('Generate UPS WWE Labels', 'wwe-ups-woocommerce-shipping');
                 $new_actions['wwe_ups_bulk_print_labels_pdf'] = __('Print UPS WWE Labels (PDF)', 'wwe-ups-woocommerce-shipping');
                 // $new_actions['wwe_ups_bulk_void_shipments'] = __('Void UPS WWE Shipments', 'wwe-ups-woocommerce-shipping');
            }
            return $new_actions;
        }

        /** Gérer les actions groupées (HPOS) */
        public function handle_wwe_bulk_actions_hpo() {
            // ─── DEBUG: Log bulk action invocation ───
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $screen_id = function_exists( 'get_current_screen' ) && get_current_screen() ? get_current_screen()->id : 'na';
                $action = isset( $_REQUEST['action'] ) && $_REQUEST['action'] !== '-1'
                    ? sanitize_key( $_REQUEST['action'] )
                    : ( isset( $_REQUEST['action2'] ) && $_REQUEST['action2'] !== '-1'
                        ? sanitize_key( $_REQUEST['action2'] )
                        : '(none)'
                    );
                error_log(
                    'BULK ACTION FIRED → screen=' . $screen_id .
                    ' | action=' . $action .
                    ' | params=' . implode( ',', array_keys( $_REQUEST ) )
                );
            }
            // Removed screen check because get_current_screen() is not available during admin_init.
            $action = isset($_REQUEST['action']) && $_REQUEST['action'] != -1 ? sanitize_key($_REQUEST['action']) : (isset($_REQUEST['action2']) && $_REQUEST['action2'] != -1 ? sanitize_key($_REQUEST['action2']) : null);

            // --- Updated order_ids assignment logic ---
            $order_ids = [];

            // HPOS passes an array named "orders" (or sometimes "order_ids")
            if ( isset( $_REQUEST['orders'] ) ) {
                $order_ids = (array) wp_parse_id_list( $_REQUEST['orders'] );
            } elseif ( isset( $_REQUEST['order_ids'] ) ) {
                $order_ids = (array) wp_parse_id_list( $_REQUEST['order_ids'] );
            }
            // Classic list table passes "id" or "post"
            elseif ( isset( $_REQUEST['id'] ) ) {
                $order_ids = (array) wp_parse_id_list( $_REQUEST['id'] );
            } elseif ( isset( $_REQUEST['post'] ) ) {
                $order_ids = (array) wp_parse_id_list( $_REQUEST['post'] );
            }
            // Accept "order" param as well
            elseif ( isset( $_REQUEST['order'] ) ) {
                $order_ids = (array) wp_parse_id_list( $_REQUEST['order'] );
            }

            if ( empty( $order_ids ) ) {
                return;  // nothing selected – stop here
            }
            // --- End updated order_ids assignment ---

            if ( !$action || strpos($action, 'wwe_ups_bulk_') !== 0 ) return;
            check_admin_referer('bulk-orders');

            $result_counts = $this->process_bulk_action($action, $order_ids); // Appelle la logique commune

            if (class_exists('WC_Admin_Notices') && is_array($result_counts)) { /* ... (Affichage notice HPOS) ... */
                 $message = ''; if ($result_counts['report_action'] === 'none') { $message = __('No eligible orders found for the selected WWE bulk action.', 'wwe-ups-woocommerce-shipping'); WC_Admin_Notices::add_custom_notice('wwe_bulk_action_result', $message, 'warning'); } else if ($result_counts['report_count'] > 0 || $result_counts['error_count'] > 0) { if ($result_counts['report_count'] > 0) $message .= sprintf(_n('%1$d WWE label %2$s.', '%1$d WWE labels %2$s.', $result_counts['report_count'], 'wwe-ups-woocommerce-shipping'), $result_counts['report_count'], esc_html($result_counts['report_action'])); if ($result_counts['error_count'] > 0) $message .= ($result_counts['report_count'] > 0 ? ' ' : '') . sprintf(_n('%d error encountered.', '%d errors encountered.', $result_counts['error_count'], 'wwe-ups-woocommerce-shipping'), $result_counts['error_count']); WC_Admin_Notices::add_custom_notice('wwe_bulk_action_result', $message, $result_counts['error_count'] > 0 ? 'error' : 'success'); }
            }
            wp_safe_redirect(remove_query_arg(['action', 'action2', 'id', '_wpnonce', '_wp_http_referer'], wp_get_referer() ?: admin_url('admin.php?page=wc-orders'))); exit;
        }

        /** Gérer les actions groupées (Legacy) */
        public function handle_wwe_bulk_actions_legacy($redirect_to, $action, $post_ids) {
             if (strpos($action, 'wwe_ups_bulk_') !== 0) return $redirect_to;
             $result_counts = $this->process_bulk_action($action, $post_ids); // Appelle la logique commune
             if (is_array($result_counts)) { $query_args = ['wwe_bulk_action' => $result_counts['report_action'], 'wwe_processed' => $result_counts['report_count'], 'wwe_errors' => $result_counts['error_count']]; return add_query_arg($query_args, $redirect_to); }
             return $redirect_to;
        }

        /** Logique commune de traitement des actions groupées */
        private function process_bulk_action($action, $order_ids) {
            $report_count = 0; $error_count = 0; $report_action = ''; $order_ids_to_process = [];

            // 1. Filtrer les commandes éligibles
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id); if (!$order) continue;
                $used_wwe = false; foreach ($order->get_shipping_methods() as $method) { if (strpos($method->get_method_id(), WWE_UPS_ID) === 0) { $used_wwe = true; break; } }
                if ( ! $used_wwe && ! $this->allow_manual_override() ) {
                    continue;
                }
                if ($action === 'wwe_ups_bulk_generate_labels' && !$order->get_meta('_wwe_ups_tracking_number', true)) { $order_ids_to_process[] = $order_id; }
                elseif ($action === 'wwe_ups_bulk_print_labels_pdf' && $order->get_meta('_wwe_ups_label_count', true) > 0) { $order_ids_to_process[] = $order_id; }
                elseif ($action === 'wwe_ups_bulk_submit_customs' && $order->get_meta('_wwe_ups_tracking_number', true) && $order->get_meta('_ups_customs_submitted', true) !== 'yes') { $order_ids_to_process[] = $order_id; }
                // elseif ($action === 'wwe_ups_bulk_void_shipments' && $order->get_meta('_wwe_ups_tracking_number', true)) { $order_ids_to_process[] = $order_id; } // Décommenter pour Void
            }
            if (empty($order_ids_to_process)) { return ['report_action' => 'none', 'report_count' => 0, 'error_count' => 0]; }

            // 2. Exécuter l'action
            switch ($action) {
                case 'wwe_ups_bulk_generate_labels':
                    $report_action = __('generated', 'wwe-ups-woocommerce-shipping');
                    foreach ($order_ids_to_process as $oid) {
                        $order = wc_get_order($oid); if (!$order) { $error_count++; continue; }
                        $result = $this->generate_label_for_order($order);
                        if (is_wp_error($result)) $error_count++; else $report_count++; usleep(100000); // Pause 100ms
                    }
                    break;
                case 'wwe_ups_bulk_print_labels_pdf':
                    if (!current_user_can('manage_woocommerce')) { wp_die('Permission Denied'); }
                    $label_data = $this->get_bulk_wwe_label_data($order_ids_to_process);
                    $this->generate_bulk_wwe_pdf($label_data); // Fait exit;
                    break;
                case 'wwe_ups_bulk_submit_customs':
                    if (!current_user_can('manage_woocommerce')) { wp_die('Permission Denied'); }
                    $report_action = __('customs submitted', 'wwe-ups-woocommerce-shipping');
                    
                    foreach ($order_ids_to_process as $oid) {
                        $order = wc_get_order($oid);
                        if (!$order) { 
                            $error_count++; 
                            continue; 
                        }
                        
                        $tracking_number = $order->get_meta('_wwe_ups_tracking_number', true);
                        if (!$tracking_number) {
                            wwe_ups_log("Order #{$oid}: No tracking number found, skipping customs submission", 'warning');
                            $error_count++;
                            continue;
                        }
                        
                        // Use new API Paperless Documents
                        $api_handler = new WWE_UPS_API_Handler();
                        $result = $api_handler->submit_complete_customs_documents($order, $tracking_number);
                        
                        if (is_wp_error($result)) {
                            wwe_ups_log("Order #{$oid}: Customs submission failed - " . $result->get_error_message(), 'error');
                            $error_count++;
                        } else {
                            wwe_ups_log("Order #{$oid}: Customs documents submitted successfully", 'info');
                            $report_count++;
                        }
                        
                        // Small delay to avoid overwhelming API
                        usleep(100000); // 100ms pause
                    }
                    break;
                // case 'wwe_ups_bulk_void_shipments': // Ajouter pour Void
                //     $report_action = __('voided', 'wwe-ups-woocommerce-shipping');
                //     foreach ($order_ids_to_process as $oid) { $order = wc_get_order($oid); if (!$order) { $error_count++; continue; } $identifier = $order->get_meta('_wwe_ups_shipment_id', true) ?: current(explode(',', $order->get_meta('_wwe_ups_tracking_number', true))); if ($identifier) { $result = $this->void_wwe_shipment($order, $identifier); if (is_wp_error($result)) $error_count++; else $report_count++; } else { $error_count++; } usleep(100000); } break;
            }
            return ['report_action' => $report_action, 'report_count' => $report_count, 'error_count' => $error_count];
        }

        /** Afficher notices actions groupées (Legacy) */
        public function show_legacy_bulk_action_notices() {
            if (empty($_REQUEST['wwe_bulk_action'])) {
                return;
            }

            $action = sanitize_text_field($_REQUEST['wwe_bulk_action']);
            $processed = isset($_REQUEST['wwe_processed']) ? absint($_REQUEST['wwe_processed']) : 0;
            $errors = isset($_REQUEST['wwe_errors']) ? absint($_REQUEST['wwe_errors']) : 0;
            $message = '';
            $type = 'warning'; // Default to warning

            if ($action === 'none') {
                $message = __('No eligible orders found for the selected WWE bulk action.', 'wwe-ups-woocommerce-shipping');
            } else {
                if ($processed > 0) {
                    $message .= sprintf(
                        _n(
                            '%1$d WWE label %2$s.',
                            '%1$d WWE labels %2$s.',
                            $processed,
                            'wwe-ups-woocommerce-shipping'
                        ),
                        $processed,
                        esc_html($action)
                    );
                }
                if ($errors > 0) {
                    $message .= ($processed > 0 ? ' ' : '') . sprintf(
                        _n(
                            '%d error encountered.',
                            '%d errors encountered.',
                            $errors,
                            'wwe-ups-woocommerce-shipping'
                        ),
                        $errors
                    );
                } else if ($processed > 0) {
                    $type = 'success'; // Only success if there are no errors
                }
            }
            
            if (!empty($message)) {
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . wp_kses_post($message) . '</p></div>';

                // Inline JS to remove query args from URL to prevent notice from re-appearing on reload
                ?>
                <script type="text/javascript">
                    document.addEventListener('DOMContentLoaded', function() {
                        if (window.history && window.history.replaceState) {
                            const url = new URL(window.location.href);
                            url.searchParams.delete('wwe_bulk_action');
                            url.searchParams.delete('wwe_processed');
                            url.searchParams.delete('wwe_errors');
                            window.history.replaceState({path: url.href}, '', url.href);
                        }
                    });
                </script>
                <?php
            }
        }

        /** Helper pour récupérer les données base64 des étiquettes pour le PDF groupé */
        private function get_bulk_wwe_label_data(array $order_ids) {
             $labels = [];
             foreach ($order_ids as $order_id) {
                 $order = wc_get_order($order_id); if (!$order) continue;
                 
                 // HPOS-COMPATIBLE: Retrieve labels using WooCommerce order meta methods
                 $label_count = $order->get_meta('_wwe_ups_label_count', true);
                 $label_data = null;
                 
                 if ($label_count > 0) {
                     if ($label_count == 1) {
                         // Single label
                         $label_data = $order->get_meta('_wwe_ups_label_0', true);
                     } else {
                         // Multiple labels - build array
                         $label_data = [];
                         for ($i = 0; $i < $label_count; $i++) {
                             $single_label = $order->get_meta("_wwe_ups_label_{$i}", true);
                             if (!empty($single_label)) {
                                 $label_data[$i] = $single_label;
                             }
                         }
                     }
                 }
                 
                 $format = $order->get_meta('_wwe_ups_label_format', true) ?: (defined('WWE_LABEL_FORMAT') ? WWE_LABEL_FORMAT : 'PNG');
                 $tracking = $order->get_meta('_wwe_ups_tracking_number', true) ?: $order_id;

                 if (!empty($label_data)) {
                     if (is_array($label_data)) { // Multi-colis
                         foreach ($label_data as $index => $single_label) { if (!empty($single_label)) { $labels[] = ['order_id' => $order_id, 'index' => $index, 'format' => $format, 'base64' => $single_label, 'tracking' => $tracking]; } }
                     } else { // Mono-colis
                         $labels[] = ['order_id' => $order_id, 'index' => 0, 'format' => $format, 'base64' => $label_data, 'tracking' => $tracking];
                     }
                 } else { wwe_ups_log("Bulk Print: No label data found for Order #{$order_id}", 'warning'); }
             }
             return $labels;
        }

        /**
         * Helper for merging multiple UPS label PDFs into one document.
         *
         * @param array $labels List of base64-encoded label data, each element is an array with order_id, base64, etc.
         */
        private function generate_bulk_wwe_pdf(array $labels) {
            if (empty($labels)) {
                wp_die(__('No labels found to print.', 'wwe-ups-woocommerce-shipping'));
            }

            $upload = wp_get_upload_dir();
            $tmp_dir = trailingslashit($upload['basedir']) . 'wwe-ups-tmp-' . uniqid() . '/';
            if (!wp_mkdir_p($tmp_dir)) {
                wp_die(__('Unable to create temporary directory for PDF merge.', 'wwe-ups-woocommerce-shipping'));
            }

            $pdf = new Fpdi();
            $tmp_files = [];

            foreach ($labels as $label_info) {
                $base64 = $label_info['base64'];
                $format = strtoupper($label_info['format']);
                $tracking = preg_replace('/[^a-zA-Z0-9]/', '', $label_info['tracking']);
                $index = (int) $label_info['index'];

                $file_path = $tmp_dir . "label-{$tracking}-{$index}.pdf";

                // Convert GIF or rotated PDF to portrait PDF
                try {
                    $img = new \Imagick();
                    $img->readImageBlob(base64_decode($base64));
                    $img->rotateImage(new \ImagickPixel('white'), 90);
                    $img->setImageFormat('pdf');
                    file_put_contents($file_path, $img->getImagesBlob());
                    $img->clear(); $img->destroy();
                } catch (\Exception $e) {
                    continue;
                }

                $tmp_files[] = $file_path;
            }

            foreach ($tmp_files as $file) {
                $page_count = $pdf->setSourceFile($file);
                for ($page_no = 1; $page_no <= $page_count; $page_no++) {
                    $tpl = $pdf->importPage($page_no);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage('P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            }

            $merged_path = $tmp_dir . 'ups-labels-' . date_i18n('Ymd-His') . '.pdf';
            $pdf->Output($merged_path, 'F');

            if (file_exists($merged_path)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="ups-labels.pdf"');
                readfile($merged_path);
                array_map('unlink', glob($tmp_dir . '*.pdf'));
                rmdir($tmp_dir);
                exit;
            }

            wp_die(__('Unable to generate merged PDF.', 'wwe-ups-woocommerce-shipping'));
        }

        // --- Custom Column Methods (inchangés) ---
        public function add_wwe_order_column($columns) { $nc=[];foreach($columns as $k=>$l){$nc[$k]=$l;if('shipping_address'===$k){$nc['wwe_tracking_column']=__('UPS WWE Tracking','wwe-ups-woocommerce-shipping');}}return $nc; }
        public function render_wwe_order_column($column, $post_id) { 
            if('wwe_tracking_column'===$column){
                $o=wc_get_order($post_id);
                $t=$o?$o->get_meta('_wwe_ups_tracking_number',true):'';
                if($t){
                    echo '<div class="wwe-ups-tracking-info">';
                    $tracking_numbers = explode(',', $t); 
                    $links = [];
                    foreach ($tracking_numbers as $tracking) { 
                        $tracking = trim($tracking); 
                        if (!empty($tracking)) { 
                            $links[] = '<a href="' . esc_url('https://www.ups.com/track?loc=en_US&tracknum=' . urlencode($tracking) . '&requester=WT/trackdetails') . '" target="_blank">' . esc_html($tracking) . '</a>'; 
                        } 
                    }
                    echo implode(', ', $links); 
                    echo '</div>';
                }else{
                    echo ' N/A ';
                }
            } 
        }
        public function render_wwe_hpos_order_column($column, $order) { 
            if('wwe_tracking_column'===$column){
                $t=$order->get_meta('_wwe_ups_tracking_number',true);
                if($t){
                    echo '<div class="wwe-ups-tracking-info">';
                    $tracking_numbers = explode(',', $t); 
                    $links = [];
                    foreach ($tracking_numbers as $tracking) { 
                        $tracking = trim($tracking); 
                        if (!empty($tracking)) { 
                            $links[] = '<a href="' . esc_url('https://www.ups.com/track?loc=en_US&tracknum=' . urlencode($tracking) . '&requester=WT/trackdetails') . '" target="_blank">' . esc_html($tracking) . '</a>'; 
                        } 
                    }
                    echo implode(', ', $links); 
                    echo '</div>';
                }else{
                    echo ' N/A ';
                }
            } 
        }

        /** Add the UPS action button in the orders list */
        public function render_wwe_list_action_button( $order ) {

            error_log( sprintf( 'WWE DEBUG: render_list_action for order %d', $order->get_id() ) );

            $show_button = $this->order_in_wwe_zone( $order );
            if ( ! $show_button ) {
                // Button is not rendered if outside WWE zone.
                return;
            }

            $oid  = $order->get_id();
            $lurl = plugins_url('woocommerce-ups-wwe/resources/images/ups-wwe-logo-32x32.png');

            echo '<a href="#" class="button tip wwe-ups-list-generate-label-button"
                     data-order-id="' . esc_attr( $oid ) . '"
                     data-tip="' . esc_attr__( 'Generate UPS WWE Label', 'wwe-ups-woocommerce-shipping' ) . '">
                     <img class="wwe-ups-logo-32x32" src="' . esc_url( $lurl ) . '" alt="UPS"/>
                 </a>';
        }

    /**
     * Get state code with intelligent fallback for countries that require it.
     * Same logic as the shipping method class.
     * 
     * @param array $address Address array
     * @return string State code or appropriate fallback
     */
    private function get_state_code_with_fallback($address) {
        $state = $address['state'] ?? '';
        $country = $address['country'] ?? '';
        
        // If state is provided, use it (truncated to 5 chars for UPS)
        if (!empty($state)) {
            return substr($state, 0, 5);
        }
        
        // For countries that require state, provide intelligent fallbacks
        switch ($country) {
            case 'US':
                // For US without state, try to guess from city or use a default
                $city = strtolower($address['city'] ?? '');
                if (strpos($city, 'new york') !== false || strpos($city, 'nyc') !== false) {
                    return 'NY';
                } elseif (strpos($city, 'los angeles') !== false || strpos($city, 'la') !== false) {
                    return 'CA';
                } elseif (strpos($city, 'chicago') !== false) {
                    return 'IL';
                }
                // Default to NY for US if no state provided
                wwe_ups_log("⚠️ ADMIN: US address without state - using NY as fallback for: " . ($city ?: 'unknown city'));
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

    } // End class WWE_UPS_Admin
}
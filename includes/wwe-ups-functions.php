<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// ============================================================================
// HPOS COMPATIBILITY HELPER FUNCTIONS
// Version: HPOS Compatible - 2025-01-10
// ============================================================================

/**
 * Get order meta data in HPOS-compatible way
 * 
 * @param int|WC_Order $order_id Order ID or WC_Order object
 * @param string $meta_key Meta key to retrieve
 * @param bool $single Whether to return single value (default: true)
 * @return mixed Meta value
 */
function wwe_ups_get_order_meta_hpos_compatible( $order_id, $meta_key, $single = true ) {
    // If we already have a WC_Order object
    if ( is_object( $order_id ) && is_a( $order_id, 'WC_Order' ) ) {
        return $order_id->get_meta( $meta_key, $single );
    }
    
    // Get order object
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return $single ? '' : array();
    }
    
    // Use WooCommerce native method (HPOS compatible)
    return $order->get_meta( $meta_key, $single );
}

if (!defined('WWE_LABEL_FORMAT')) {
    define('WWE_LABEL_FORMAT', 'GIF'); // Force GIF as the label format
}

// ---------------------------------------------------------------------
// Fallback values for a standard 12" vinyl record (UPS SubmitParcel)
// ---------------------------------------------------------------------
if ( ! function_exists( 'yoyaku_default_vinyl_item' ) ) {
    /**
     * Provides default customs data for a vinyl record when product metadata is missing.
     *
     * @return array{
     *     sku: string,
     *     desc: string,
     *     hs: string,
     *     origin: string,
     *     value: float,
     *     weight_lbs: float,
     *     dimensions: array{
     *         length: float,
     *         width: float,
     *         height: float,
     *     }
     * }
     */
    function yoyaku_default_vinyl_item() {
        return [
            // Stock Keeping Unit when none is provided
            'sku'        => 'vinyl',
            // Description used for customs declarations
            'desc'       => 'Vinyl Record',
            // Harmonized System code without separators
            'hs'         => '85232910',
            // Country of origin ISO code
            'origin'     => 'FR',
            // Unit value used for customs declarations
            'value'      => 10.00,
            // Fallback weight in pounds
            'weight_lbs' => 0.25,
            'dimensions' => [
                'length' => 30.0,
                'width'  => 30.0,
                'height' => 1.5,
            ],
        ];
    }
}

/**
 * WWE UPS Logger function.
 *
 * Uses WC_Logger to log events.
 *
 * @param string|array|object $message Log message.
 * @param string $level Optional. Default 'debug'. Options: 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'.
 */
function wwe_ups_log($message, $level = 'debug') {
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $context = array('source' => WWE_UPS_LOG_SOURCE); // Use defined constant

        // Convert objects/arrays to string for logging if they are not already strings
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        if (method_exists($logger, $level)) {
             // Check if context is expected/handled correctly by the specific WC_Logger method version
             // Some might just take the message string.
             try {
                $logger->{$level}($message, $context);
             } catch (Exception $e) {
                 // Fallback if context causes error
                 $logger->{$level}($message . ' [Context Source: ' . WWE_UPS_LOG_SOURCE . ']');
             }
        } else {
             // Fallback for potentially incorrect levels
             $logger->debug($message . ' (Level: ' . $level . ')', $context);
        }
    } else {
        // Fallback if WC_Logger isn't available
        error_log('WWE UPS (' . strtoupper($level) . '): ' . (is_string($message) ? $message : print_r($message, true)));
    }
}


/**
 * Obtient les détails du colis basé sur le poids pour UPS Worldwide Economy.
 * Règles: 0-5kg (33x33x4), 5-12kg (33x33x10), 12-15kg (33x33x33)
 * Pour les colis divisés (>15kg), utilise toujours 33x33x33cm
 */
function wwe_ups_get_package_details_by_weight($package_weight_kg, $is_divided_package = false) {
    $dim_unit   = defined('WWE_PACKAGE_DIM_UNIT') ? WWE_PACKAGE_DIM_UNIT : 'CM';
    $weight_unit = defined('WWE_PACKAGE_WEIGHT_UNIT') ? WWE_PACKAGE_WEIGHT_UNIT : 'KGS';
    $min_weight = defined('WWE_MINIMUM_PACKAGE_WEIGHT') ? WWE_MINIMUM_PACKAGE_WEIGHT : 0.1;
    $max_weight_single_box = defined('WWE_MAX_WEIGHT') ? WWE_MAX_WEIGHT : 15.0; // Max pour UN colis

    $package_weight_kg = max(floatval($package_weight_kg), $min_weight); // Applique poids minimum

    $package = false;

    // Si c'est un colis divisé, utilise toujours la boîte 33x33x33cm
    if ($is_divided_package) {
        wwe_ups_log("Colis divisé: utilisation forcée de la boîte 33x33x33cm pour {$package_weight_kg}kg");
        $package = [
            'name'        => 'Colis Divisé 33x33x33',
            'length'      => 33,
            'width'       => 33,
            'height'      => 33,
            'weight'      => $package_weight_kg,
            'dim_unit'    => $dim_unit,
            'weight_unit' => $weight_unit,
            'packaging_code' => '02' // Customer Supplied Package (comme version GitHub qui fonctionnait)
        ];
    } else {
        // Applique les règles seulement si <= 15kg (la division gère le > 15kg)
        if ($package_weight_kg > $max_weight_single_box) {
            wwe_ups_log("Attention: wwe_ups_get_package_details_by_weight appelée avec {$package_weight_kg}kg > {$max_weight_single_box}kg. Utilise la division de colis.", 'warning');
            return false;
        }

        // Règles par poids pour colis unique
        if ($package_weight_kg <= 5.0) {
            $package = [
                'name'        => 'Petit Colis 33x33x4',
                'length'      => 33,
                'width'       => 33,
                'height'      => 4,
                'weight'      => $package_weight_kg,
                'dim_unit'    => $dim_unit,
                'weight_unit' => $weight_unit,
                'packaging_code' => '02' // Customer Supplied Package (comme version GitHub qui fonctionnait)
            ];
        } elseif ($package_weight_kg <= 12.0) {
            $package = [
                'name'        => 'Moyen Colis 33x33x10',
                'length'      => 33,
                'width'       => 33,
                'height'      => 10,
                'weight'      => $package_weight_kg,
                'dim_unit'    => $dim_unit,
                'weight_unit' => $weight_unit,
                'packaging_code' => '02' // Customer Supplied Package (comme version GitHub qui fonctionnait)
            ];
        } else {
            $package = [
                'name'        => 'Grand Colis 33x33x33',
                'length'      => 33,
                'width'       => 33,
                'height'      => 33,
                'weight'      => $package_weight_kg,
                'dim_unit'    => $dim_unit,
                'weight_unit' => $weight_unit,
                'packaging_code' => '02' // Customer Supplied Package (comme version GitHub qui fonctionnait)
            ];
        }
    }

    return $package;
}

/**
 * Prépare les colis pour l'API UPS (avec division si nécessaire).
 *
 * @param WC_Order|array $order_or_package WC_Order (admin) ou $package (frontend)
 * @return array|false Tableau des colis formatés pour l'API ou false si erreur.
 */
function wwe_ups_prepare_api_packages_for_request($order_or_package) {
    $total_weight_kg  = 0;
    $items_count      = 0;
    $missing_weight   = false;
    $is_order         = ($order_or_package instanceof WC_Order);
    $order_id         = $is_order ? $order_or_package->get_id() : 0; // Pour logs

    $contents = $is_order ? $order_or_package->get_items('line_item') : ($order_or_package['contents'] ?? []);

    if (empty($contents)) {
        wwe_ups_log("Erreur préparation colis ({$order_id}): Contenu vide.", 'error');
        return false;
    }

    foreach ($contents as $item_id => $item_or_values) {
        $item     = $is_order ? $item_or_values : null;
        $values   = $is_order ? null : $item_or_values;
        $product  = $is_order ? $item->get_product() : ($values['data'] ?? null);
        $qty      = $is_order ? $item->get_quantity() : ($values['quantity'] ?? 0);
        
        // Handle special shipping payment products
        if ($is_order && $item) {
            $item_name = $item->get_name();
            $product_sku = $product ? $product->get_sku() : '';
            
            // Check if this is a shipping payment product
            if (strpos($item_name, 'Shipping Payment') !== false || 
                $product_sku === 'ship-your-preoder' || 
                strpos($item_name, 'Pre-Order') !== false && strpos($item_name, 'Shipping') !== false) {
                
                // For shipping payment products, try to get weight from stored metadata
                $preorder_weight = $item->get_meta('_preorder_total_weight_calculated');
                if ($preorder_weight) {
                    // Extract numeric weight from strings like "3,54 kg"
                    $weight_numeric = preg_replace('/[^0-9.,]/', '', $preorder_weight);
                    $weight_numeric = str_replace(',', '.', $weight_numeric);
                    $weight_kg = floatval($weight_numeric);
                    
                    if ($weight_kg > 0) {
                        $total_weight_kg += $weight_kg;
                        $items_count += 1; // Count as 1 shipping unit
                        wwe_ups_log("Préparation Colis ({$order_id}): Poids pré-commande récupéré - {$item_name}: {$weight_kg}kg", 'debug');
                        continue;
                    }
                }
                
                // Fallback: try to get weight from "Original Order Weight" if stored differently (HPOS compatible)
                $original_weight = wwe_ups_get_order_meta_hpos_compatible($order_id, '_original_order_weight', true);
                if ($original_weight && is_numeric($original_weight)) {
                    $total_weight_kg += floatval($original_weight);
                    $items_count += 1;
                    wwe_ups_log("Préparation Colis ({$order_id}): Poids original récupéré - {$item_name}: {$original_weight}kg", 'debug');
                    continue;
                }
                
                // If no weight found, skip but don't fail the entire calculation
                wwe_ups_log("Préparation Colis ({$order_id}): Article shipping sans poids défini ignoré - {$item_name}", 'warning');
                continue;
            }
        }

        if ($qty <= 0) {
            continue;
        }

        if ($product && $product->needs_shipping()) {
            if (!$product->has_weight()) {
                $missing_weight = true;
                wwe_ups_log("Préparation Colis ({$order_id}): Poids manquant pour {$product->get_name()} (ID: {$product->get_id()})", 'debug');
                break;
            }

            $item_weight = wc_get_weight($product->get_weight(), 'kg');
            if (false === $item_weight || !is_numeric($item_weight) || $item_weight < 0) {
                $missing_weight = true;
                wwe_ups_log("Préparation Colis ({$order_id}): Poids invalide pour {$product->get_name()} (poids: {$item_weight})", 'debug');
                break;
            }

            $total_weight_kg += floatval($item_weight) * $qty;
            $items_count     += $qty;
        }
    }

    if ($items_count === 0 || $missing_weight || $total_weight_kg <= 0) {
        wwe_ups_log("Erreur préparation colis ({$order_id}): Articles invalides ou poids manquant/nul.", 'error');
        return false;
    }

    wwe_ups_log("Préparation Colis ({$order_id}): Poids total calculé = {$total_weight_kg} kg.");

    $max_package_weight = defined('WWE_MAX_WEIGHT') ? floatval(WWE_MAX_WEIGHT) : 20.0;
    $min_package_weight = defined('WWE_MINIMUM_PACKAGE_WEIGHT') ? floatval(WWE_MINIMUM_PACKAGE_WEIGHT) : 0.1;
    $api_packages       = [];

    if ($total_weight_kg > $max_package_weight) {
        // --- Logique de Division > 20kg ---
        $num_packages     = 0;
        $remaining_weight = $total_weight_kg;

        wwe_ups_log("Préparation Colis ({$order_id}): Poids total > {$max_package_weight}kg. Division...");

        while ($remaining_weight > 0 && $num_packages < 10) { // Limite à 10 colis
            $num_packages++;
            $current_package_weight = min($remaining_weight, $max_package_weight);

            // Évite un colis résiduel plus léger que le minimum
            if (($remaining_weight - $current_package_weight) > 0 && ($remaining_weight - $current_package_weight) < $min_package_weight) {
                $current_package_weight -= $min_package_weight;
            }
            $current_package_weight = max($current_package_weight, $min_package_weight);

            // Utiliser la boîte 33x33x33cm pour tous les colis divisés
            $details = wwe_ups_get_package_details_by_weight($current_package_weight, true);
            if (!$details) {
                wwe_ups_log("Erreur Prépa Colis ({$order_id}): Détails manquants colis divisé #{$num_packages} ({$current_package_weight}kg).", 'error');
                return false;
            }

            $api_packages[] = wwe_ups_format_package_for_api($details, $order_or_package, $num_packages, 'X'); // Formatage
            $remaining_weight -= $current_package_weight;
            $remaining_weight  = max(0, $remaining_weight);
        }

        if ($num_packages > 0) {
            foreach ($api_packages as $index => &$pkg) {
                if (isset($pkg['ReferenceNumber'][0]['Value'])) {
                    $pkg['ReferenceNumber'][0]['Value'] = 'Box ' . ($index + 1) . '/' . $num_packages;
                }
            }
            unset($pkg);
        }

        wwe_ups_log("Préparation Colis ({$order_id}): Division terminée en {$num_packages} colis.");
    } else {
        // --- Cas Colis Unique ---
        $details = wwe_ups_get_package_details_by_weight($total_weight_kg, false);
        if (!$details) {
            wwe_ups_log("Erreur Prépa Colis ({$order_id}): Détails colis unique manquants ({$total_weight_kg}kg).", 'error');
            return false;
        }
        $api_packages[] = wwe_ups_format_package_for_api($details, $order_or_package); // Formatage
    }

    return $api_packages;
}

/**
 * Formate UN colis pour l'API UPS.
 *
 * @param array              $details          Détails du colis (dimensions, poids, unités)
 * @param WC_Order|array     $order_or_package Contexte initial (commande ou panier)
 * @param int|null           $pkg_index        Index du colis (pour division)
 * @param string|null        $total_pkgs       Total colis (pour division)
 *
 * @return array Données formatées prêtes pour l'API UPS.
 */
function wwe_ups_format_package_for_api($details, $order_or_package, $pkg_index = null, $total_pkgs = null) {
    $is_order     = ($order_or_package instanceof WC_Order);
    $order_number = $is_order ? $order_or_package->get_order_number() : 'Cart';

    wwe_ups_log( "wwe_ups_format_package_for_api: Formatting package for order {$order_number}, details: " . print_r($details, true) );

    $package_data = [
        'PackagingType' => ['Code' => '02'], // '02' = Customer Supplied (comme version GitHub qui fonctionnait)
        'Dimensions' => [
            'UnitOfMeasurement' => ['Code' => $details['dim_unit']],
            'Length'            => (string) $details['length'],
            'Width'             => (string) $details['width'],
            'Height'            => (string) $details['height'],
        ],
        'PackageWeight' => [
            'UnitOfMeasurement' => ['Code' => $details['weight_unit']], // KGS
            'Weight'            => (string) $details['weight'],
        ],
    ];

    // For Mexico shipments, MerchandiseDescription is required at the package level.
    // Let's try to build a sensible one from the items.
    $destination_country = null;
    
    // Try to get destination country from the order
    if ($is_order && method_exists($order_or_package, 'get_shipping_country')) {
        $destination_country = $order_or_package->get_shipping_country();
    } elseif ($is_order && method_exists($order_or_package, 'get_address')) {
        $shipping_address = $order_or_package->get_address('shipping');
        $destination_country = $shipping_address['country'] ?? null;
    } elseif (isset($details['destination_country'])) {
        $destination_country = $details['destination_country'];
    }
    
    wwe_ups_log( "wwe_ups_format_package_for_api: Detected destination country: " . ($destination_country ?: 'UNKNOWN') );
    
    if ($destination_country === 'MX') {
        wwe_ups_log( "wwe_ups_format_package_for_api: Mexico shipment detected for order {$order_number}. Attempting to add MerchandiseDescription." );
        $merch_descriptions = [];
        if ($is_order) {
            foreach ($order_or_package->get_items() as $item_id => $item) {
                $product = $item->get_product();
                if ($product) {
                    $invoice_desc = $product->get_meta('ph_ups_invoice_desc', true);
                    if (!empty($invoice_desc)) {
                        $merch_descriptions[] = $invoice_desc;
                        wwe_ups_log( "wwe_ups_format_package_for_api: Found product invoice desc: " . $invoice_desc );
                    } else {
                        $product_name = $product->get_name();
                        $merch_descriptions[] = $product_name;
                        wwe_ups_log( "wwe_ups_format_package_for_api: Using product name: " . $product_name );
                    }
                }
            }
        } elseif (isset($details['contents']) && is_array($details['contents'])) { // Cart based
            foreach ($details['contents'] as $item) {
                if (isset($item['data'])) {
                    $product = $item['data'];
                    $invoice_desc = $product->get_meta('ph_ups_invoice_desc', true);
                     if (!empty($invoice_desc)) {
                        $merch_descriptions[] = $invoice_desc;
                    } else {
                        $merch_descriptions[] = $product->get_name();
                    }
                }
            }
        }

        if (!empty($merch_descriptions)) {
            $unique_descs = array_unique($merch_descriptions);
            $package_data['Description'] = implode(', ', array_slice($unique_descs, 0, 3)); // Limit length
            if (strlen($package_data['Description']) > 35) {
                 $package_data['Description'] = substr($package_data['Description'], 0, 32) . '...';
            }
            wwe_ups_log( "wwe_ups_format_package_for_api: Added MerchandiseDescription for MX: " . $package_data['Description'] );
        } else {
            // Fallback if no item descriptions found - use vinyl record default
            $package_data['Description'] = 'Vinyl Records';
            wwe_ups_log( "wwe_ups_format_package_for_api: No specific item descriptions found for MX shipment. Using fallback: " . $package_data['Description'] );
        }
    }

    // Ajout d'un numéro de référence (max 35 caractères)
    $reference_value = 'Order ' . $order_number;
    if ($pkg_index && $total_pkgs) {
        $reference_value = 'Box ' . $pkg_index . '/' . $total_pkgs;
    }
    $package_data['ReferenceNumber'] = [[
        'Code'  => 'TN',
        'Value' => substr($reference_value, 0, 35),
    ]];

    // Ensure dimensions are valid; fallback to weight-based box if empty or zero
    foreach ( ['Length','Width','Height'] as $dim ) {
        if ( empty( $package_data['Dimensions'][ $dim ] ) || $package_data['Dimensions'][ $dim ] === '0' ) {
            $fallback = wwe_ups_get_package_details_by_weight( $details['weight'], false );
            $package_data['Dimensions']['Length'] = (string) $fallback['length'];
            $package_data['Dimensions']['Width']  = (string) $fallback['width'];
            $package_data['Dimensions']['Height'] = (string) $fallback['height'];
            break;
        }
    }
    /* --- Correctifs Packaging & poids mini --- */
    if (isset($package_data['PackageWeight']['Weight'])) {
        $w = floatval($package_data['PackageWeight']['Weight']);
        if ($w < 0.1) { $w = 0.1; }
        $package_data['PackageWeight']['Weight'] = (string) $w;
    } else {
        $package_data['PackageWeight'] = [
            'UnitOfMeasurement' => ['Code' => WWE_PACKAGE_WEIGHT_UNIT],
            'Weight'            => '0.1',
        ];
    }
    // Optionnel : ajouter le SKU du premier produit si dispo
    if ($is_order && method_exists($order_or_package, 'get_items')) {
        $items = $order_or_package->get_items();
        foreach ($items as $item) {
            $p = $item->get_product();
            if ($p && $p->get_sku()) {
                $package_data['PartNumber'] = $p->get_sku(); // ou l'ajouter dans ReferenceNumber si UPS l'accepte
                break;
            }
        }
    }
    
    // UPS API expects 'PackagingType' - ensure it's correctly set
    $packaging_code = isset($details['packaging_code']) ? $details['packaging_code'] : '02';
    $package_data['PackagingType'] = array( 'Code' => $packaging_code );
    
    // CRUCIAL: UPS Shipping API expects 'Packaging' (not 'PackagingType') - RESTORE FROM GITHUB VERSION
    if ( isset( $package_data['PackagingType'] ) ) {
        $package_data['Packaging'] = $package_data['PackagingType'];
    }

    return $package_data;
}

/**
 * Formate UN colis spécifiquement pour l'API UPS Shipment (génération d'étiquettes).
 * L'API Shipment a des exigences différentes de l'API Rate.
 *
 * @param array $package_data Données du package formatées par wwe_ups_format_package_for_api
 * @return array Données formatées pour l'API Shipment
 */
function wwe_ups_format_package_for_shipment_api($package_data) {
    // L'API Shipment UPS utilise un format légèrement différent
    $shipment_package = $package_data;
    
    // S'assurer que PackagingType est correctement formaté pour l'API Shipment
    if (isset($package_data['PackagingType']['Code'])) {
        $packaging_code = $package_data['PackagingType']['Code'];
        
        // Utiliser la description correcte selon le code
        $packaging_descriptions = [
            '01' => 'UPS Letter',
            '02' => 'Customer Supplied Package',
            '03' => 'Tube',
            '04' => 'PAK',
            '21' => 'UPS Express Box',
            '24' => 'UPS 25KG Box',
            '25' => 'UPS 10KG Box'
        ];
        
        $description = isset($packaging_descriptions[$packaging_code]) 
            ? $packaging_descriptions[$packaging_code] 
            : 'UPS Letter'; // Fallback par défaut
            
        $shipment_package['PackagingType'] = [
            'Code' => $packaging_code,
            'Description' => $description
        ];
        
        wwe_ups_log("PackagingType corrected for Shipment API: Code={$packaging_code}, Description={$description}");
    }
    
    // S'assurer que les dimensions sont des strings
    if (isset($shipment_package['Dimensions'])) {
        foreach (['Length', 'Width', 'Height'] as $dim) {
            if (isset($shipment_package['Dimensions'][$dim])) {
                $shipment_package['Dimensions'][$dim] = (string) $shipment_package['Dimensions'][$dim];
            }
        }
    }
    
    // S'assurer que le poids est une string
    if (isset($shipment_package['PackageWeight']['Weight'])) {
        $shipment_package['PackageWeight']['Weight'] = (string) $shipment_package['PackageWeight']['Weight'];
    }
    
    wwe_ups_log("Package formatted for Shipment API: " . print_r($shipment_package, true));
    
    return $shipment_package;
}

// Add more helper functions here as needed (e.g., formatting addresses, HS code retrieval)
/**
 * Valide les données produit pour l'exportation.
 * 
 * @param WC_Product $product Le produit à valider
 * @return array|WP_Error Données validées ou erreur
 */
function wwe_ups_validate_product_for_export(WC_Product $product) {
    if (!$product) {
        return new WP_Error('invalid_product', __('Produit invalide.', 'wwe-ups-woocommerce-shipping'));
    }
    
    // Vérifier le poids
    if (!$product->has_weight()) {
        return new WP_Error(
            'missing_weight',
            sprintf(__('Le produit "%s" n\'a pas de poids défini.', 'wwe-ups-woocommerce-shipping'), $product->get_name())
        );
    }
    
    $weight = wc_get_weight($product->get_weight(), 'kg');
    if (!is_numeric($weight) || $weight <= 0) {
        return new WP_Error(
            'invalid_weight',
            sprintf(__('Le produit "%s" a un poids invalide.', 'wwe-ups-woocommerce-shipping'), $product->get_name())
        );
    }
    
    // Obtenir le code HS
    $hs_code = $product->get_meta('_wps_hs_code') ?: $product->get_meta('_hs_code');
    if (!$hs_code) {
        $hs_code = get_post_meta($product->get_id(), '_wps_hs_code', true) ?: get_post_meta($product->get_id(), '_hs_code', true);
    }
    
    // Si toujours pas de code HS, utiliser la valeur par défaut
    if (!$hs_code) {
        $hs_code = defined('WWE_DEFAULT_HS_CODE') ? WWE_DEFAULT_HS_CODE : '852380';
        wwe_ups_log(sprintf(
            __('Code HS manquant pour le produit "%s", utilisation du code par défaut: %s', 'wwe-ups-woocommerce-shipping'),
            $product->get_name(),
            $hs_code
        ), 'warning');
    }
    
    // Nettoyer le code HS (enlever les points et autres caractères non numériques)
    $cleaned_hs_code = preg_replace('/[^0-9]/', '', $hs_code);
    
    // Obtenir le pays d'origine
    $origin_country = $product->get_meta('_origin_country');
    if (!$origin_country) {
        $origin_country = get_post_meta($product->get_id(), '_origin_country', true);
    }
    
    // Si toujours pas de pays d'origine, utiliser la valeur par défaut
    if (!$origin_country) {
        $origin_country = defined('WWE_DEFAULT_PRODUCT_ORIGIN_COUNTRY') ? WWE_DEFAULT_PRODUCT_ORIGIN_COUNTRY : 'FR';
    }
    
    // Vérifier que le pays d'origine est un code ISO valide
    if (strlen($origin_country) !== 2) {
        wwe_ups_log(sprintf(
            __('Code pays d\'origine invalide pour le produit "%s": %s. Utilisation de FR.', 'wwe-ups-woocommerce-shipping'),
            $product->get_name(),
            $origin_country
        ), 'warning');
        $origin_country = 'FR';
    }
    
    return [
        'weight' => $weight,
        'hs_code' => $cleaned_hs_code,
        'origin_country' => $origin_country
    ];
}
// function wwe_ups_get_product_hs_code($product) { ... }
// function wwe_ups_format_phone($phone) { ... }

// AJAX handler to reset UPS shipment data for an order, so a new label can be generated.
add_action('wp_ajax_wwe_ups_reset_shipment', 'wwe_ups_reset_shipment');
function wwe_ups_reset_shipment() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    // Verify nonce
    check_ajax_referer( 'wwe_ups_admin_nonce', 'security' );

    // Get and validate order ID
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_send_json_error( 'Invalid order ID' );
    }

    // Get order object for HPOS compatibility
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
    }

    // Remove UPS shipment metadata using HPOS-compatible methods
    $order->delete_meta_data('_wwe_ups_shipment_id');
    $order->delete_meta_data('_wwe_ups_tracking_number');
    $order->delete_meta_data('_wwe_ups_label_image_base64');
    $order->delete_meta_data('_wwe_ups_label_format');
    
    // Delete individual label meta keys
    $label_count = $order->get_meta('_wwe_ups_label_count', true);
    if ($label_count > 0) {
        for ($i = 0; $i < $label_count; $i++) {
            $order->delete_meta_data("_wwe_ups_label_{$i}");
        }
    }
    $order->delete_meta_data('_wwe_ups_label_count');
    
    // Legacy or alternate meta keys
    $order->delete_meta_data('_wwe_ups_label_data');
    $order->delete_meta_data('_wwe_ups_voided');
    
    // Save the order
    $order->save();

    // Log the reset action
    wwe_ups_log( "UPS shipment data reset for order {$order_id} using HPOS-compatible methods", 'info' );

    // Return success
    wp_send_json_success();
}
/**
 * Convertit un fichier GIF en PDF à l'aide de la bibliothèque Imagick.
 * 
 * @param string $gif_path Chemin du fichier GIF d'entrée.
 * @param string|null $output_pdf_path Chemin du fichier PDF de sortie (optionnel).
 * @return string|false Chemin du fichier PDF généré, ou false en cas d'erreur.
 */
function wwe_ups_convert_gif_to_pdf($gif_path, $output_pdf_path = null) {
    if (!extension_loaded('imagick')) {
        wwe_ups_log("Erreur : Imagick non installé sur le serveur.", 'error');
        return false;
    }

    if (!file_exists($gif_path)) {
        wwe_ups_log("Erreur : Fichier GIF introuvable - $gif_path", 'error');
        return false;
    }

    try {
        $imagick = new \Imagick();
        $imagick->readImage($gif_path);
        $imagick->setImageFormat('pdf');

        // Si aucun chemin de sortie n'est donné, utilise le même nom avec .pdf
        if (!$output_pdf_path) {
            $output_pdf_path = preg_replace('/\.gif$/i', '.pdf', $gif_path);
        }

        $imagick->writeImages($output_pdf_path, true);
        $imagick->clear();
        $imagick->destroy();

        wwe_ups_log("Conversion réussie : $gif_path → $output_pdf_path");
        return $output_pdf_path;
    } catch (Exception $e) {
        wwe_ups_log("Erreur conversion GIF→PDF : " . $e->getMessage(), 'error');
        return false;
    }
}

/**
 * After a WWE UPS tracking number is saved, push catalog and parcel data to UPS Global Access.
 */
add_action( 'update_post_meta', function( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( $meta_key !== '_wwe_ups_tracking_number' || empty( $meta_value ) ) {
        return;
    }
    
    wwe_ups_log( "🟡 Tracking number updated for order {$post_id}: {$meta_value}", 'info' );
    
    // Record the timestamp when tracking is first generated
    $order = wc_get_order($post_id);
    if ($order && !$order->get_meta('_wwe_ups_tracking_time')) {
        $order->update_meta_data('_wwe_ups_tracking_time', time());
        $order->save();
        wwe_ups_log( "📝 TIMING: Recorded tracking generation time for order {$post_id}", 'info' );
    }
    
    // Trigger the GA push action with intelligent timing
    do_action( 'yoyaku_ga_push', $post_id, $meta_value );
}, 10, 4 );

/**
 * Send SKUs and parcel details to UPS Global Access via SubmitCatalog and SubmitParcel.
 */
add_action( 'yoyaku_ga_push', function( $order_id, $tracking_number ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wwe_ups_log( "GA push aborted: invalid order {$order_id}", 'error' );
        return;
    }

    // ═══════════════════════════════════════════════════════════════
    // INTELLIGENT DELAY FOR UPS PROCESSING
    // ═══════════════════════════════════════════════════════════════
    wwe_ups_log("⏰ TIMING OPTIMIZATION: Implementing intelligent delay for UPS processing", 'info');
    
    // Check if this is a fresh tracking number (just generated)
    $tracking_meta_time = $order->get_meta('_wwe_ups_tracking_time');
    $current_time = time();
    
    if (!$tracking_meta_time) {
        // First time processing - record the time
        $order->update_meta_data('_wwe_ups_tracking_time', $current_time);
        $order->save();
        $tracking_meta_time = $current_time;
        wwe_ups_log("📝 TIMING: First processing - recorded timestamp {$current_time}", 'info');
    }
    
    $time_since_tracking = $current_time - $tracking_meta_time;
    $minimum_delay = 300; // 5 minutes minimum delay
    $recommended_delay = 420; // 7 minutes recommended delay
    
    wwe_ups_log("⏱️ TIMING ANALYSIS:", 'info');
    wwe_ups_log("   • Tracking generated at: " . date('Y-m-d H:i:s', $tracking_meta_time), 'info');
    wwe_ups_log("   • Current time: " . date('Y-m-d H:i:s', $current_time), 'info');
    wwe_ups_log("   • Time elapsed: {$time_since_tracking} seconds", 'info');
    wwe_ups_log("   • Minimum delay required: {$minimum_delay} seconds", 'info');
    wwe_ups_log("   • Recommended delay: {$recommended_delay} seconds", 'info');
    
    if ($time_since_tracking < $minimum_delay) {
        $wait_time = $minimum_delay - $time_since_tracking;
        wwe_ups_log("⏳ DELAY REQUIRED: Waiting {$wait_time} seconds for UPS to complete processing", 'info');
        wwe_ups_log("🎯 REASON: Prevents race condition between UPS label generation and i-Parcel submission", 'info');
        
        // Schedule delayed execution
        wp_schedule_single_event(time() + $wait_time, 'yoyaku_ga_push_delayed', array($order_id, $tracking_number));
        wwe_ups_log("📅 SCHEDULED: Delayed execution in {$wait_time} seconds", 'info');
        return;
    } elseif ($time_since_tracking < $recommended_delay) {
        wwe_ups_log("⚠️ TIMING WARNING: Processing after {$time_since_tracking}s (recommended: {$recommended_delay}s)", 'warning');
        wwe_ups_log("💡 SUGGESTION: Consider increasing delay for optimal results", 'warning');
    } else {
        wwe_ups_log("✅ TIMING OPTIMAL: Processing after {$time_since_tracking}s - UPS should be ready", 'info');
    }

    // Continue with existing GA push logic...
    do_action('yoyaku_ga_push_execute', $order_id, $tracking_number);
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
// DELAYED GA PUSH EXECUTION HOOK
// ═══════════════════════════════════════════════════════════════
add_action('yoyaku_ga_push_delayed', function($order_id, $tracking_number) {
    wwe_ups_log("🔄 DELAYED EXECUTION: Starting scheduled GA push for order {$order_id}", 'info');
    wwe_ups_log("📦 Tracking: {$tracking_number}", 'info');
    
    // Execute the original GA push logic with timing verification
    $order = wc_get_order($order_id);
    if (!$order) {
        wwe_ups_log("❌ DELAYED EXECUTION FAILED: Order {$order_id} not found", 'error');
        return;
    }
    
    $tracking_meta_time = $order->get_meta('_wwe_ups_tracking_time');
    $current_time = time();
    $time_elapsed = $current_time - $tracking_meta_time;
    
    wwe_ups_log("⏱️ DELAYED TIMING VERIFICATION:", 'info');
    wwe_ups_log("   • Original tracking time: " . date('Y-m-d H:i:s', $tracking_meta_time), 'info');
    wwe_ups_log("   • Current execution time: " . date('Y-m-d H:i:s', $current_time), 'info');
    wwe_ups_log("   • Total delay achieved: {$time_elapsed} seconds", 'info');
    
    // Trigger the original GA push logic (without the delay check)
    do_action('yoyaku_ga_push_execute', $order_id, $tracking_number);
}, 10, 2);

// ═══════════════════════════════════════════════════════════════
// ACTUAL GA PUSH EXECUTION (SEPARATED FROM TIMING LOGIC)
// ═══════════════════════════════════════════════════════════════
add_action('yoyaku_ga_push_execute', function($order_id, $tracking_number) {
    wwe_ups_log("🟡 GA PUSH: preparing catalog for order {$order_id} with FORCED standardized values", 'info');
    $sku_payload = [];

    // FIX FATAL ERROR: Get order object from ID
    $order = wc_get_order($order_id);
    if (!$order) {
        wwe_ups_log("❌ GA PUSH ERROR: Order {$order_id} not found", "error");
        return;
    }

    foreach ( $order->get_items() as $item ) {
        $p = $item->get_product();
        $sku_payload[] = [
            'SKU'             => 'SECONDHANDVINYL', // Force same SKU for all
            'ProductName'     => 'Second-hand vinyl records', // Force same name for all
            'HSCodeUS'        => '85238010', // Force same HS code for all
            'CountryOfOrigin' => 'FR', // Force same origin for all
            'CurrentPrice'    => 2.00, // Force same price for all
            'Weight'          => wc_get_weight( $p->get_weight(), 'lb' ), // Keep real weight
        ];
    }
    $api_handler = new WWE_UPS_API_Handler();
    wwe_ups_log("📦 Catalog payload to be submitted: " . print_r($sku_payload, true), 'info');
    $api_handler->submit_catalog( $sku_payload );
    wwe_ups_log("✅ Catalog pushed to GA", 'info');

    // 2) Build and send parcel detail
    wwe_ups_log("🟡 GA PUSH: preparing parcel for tracking {$tracking_number}", 'info');
    $items = [];
    foreach ( $order->get_items() as $item ) {

        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        // Defaults for 12" vinyl
        $def = yoyaku_default_vinyl_item();

        $qty   = max( 1, (int) $item->get_quantity() );
        $sku   = $product->get_sku() ?: $def['sku'];
        $name  = $product->get_name() ?: $def['desc'];
        $desc  = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 35 ) : substr( $name, 0, 35 );
        $org   = $product->get_meta( '_origin_country' ) ?: $def['origin'];
        $hs    = preg_replace( '/[^0-9]/', '', $product->get_meta( '_hs_code' ) ?: $def['hs'] );

        // Weight & dimensions (lbs / inches)
        $w_lbs = (float) wc_get_weight( $product->get_weight(), 'lb' );
        $l_in  = (float) wc_get_dimension( $product->get_length(), 'in' );
        $w_in  = (float) wc_get_dimension( $product->get_width(),  'in' );
        $h_in  = (float) wc_get_dimension( $product->get_height(), 'in' );

        if ( $w_lbs <= 0 ) {
            $w_lbs = $def['weight_lbs'];
        }
        if ( $l_in <= 0 ) {
            $l_in = isset( $def['dimensions']['length'] ) ? wc_get_dimension( $def['dimensions']['length'], 'in', 'cm' ) : 0;
        }
        if ( $w_in <= 0 ) {
            $w_in = isset( $def['dimensions']['width'] ) ? wc_get_dimension( $def['dimensions']['width'], 'in', 'cm' ) : 0;
        }
        if ( $h_in <= 0 ) {
            $h_in = isset( $def['dimensions']['height'] ) ? wc_get_dimension( $def['dimensions']['height'], 'in', 'cm' ) : 0;
        }

        // FORCE standardized values for ALL products (ignore real product data)
        wwe_ups_log( "GA Push: Product {$sku} - ALL VALUES FORCED for standardization" );

        $items[] = [
            'SKU'                   => 'SECONDHANDVINYL', // Force same SKU for all
            'Quantity'              => $qty, // Keep real quantity
            'ProductDescription'    => 'Second-hand vinyl records', // Force same description for all
            'CountryOfOrigin'       => 'FR', // Force same origin for all
            'HTSCode'               => '85238010', // Force same HS code for all
            'CustWeightLbs'         => $w_lbs, // Keep real weight
            'CustLengthInches'      => $l_in, // Keep real dimensions
            'CustWidthInches'       => $w_in,
            'CustHeightInches'      => $h_in,
            'OriginalPrice'         => 2.00, // Force same price for all
            'ValueCompanyCurrency'  => 2.00,
            'CompanyCurrency'       => 'USD',
            'ValueShopperCurrency'  => 2.00,
            'ShopperCurrency'       => 'USD',
        ];
    }
    $addr     = $order->get_address( 'shipping' );
    $payload  = [
        'ItemDetailsList'=> $items,
        'AddressInfo'    => [
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
            'Billing'  => null, // placeholder
        ],
        'DDP'            => false,
        'TrackByEmail'   => true,
        'Reference'      => $order->get_order_number(),
        'TrackingNumber' => $tracking_number,
    ];
    // Set Billing to Shipping address instead of stdClass
    $payload['AddressInfo']['Billing'] = $payload['AddressInfo']['Shipping'];
    // Add ControlNumber for countries requiring tax ID
    $country = $payload['AddressInfo']['Shipping']['CountryCode'];
    if ( in_array( $country, ['BR','IL','KR','RU','ZA','TW'], true ) ) {
        $tax_id = $order->get_meta('_billing_tax_id');
        $payload['AddressInfo']['Shipping']['ControlNumber'] = $tax_id;
        $payload['AddressInfo']['Billing']['ControlNumber']  = $tax_id;
    }
    // ═══════════════════════════════════════════════════════════════
    // PARCEL SUBMISSION WITH DOUBLE-CALL METHOD
    // ═══════════════════════════════════════════════════════════════
    wwe_ups_log("", 'info');
    wwe_ups_log("🚀 STARTING PARCEL SUBMISSION PROCESS", 'info');
    wwe_ups_log("📦 Order ID: {$order_id}", 'info');
    wwe_ups_log("📦 Tracking: {$tracking_number}", 'info');
    wwe_ups_log("📦 Destination: " . ($payload['AddressInfo']['Shipping']['CountryCode'] ?? 'UNKNOWN'), 'info');
    wwe_ups_log("📦 Items Count: " . count($payload['ItemDetailsList'] ?? []), 'info');
    
    // Log payload summary for debugging (without sensitive info)
    $payload_debug = $payload;
    // Mask sensitive information
    if (isset($payload_debug['AddressInfo']['Shipping']['Email'])) {
        $email = $payload_debug['AddressInfo']['Shipping']['Email'];
        $payload_debug['AddressInfo']['Shipping']['Email'] = substr($email, 0, 3) . '***@' . substr(strrchr($email, '@'), 1);
    }
    if (isset($payload_debug['AddressInfo']['Shipping']['Phone'])) {
        $phone = $payload_debug['AddressInfo']['Shipping']['Phone'];
        $payload_debug['AddressInfo']['Shipping']['Phone'] = substr($phone, 0, 3) . '***' . substr($phone, -2);
    }
    wwe_ups_log("📋 Payload Summary (sensitive data masked): " . wp_json_encode($payload_debug), 'debug');
    
    // Use the new double-call method to fix NotificationEmailSent status
    wwe_ups_log("🔄 Calling submit_parcel_with_update() method...", 'info');
    $parcel_start_time = microtime(true);
    
    $parcel_response = $api_handler->submit_parcel_with_update( $payload );
    
    $parcel_duration = round((microtime(true) - $parcel_start_time) * 1000, 2);
    
    if ( is_wp_error( $parcel_response ) ) {
        wwe_ups_log("❌ PARCEL SUBMISSION FAILED after {$parcel_duration}ms", 'error');
        wwe_ups_log("❌ Error Code: " . $parcel_response->get_error_code(), 'error');
        wwe_ups_log("❌ Error Message: " . $parcel_response->get_error_message(), 'error');
        wwe_ups_log("❌ Error Data: " . print_r($parcel_response->get_error_data(), true), 'error');
        wwe_ups_log("🛑 GA PUSH TERMINATED - Parcel submission failed", 'error');
        return; // Stop processing if parcel submission fails
    } else {
        wwe_ups_log("✅ PARCEL SUBMISSION SUCCESSFUL after {$parcel_duration}ms", 'info');
        wwe_ups_log("📊 Response Code: " . (isset($parcel_response['code']) ? $parcel_response['code'] : 'UNKNOWN'), 'info');
        
        // Log response summary (without full payload for readability)
        $response_summary = [
            'code' => $parcel_response['code'] ?? 'UNKNOWN',
            'has_body' => isset($parcel_response['body']) ? 'YES' : 'NO',
            'body_keys' => isset($parcel_response['body']) ? array_keys($parcel_response['body']) : []
        ];
        wwe_ups_log("📊 Response Summary: " . wp_json_encode($response_summary), 'info');
        wwe_ups_log("📋 Full Response (debug): " . print_r($parcel_response, true), 'debug');
        
        wwe_ups_log("🎯 EXPECTED RESULT: Check UPS Global Access portal for 'Submitted' status", 'info');
    }

    // 3) NOUVEAU: Process customs documents immediately after Global Access
    wwe_ups_log("🟡 GA PUSH: Processing customs documents for synchronization", 'info');
    
    // Check if order is international and needs customs documents
    $destination_country = $order->get_shipping_country();
    $origin_country = 'FR'; // Your origin country
    
    if ($destination_country !== $origin_country) {
        // This is an international shipment - process customs documents
        try {
            $customs_result = $api_handler->submit_complete_customs_documents($order, $tracking_number);
            
            if (is_wp_error($customs_result)) {
                wwe_ups_log("⚠️ GA PUSH: Customs documents failed but continuing - " . $customs_result->get_error_message(), 'warning');
            } else {
                wwe_ups_log("✅ GA PUSH: Customs documents processed successfully", 'info');
                
                // Mark order as having customs documents processed
                $order->update_meta_data('_wwe_customs_processed', 'yes');
                $order->update_meta_data('_wwe_customs_processed_date', current_time('mysql'));
                $order->save();
            }
        } catch (Exception $e) {
            wwe_ups_log("⚠️ GA PUSH: Customs documents exception but continuing - " . $e->getMessage(), 'warning');
        }
    } else {
        wwe_ups_log("ℹ️ GA PUSH: Domestic shipment - no customs documents needed", 'info');
    }
    
    wwe_ups_log("🎉 GA PUSH: Complete workflow finished for order {$order_id}", 'info');
}, 10, 2 );

/**
 * SOLUTION UNIFIÉE - Calcul centralisé des tarifs WWE negotiated
 * Utilisé à la fois par le front office et l'admin pour éviter les incohérences
 * 
 * @param array $api_packages Array of packages from wwe_ups_prepare_api_packages_for_request()
 * @param string $destination_country Country code (US, CA, MX, etc.)
 * @param float $total_weight_kg Total weight in kg (optional, calculated if not provided)
 * @param string $context 'frontend' or 'admin' for logging purposes
 * @return float Total shipping cost in EUR
 */
function wwe_ups_calculate_unified_negotiated_rate($api_packages, $destination_country, $total_weight_kg = null, $context = 'unknown') {
    if (empty($api_packages) || !is_array($api_packages)) {
        wwe_ups_log("UNIFIED RATE ERROR: Invalid api_packages parameter", 'error');
        return 0.0;
    }
    
    $num_packages = count($api_packages);
    
    // Calculate total weight if not provided
    if ($total_weight_kg === null) {
        $total_weight_kg = 0;
        foreach ($api_packages as $package) {
            if (isset($package['PackageWeight']['Weight'])) {
                $total_weight_kg += (float) $package['PackageWeight']['Weight'];
            }
        }
    }
    
    // UNIFIED RATE STRUCTURE - Based on your actual WWE contract
    // These rates should match your real negotiated rates with UPS
    $rate_structure = wwe_ups_get_rate_structure_by_country($destination_country);
    
    // Base calculation: base rate + weight-based cost
    $base_cost = $rate_structure['base_rate'];
    $weight_cost = $total_weight_kg * $rate_structure['per_kg_rate'];
    
    // Additional packages cost (if multiple packages)
    $additional_packages_cost = 0;
    if ($num_packages > 1) {
        $additional_packages_cost = ($num_packages - 1) * $rate_structure['additional_package_rate'];
    }
    
    // Calculate total
    $total_cost = $base_cost + $weight_cost + $additional_packages_cost;
    
    // Apply minimum cost
    $total_cost = max($total_cost, $rate_structure['minimum_cost']);
    
    // Apply maximum cost (safety net)
    if (isset($rate_structure['maximum_cost']) && $total_cost > $rate_structure['maximum_cost']) {
        $total_cost = $rate_structure['maximum_cost'];
        wwe_ups_log("UNIFIED RATE WARNING: Cost capped at maximum for {$destination_country}", 'warning');
    }
    
    // Detailed logging for audit trail
    wwe_ups_log("UNIFIED WWE RATE [{$context}] - Country: {$destination_country}, Packages: {$num_packages}, Weight: {$total_weight_kg}kg", 'info');
    wwe_ups_log("UNIFIED WWE RATE [{$context}] - Breakdown: Base €{$base_cost} + Weight €{$weight_cost} ({$total_weight_kg}kg × €{$rate_structure['per_kg_rate']}) + Additional €{$additional_packages_cost} = €{$total_cost}", 'info');
    
    return $total_cost;
}

/**
 * Get rate structure by destination country
 * Centralized rate configuration - modify here to change all rates
 * 
 * @param string $country_code ISO country code
 * @return array Rate structure with base_rate, per_kg_rate, etc.
 */
function wwe_ups_get_rate_structure_by_country($country_code) {
    // WWE NEGOTIATED RATES - UPDATE THESE WITH YOUR ACTUAL CONTRACT RATES
    $rate_structures = [
        // ZONE 1 - United States (your main market)
        'US' => [
            'base_rate' => 18.00,              // €18 base rate for first package
            'per_kg_rate' => 1.50,             // €1.50 per kg
            'additional_package_rate' => 8.00, // €8 per additional package
            'minimum_cost' => 18.00,           // €18 minimum
            'maximum_cost' => 80.00,           // €80 maximum (safety)
        ],
        
        // ZONE 2 - North America neighbors
        'CA' => [
            'base_rate' => 20.00,
            'per_kg_rate' => 1.60,
            'additional_package_rate' => 9.00,
            'minimum_cost' => 20.00,
            'maximum_cost' => 85.00,
        ],
        'MX' => [
            'base_rate' => 22.00,
            'per_kg_rate' => 1.70,
            'additional_package_rate' => 10.00,
            'minimum_cost' => 22.00,
            'maximum_cost' => 90.00,
        ],
        
        // ZONE 3 - South America (competitive rates for your vinyl market)
        'BR' => [
            'base_rate' => 16.00,
            'per_kg_rate' => 1.40,
            'additional_package_rate' => 7.00,
            'minimum_cost' => 16.00,
            'maximum_cost' => 75.00,
        ],
        'AR' => [
            'base_rate' => 17.00,
            'per_kg_rate' => 1.45,
            'additional_package_rate' => 7.50,
            'minimum_cost' => 17.00,
            'maximum_cost' => 75.00,
        ],
        'PE' => [
            'base_rate' => 15.00,
            'per_kg_rate' => 1.35,
            'additional_package_rate' => 6.50,
            'minimum_cost' => 15.00,
            'maximum_cost' => 70.00,
        ],
        'CL' => [
            'base_rate' => 17.50,
            'per_kg_rate' => 1.50,
            'additional_package_rate' => 7.50,
            'minimum_cost' => 17.50,
            'maximum_cost' => 75.00,
        ],
        'CO' => [
            'base_rate' => 16.50,
            'per_kg_rate' => 1.40,
            'additional_package_rate' => 7.00,
            'minimum_cost' => 16.50,
            'maximum_cost' => 75.00,
        ],
        
        // ZONE 4 - Rest of World (higher rates)
        'DEFAULT' => [
            'base_rate' => 25.00,
            'per_kg_rate' => 2.00,
            'additional_package_rate' => 12.00,
            'minimum_cost' => 25.00,
            'maximum_cost' => 100.00,
        ]
    ];
    
    // Return specific country rates or default
    if (isset($rate_structures[$country_code])) {
        $structure = $rate_structures[$country_code];
        $structure['zone'] = $country_code;
    } else {
        $structure = $rate_structures['DEFAULT'];
        $structure['zone'] = 'DEFAULT';
        wwe_ups_log("UNIFIED RATE: Using DEFAULT rates for unknown country: {$country_code}", 'info');
    }
    
    return $structure;
}

// Default flat fee (in EUR) for WWE methods, override by defining WWE_FLAT_FEE elsewhere
if ( ! defined( 'WWE_FLAT_FEE' ) ) {
    define( 'WWE_FLAT_FEE', 0.00 );
}

/**
 * Add flat fee to all WWE shipping method costs.
 */
add_filter( 'woocommerce_package_rates', 'wwe_add_flat_fee', 10, 2 );
function wwe_add_flat_fee( $rates, $package ) {
    foreach ( $rates as $rate_id => $rate ) {
        if ( strpos( $rate->method_id, 'wwe_' ) === 0 ) {
            $rates[ $rate_id ]->cost += WWE_FLAT_FEE;
        }
    }
    return $rates;
}

/**
 * Append an "Est delivery" line under WWE method labels with dynamic dates.
 */
add_filter( 'woocommerce_cart_shipping_method_full_label', 'wwe_append_delivery_estimate', 10, 2 );
function wwe_append_delivery_estimate( $label, $method ) {
    if ( strpos( $method->id, 'wwe_' ) === 0 ) {
        // calculate dates
        $date1 = date_i18n( 'd/m/Y', strtotime( '+3 days' ) );
        $date2 = date_i18n( 'd/m/Y', strtotime( '+8 days' ) );
        // build estimate string
        $estimate = sprintf( __( 'Est delivery : between %s and %s', 'wwe-ups-woocommerce-shipping' ), $date1, $date2 );
        // append under the method label
        $label .= '<br><small>' . esc_html( $estimate ) . '</small>';
    }
    return $label;
}

/**
 * Convert a monetary amount to USD for i-parcel.
 *
 * @param float  $amount        Amount to convert.
 * @param string $from_currency Original currency code.
 * @return float Converted amount in USD.
 */
function wwe_iparcel_convert_to_usd( $amount, $from_currency ) {
    $amount        = (float) $amount;
    $from_currency = strtoupper( (string) $from_currency );

    if ( 'USD' === $from_currency ) {
        return $amount;
    }

    $rates = apply_filters( 'wwe_iparcel_currency_rates_to_usd', [
        'EUR' => 1.08,
        'GBP' => 1.27,
    ] );

    if ( isset( $rates[ $from_currency ] ) && is_numeric( $rates[ $from_currency ] ) && $rates[ $from_currency ] > 0 ) {
        return round( $amount * $rates[ $from_currency ], 2 );
    }

    $fallback = defined( 'YY_UPS_PRICE_FALLBACK' ) ? (float) YY_UPS_PRICE_FALLBACK : 10.0;
    $log      = sprintf( 'WWE i-Parcel Conversion Error: Missing rate for %s, using fallback %.2f USD', $from_currency, $fallback );

    if ( function_exists( 'wwe_ups_log' ) ) {
        wwe_ups_log( $log, 'warning' );
    } elseif ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->warning( $log, [ 'source' => 'wwe-iparcel' ] );
    }

    return $fallback;
}

/**
 * Convert a WC_Order_Item_Product to the format expected by i-parcel.
 *
 * @param WC_Order_Item_Product $order_item    Order item object.
 * @param string                $order_currency Currency of the order.
 * @return array|WP_Error Array ready for ItemDetailsList or WP_Error on failure.
 */
function wwe_convert_item_for_iparcel( WC_Order_Item_Product $order_item, $order_currency ) {
    $product = $order_item->get_product();
    $qty     = $order_item->get_quantity();

    if ( ! $product ) {
        wwe_ups_log( "wwe_convert_item_for_iparcel: Product not found for order item ID: " . $order_item->get_id() );
        return new WP_Error( 'iparcel_item_error', __( 'Product not found for order item.', 'wwe-ups-woocommerce-shipping' ) );
    }

    $product_id = $product->get_id();
    wwe_ups_log( "wwe_convert_item_for_iparcel: Processing product ID: {$product_id}, Name: " . $product->get_name() );

    // FORCE standardized values for ALL products (ignore real product data)
    $item_description = 'Second-hand vinyl records'; // Always the same
    $country_of_origin = 'FR'; // Always France
    $hs_code = '85238010'; // Always the same HS code
    $item_sku = 'SECONDHANDVINYL'; // Always the same SKU

    wwe_ups_log( "wwe_convert_item_for_iparcel: Product ID {$product_id} - ALL VALUES FORCED for standardization" );

    $iparcel_item = [
        'ItemID'        => $item_sku, // Force same SKU for all
        'Description'   => $item_description, // Force same description for all
        'CustomsValue'  => '2.00', // Force same price for all
        'Quantity'      => $order_item->get_quantity(), // Keep real quantity
        'CountryOfOrigin' => $country_of_origin, // Force same origin for all
        'HSCode'        => $hs_code, // Force same HS code for all
    ];

    wwe_ups_log( "wwe_convert_item_for_iparcel: Prepared iParcel data for product ID {$product_id}: " . print_r($iparcel_item, true) );

    // Validate required fields before returning
    $required_fields = ['ItemID', 'Description', 'CustomsValue', 'Quantity', 'CountryOfOrigin', 'HSCode'];
    foreach ($required_fields as $field) {
        if (empty($iparcel_item[$field])) {
            $error_message = sprintf(
                // translators: %1$s: Product Name, %2$s: Missing Field, %3$s: Product ID
                __( 'Missing required iParcel field for product "%1$s" (ID: %3$s). Field "%2$s" is empty.', 'wwe-ups-woocommerce-shipping' ),
                $product->get_name(),
                $field,
                $product_id
            );
            wwe_ups_log( "wwe_convert_item_for_iparcel: ERROR - {$error_message}" );
            return new WP_Error('iparcel_item_error', $error_message);
        }
    }

    return $iparcel_item;
}
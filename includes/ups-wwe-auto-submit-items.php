<?php
/**
 * UPS WWE Auto Submit Items Script
 * Automatise la soumission des informations produits pour passer du statut "New" à "ItemsProvidedByMerchant"
 */

if (!defined('ABSPATH')) {
    exit;
}

class UPS_WWE_Auto_Submit_Items {
    
    private $api_endpoint = 'https://globalaccess.wweconomy.ups.com/MissingCommodityLoadParcels';
    private $login_endpoint = 'https://globalaccess.wweconomy.ups.com/Account/Login';
    
    public function __construct() {
        add_action('wp_ajax_ups_wwe_auto_submit_items', array($this, 'ajax_auto_submit_items'));
        add_action('wp_ajax_ups_wwe_get_pending_tracking', array($this, 'ajax_get_pending_tracking'));
        add_action('admin_notices', array($this, 'show_admin_notice'));
    }
    
    /**
     * Récupère tous les tracking numbers avec statut "New" depuis aujourd'hui
     */
    public function get_pending_tracking_numbers() {
        global $wpdb;
        
        // Récupérer tous les tracking numbers d'aujourd'hui
        $tracking_numbers = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT meta_value as tracking_number, order_id
            FROM wp_wc_orders_meta 
            WHERE meta_key = '_wwe_ups_tracking_number' 
            AND order_id IN (
                SELECT id FROM wp_wc_orders 
                WHERE date_created_gmt >= %s
            )
            ORDER BY order_id DESC
        ", date('Y-m-d 00:00:00')));
        
        return $tracking_numbers;
    }
    
    /**
     * Récupère les informations produit d'une commande pour UPS WWE
     */
    public function get_order_items_for_ups($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Utiliser les valeurs standardisées comme dans le plugin
            $items[] = array(
                'Country' => 'FR',
                'ItemDescription' => 'Second-hand vinyl records',
                'HsCode' => '85238010',
                'Quantity' => $item->get_quantity(),
                'Sku' => 'SECONDHANDVINYL',
                'Value' => 2.00,
                'Currency' => 'EUR'
            );
        }
        
        return $items;
    }
    
    /**
     * Récupère les informations destinataire d'une commande
     */
    public function get_consignee_info($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $shipping_address = $order->get_address('shipping');
        
        return array(
            'Name' => $shipping_address['first_name'] . ' ' . $shipping_address['last_name'],
            'Street' => $shipping_address['address_1'],
            'City' => $shipping_address['city'],
            'Country' => $shipping_address['country'],
            'PostCode' => $shipping_address['postcode'],
            'Phone' => $order->get_billing_phone(),
            'Email' => $order->get_billing_email()
        );
    }
    
    /**
     * Simule la soumission d'informations produit à UPS WWE
     */
    public function submit_items_to_ups($tracking_number, $order_id) {
        $items = $this->get_order_items_for_ups($order_id);
        $consignee = $this->get_consignee_info($order_id);
        
        if (!$items || !$consignee) {
            return array('success' => false, 'message' => 'Impossible de récupérer les informations de la commande');
        }
        
        // Payload pour l'API UPS WWE
        $payload = array(
            'TrackingNumber' => $tracking_number,
            'ConsigneeInformation' => $consignee,
            'Items' => $items
        );
        
        if (function_exists('wwe_ups_log')) {
            wwe_ups_log("🔄 AUTO SUBMIT: Tentative soumission pour tracking {$tracking_number}");
            wwe_ups_log("📦 Payload: " . json_encode($payload, JSON_PRETTY_PRINT));
        }
        
        // Simuler l'appel API (à adapter selon l'API UPS WWE réelle)
        $response = $this->call_ups_api($payload);
        
        if ($response['success']) {
            // Marquer comme soumis dans les métadonnées
            $order = wc_get_order($order_id);
            $order->update_meta_data('_wwe_ups_items_submitted', 'yes');
            $order->update_meta_data('_wwe_ups_items_submitted_date', current_time('mysql'));
            $order->save();
            
            if (function_exists('wwe_ups_log')) {
                wwe_ups_log("✅ AUTO SUBMIT: Succès pour tracking {$tracking_number}");
            }
        } else {
            if (function_exists('wwe_ups_log')) {
                wwe_ups_log("❌ AUTO SUBMIT: Échec pour tracking {$tracking_number} - " . $response['message']);
            }
        }
        
        return $response;
    }
    
    /**
     * Appel API UPS WWE (simulation)
     */
    private function call_ups_api($payload) {
        // TODO: Implémenter le vrai appel API UPS WWE
        // Pour l'instant, simulation du succès
        
        $response = wp_remote_post($this->api_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'YOYAKU-WWE-Auto-Submit/1.0'
            ),
            'body' => json_encode($payload)
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => 'Erreur de connexion: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            return array('success' => true, 'message' => 'Informations soumises avec succès');
        } else {
            return array(
                'success' => false, 
                'message' => "Erreur API: HTTP {$status_code} - {$body}"
            );
        }
    }
    
    /**
     * Traite tous les tracking numbers en attente
     */
    public function process_all_pending() {
        $pending_tracking = $this->get_pending_tracking_numbers();
        $results = array();
        
        foreach ($pending_tracking as $tracking) {
            $result = $this->submit_items_to_ups($tracking->tracking_number, $tracking->order_id);
            $results[] = array(
                'tracking_number' => $tracking->tracking_number,
                'order_id' => $tracking->order_id,
                'success' => $result['success'],
                'message' => $result['message']
            );
        }
        
        return $results;
    }
}

// Initialiser le système
new UPS_WWE_Auto_Submit_Items();

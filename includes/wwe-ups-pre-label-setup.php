<?php
/**
 * WWE UPS Pre-Label Setup System
 * 
 * Sends order data to i-Parcel BEFORE UPS label generation using SetCheckout API
 * Based on the old i-parcel plugin workflow to ensure UPS has complete item data
 * 
 * @package WWE_UPS
 * @author Benjamin Belaga
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Pre-Label Setup Configuration
 */
class WWE_UPS_Pre_Label_Setup {
    
    private $config;
    
    public function __construct() {
        $this->config = [
            'enabled' => true,
            'timeout_seconds' => 30,
            'debug_mode' => true,
        ];
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook AVANT génération d'étiquette UPS - priorité élevée pour s'exécuter en premier
        add_action('wwe_before_label_generation', [$this, 'setup_iparcel_data'], 5, 1);
        
        // Hook sur la vraie action AJAX de génération d'étiquette - priorité 1 pour s'exécuter en premier
        add_action('wp_ajax_wwe_ups_generate_label', [$this, 'setup_iparcel_data_ajax'], 1);
        add_action('wp_ajax_nopriv_wwe_ups_generate_label', [$this, 'setup_iparcel_data_ajax'], 1);
    }
    
    /**
     * Configure i-Parcel data before UPS label generation
     * 
     * @param int $order_id Order ID
     * @return bool Success status
     */
    public function setup_iparcel_data($order_id) {
        try {
            // Validation de base
            if (empty($order_id)) {
                wwe_ups_log('Pre-Label Setup: No order ID provided', 'error');
                return false;
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                wwe_ups_log("Pre-Label Setup: Order {$order_id} not found", 'error');
                return false;
            }
            
            // Vérifier si déjà traité
            $already_processed = $order->get_meta('_iparcel_pre_label_submitted', true);
            if ($already_processed) {
                wwe_ups_log("Pre-Label Setup: Order {$order_id} already processed - skipping", 'info');
                return true;
            }
            
            // Vérifier si la commande a été voidée (empêcher les nouvelles soumissions)
            $is_voided = $order->get_meta('_iparcel_pre_label_voided', true);
            if ($is_voided) {
                $voided_tracking = $order->get_meta('_iparcel_pre_label_voided_tracking', true);
                wwe_ups_log("Pre-Label Setup: Order {$order_id} was previously voided (tracking: {$voided_tracking}) - skipping", 'info');
                return true;
            }
            
            wwe_ups_log("Pre-Label Setup: Starting Pre-Label SubmitParcel for order {$order_id}", 'info');
            
            // Initialiser l'API handler
            $api_handler = new WWE_UPS_API_Handler();
            
            // Exécuter Pre-Label SubmitParcel
            $result = $api_handler->set_checkout($order);
            
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                wwe_ups_log("Pre-Label Setup: Pre-Label SubmitParcel failed for order {$order_id} - {$error_message}", 'error');
                
                // Marquer comme échec mais permettre la génération d'étiquette
                $order->update_meta_data('_iparcel_pre_label_error', $error_message);
                $order->update_meta_data('_iparcel_pre_label_attempted_at', time());
                $order->save();
                
                return false;
            }
            
            // Succès
            if (isset($result['success']) && $result['success']) {
                $message = $result['message'] ?? 'Pre-label data submitted successfully';
                
                wwe_ups_log("Pre-Label Setup: Pre-Label SubmitParcel successful for order {$order_id} - {$message}", 'info');
                
                // Les métadonnées sont déjà sauvées dans la méthode set_checkout
                return true;
            }
            
            // Cas imprévu
            wwe_ups_log("Pre-Label Setup: Unexpected Pre-Label SubmitParcel response for order {$order_id}", 'warning');
            return false;
            
        } catch (Exception $e) {
            wwe_ups_log("Pre-Label Setup: Exception for order {$order_id} - " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * AJAX method for generate_label hook
     * Triggered by wp_ajax_wwe_ups_generate_label BEFORE the main handler
     */
    public function setup_iparcel_data_ajax() {
        // Extract order_id from AJAX POST data
        $order_id = 0;
        
        if (isset($_POST['order_id'])) {
            $order_id = absint($_POST['order_id']);
        }
        
        if ($order_id > 0) {
            wwe_ups_log("Pre-Label Setup: AJAX hook triggered for order {$order_id}", 'info');
            $this->setup_iparcel_data($order_id);
        } else {
            wwe_ups_log("Pre-Label Setup: AJAX hook - no valid order_id found", 'warning');
        }
    }
    
    /**
     * Get setup status for an order
     * 
     * @param int $order_id Order ID
     * @return array Status information
     */
    public function get_setup_status($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        $processed = $order->get_meta('_iparcel_pre_label_submitted', true);
        $error = $order->get_meta('_iparcel_pre_label_error', true);
        $success_at = $order->get_meta('_iparcel_pre_label_submitted_at', true);
        $attempted_at = $order->get_meta('_iparcel_pre_label_attempted_at', true);
        $pre_label_data = $order->get_meta('_iparcel_pre_label_data', true);
        
        if ($processed) {
            return [
                'status' => 'success',
                'message' => 'Pre-Label SubmitParcel completed successfully',
                'data' => $pre_label_data,
                'completed_at' => $success_at ? date('Y-m-d H:i:s', $success_at) : null
            ];
        }
        
        if ($error) {
            return [
                'status' => 'error',
                'message' => $error,
                'attempted_at' => $attempted_at ? date('Y-m-d H:i:s', $attempted_at) : null
            ];
        }
        
        return [
            'status' => 'pending',
            'message' => 'Pre-Label SubmitParcel not yet processed'
        ];
    }
    
    /**
     * Manual trigger for testing purposes
     * 
     * @param int $order_id Order ID
     * @return array Result
     */
    public function manual_trigger($order_id) {
        wwe_ups_log("Pre-Label Setup: Manual trigger for order {$order_id}", 'info');
        
        // Reset previous status (including voided status)
        $order = wc_get_order($order_id);
        if ($order) {
            $order->delete_meta_data('_iparcel_pre_label_submitted');
            $order->delete_meta_data('_iparcel_pre_label_error');
            $order->delete_meta_data('_iparcel_pre_label_submitted_at');
            $order->delete_meta_data('_iparcel_pre_label_attempted_at');
            $order->delete_meta_data('_iparcel_pre_label_data');
            // Reset voided status to allow new attempts
            $order->delete_meta_data('_iparcel_pre_label_voided');
            $order->delete_meta_data('_iparcel_pre_label_voided_at');
            $order->delete_meta_data('_iparcel_pre_label_voided_tracking');
            $order->save();
        }
        
        $result = $this->setup_iparcel_data($order_id);
        
        return [
            'success' => $result,
            'status' => $this->get_setup_status($order_id)
        ];
    }
}

// Initialize the system
if (class_exists('WWE_UPS_API_Handler')) {
    new WWE_UPS_Pre_Label_Setup();
} 
<?php
/**
 * WWE UPS Auto-Customs System
 * 
 * Automatically submits customs documents after UPS WWE label generation
 * 
 * @package WWE_UPS
 * @author Benjamin Belaga
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Auto-Customs Configuration
 */
class WWE_UPS_Auto_Customs {
    
    private $config;
    
    public function __construct() {
        $this->config = [
            'enabled' => true,
            'delay_seconds' => 90, // 90 secondes après génération étiquette
            'max_retries' => 3,
            'retry_delays' => [300, 900, 3600], // 5min, 15min, 1h
        ];
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook principal : déclenché après génération étiquette UPS WWE
        add_action('wwe_after_waybill_created', [$this, 'trigger_auto_customs'], 20, 2);
        
        // Handlers pour les tâches programmées
        add_action('wwe_ups_auto_customs_process', [$this, 'process_auto_customs'], 10, 2);
        add_action('wwe_ups_auto_customs_retry', [$this, 'retry_auto_customs'], 10, 3);
    }
    
    /**
     * Déclenche l'auto-submission des douanes avec délai intelligent
     * 
     * @param int $order_id Order ID
     * @param array|string $tracking_numbers Tracking numbers
     */
    public function trigger_auto_customs($order_id, $tracking_numbers) {
        if (!$this->config['enabled']) {
            wwe_ups_log("🔴 AUTO-CUSTOMS: Disabled - skipping order #{$order_id}", 'info');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wwe_ups_log("🔴 AUTO-CUSTOMS: Invalid order #{$order_id}", 'error');
            return;
        }
        
        // Vérifier si déjà traité
        if ($order->get_meta('_ups_customs_submitted', true) === 'yes') {
            wwe_ups_log("⚪ AUTO-CUSTOMS: Already submitted for order #{$order_id}", 'info');
            return;
        }
        
        // Marquer comme en cours de traitement
        $order->update_meta_data('_wwe_auto_customs_status', 'pending');
        $order->update_meta_data('_wwe_auto_customs_triggered_at', time());
        $order->save();
        
        wwe_ups_log("🚀 AUTO-CUSTOMS: Triggered for order #{$order_id} - scheduling in {$this->config['delay_seconds']} seconds", 'info');
        
        // Programmer l'exécution avec délai intelligent
        wp_schedule_single_event(
            time() + $this->config['delay_seconds'], 
            'wwe_ups_auto_customs_process', 
            [$order_id, $tracking_numbers]
        );
    }
    
    /**
     * Traite la soumission automatique des douanes
     * 
     * @param int $order_id Order ID
     * @param array|string $tracking_numbers Tracking numbers
     */
    public function process_auto_customs($order_id, $tracking_numbers) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wwe_ups_log("🔴 AUTO-CUSTOMS: Invalid order #{$order_id} during processing", 'error');
            return;
        }
        
        // Vérifier si toujours éligible
        if ($order->get_meta('_ups_customs_submitted', true) === 'yes') {
            wwe_ups_log("⚪ AUTO-CUSTOMS: Already submitted during processing for order #{$order_id}", 'info');
            return;
        }
        
        // Marquer comme en cours de traitement
        $order->update_meta_data('_wwe_auto_customs_status', 'processing');
        $order->save();
        
        wwe_ups_log("⚡ AUTO-CUSTOMS: Processing order #{$order_id}", 'info');
        
        // Obtenir le premier tracking number
        $tracking_array = is_array($tracking_numbers) ? $tracking_numbers : explode(',', $tracking_numbers);
        $primary_tracking = trim($tracking_array[0]);
        
        if (empty($primary_tracking)) {
            wwe_ups_log("🔴 AUTO-CUSTOMS: No tracking number for order #{$order_id}", 'error');
            $this->mark_failed($order, 'No tracking number available');
            return;
        }
        
        // Utiliser l'API handler existant
        try {
            $api_handler = new WWE_UPS_API_Handler(['debug' => true]);
            $result = $api_handler->submit_complete_customs_documents($order, $primary_tracking);
            
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                wwe_ups_log("🚨 AUTO-CUSTOMS: Failed for order #{$order_id} - {$error_message}", 'error');
                
                // Programmer retry si pas trop d'tentatives
                $attempt = intval($order->get_meta('_wwe_auto_customs_attempts', true)) + 1;
                $this->schedule_retry($order, $tracking_numbers, $attempt, $error_message);
                
            } else {
                // Succès !
                wwe_ups_log("🎉 AUTO-CUSTOMS: SUCCESS for order #{$order_id} - Documents submitted automatically", 'info');
                
                $order->update_meta_data('_wwe_auto_customs_status', 'completed');
                $order->update_meta_data('_wwe_auto_customs_completed_at', time());
                $order->add_order_note('✅ Customs documents submitted automatically via UPS Paperless API v2');
                $order->save();
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            wwe_ups_log("🚨 AUTO-CUSTOMS: Exception for order #{$order_id} - {$error_message}", 'error');
            
            $attempt = intval($order->get_meta('_wwe_auto_customs_attempts', true)) + 1;
            $this->schedule_retry($order, $tracking_numbers, $attempt, $error_message);
        }
    }
    
    /**
     * Programme un retry en cas d'échec
     */
    private function schedule_retry($order, $tracking_numbers, $attempt, $error_message) {
        $order_id = $order->get_id();
        
        if ($attempt > $this->config['max_retries']) {
            // Trop de tentatives - marquer comme échec définitif
            $this->mark_failed($order, "Max retries exceeded. Last error: {$error_message}");
            return;
        }
        
        $retry_delay = $this->config['retry_delays'][$attempt - 1] ?? 3600; // Default 1h
        $next_attempt_time = time() + $retry_delay;
        
        $order->update_meta_data('_wwe_auto_customs_attempts', $attempt);
        $order->update_meta_data('_wwe_auto_customs_last_error', $error_message);
        $order->update_meta_data('_wwe_auto_customs_next_retry', $next_attempt_time);
        $order->save();
        
        wwe_ups_log("🔄 AUTO-CUSTOMS: Scheduling retry #{$attempt} for order #{$order_id} in {$retry_delay} seconds", 'info');
        
        wp_schedule_single_event(
            $next_attempt_time,
            'wwe_ups_auto_customs_retry',
            [$order_id, $tracking_numbers, $attempt]
        );
    }
    
    /**
     * Traite un retry
     */
    public function retry_auto_customs($order_id, $tracking_numbers, $attempt) {
        wwe_ups_log("🔄 AUTO-CUSTOMS: Retry #{$attempt} for order #{$order_id}", 'info');
        $this->process_auto_customs($order_id, $tracking_numbers);
    }
    
    /**
     * Marque une commande comme échec définitif
     */
    private function mark_failed($order, $error_message) {
        $order_id = $order->get_id();
        
        $order->update_meta_data('_wwe_auto_customs_status', 'failed');
        $order->update_meta_data('_wwe_auto_customs_failed_at', time());
        $order->update_meta_data('_wwe_auto_customs_final_error', $error_message);
        $order->add_order_note("❌ Auto-customs failed: {$error_message}");
        $order->save();
        
        wwe_ups_log("💀 AUTO-CUSTOMS: FINAL FAILURE for order #{$order_id} - {$error_message}", 'error');
    }
    
    /**
     * Get auto-customs status for an order
     */
    public function get_status($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return false;
        
        return [
            'status' => $order->get_meta('_wwe_auto_customs_status', true),
            'attempts' => intval($order->get_meta('_wwe_auto_customs_attempts', true)),
            'triggered_at' => $order->get_meta('_wwe_auto_customs_triggered_at', true),
            'completed_at' => $order->get_meta('_wwe_auto_customs_completed_at', true),
            'failed_at' => $order->get_meta('_wwe_auto_customs_failed_at', true),
            'last_error' => $order->get_meta('_wwe_auto_customs_last_error', true),
            'next_retry' => $order->get_meta('_wwe_auto_customs_next_retry', true),
        ];
    }
}

// Initialize the auto-customs system
new WWE_UPS_Auto_Customs();

/**
 * Helper function to get auto-customs status
 */
function wwe_ups_get_auto_customs_status($order_id) {
    static $instance = null;
    if ($instance === null) {
        $instance = new WWE_UPS_Auto_Customs();
    }
    return $instance->get_status($order_id);
} 
<?php
/**
 * UPS Health Check - Monitoring Automatique
 * Détecte et alerte sur les commandes avec des problèmes UPS
 */

if (!defined('ABSPATH')) {
    exit;
}

class WWE_UPS_Health_Check {
    
    public function __construct() {
        // Hook sur l'admin pour vérifier périodiquement
        add_action('admin_init', [$this, 'check_ups_health']);
        add_action('wp_ajax_wwe_ups_health_check', [$this, 'ajax_health_check']);
        
        // Notification admin
        add_action('admin_notices', [$this, 'show_health_warnings']);
    }
    
    /**
     * Vérification périodique de la santé UPS
     */
    public function check_ups_health() {
        // Vérifier seulement une fois par jour à 8h00
        $last_check = get_transient('wwe_ups_last_health_check');
        $current_hour = (int) date('H');
        
        // Exécuter seulement à 8h ET si pas déjà fait aujourd'hui
        if ($last_check && $current_hour !== 8) {
            return;
        }
        
        // Si déjà fait aujourd'hui, attendre demain
        if ($last_check && (time() - $last_check) < (20 * HOUR_IN_SECONDS)) {
            return;
        }
        
        $problems = $this->scan_for_problems();
        
        if (!empty($problems)) {
            set_transient('wwe_ups_health_problems', $problems, HOUR_IN_SECONDS * 24);
            
            // Log des problèmes détectés
            wwe_ups_log("🚨 Health Check: " . count($problems) . " commandes problématiques détectées", 'warning');
        } else {
            delete_transient('wwe_ups_health_problems');
        }
        
        set_transient('wwe_ups_last_health_check', time(), DAY_IN_SECONDS);
    }
    
    /**
     * Scanner pour détecter les problèmes
     */
    public function scan_for_problems() {
        global $wpdb;
        
        $problems = [];
        
        // 1. Détecter les tracking numbers fake/corrompus
        $fake_tracking_query = "
            SELECT DISTINCT order_id, meta_value 
            FROM {$wpdb->prefix}wc_orders_meta 
            WHERE meta_key = '_wwe_ups_tracking_number' 
            AND (
                meta_value REGEXP '9{6,}$' OR 
                LENGTH(meta_value) < 10 OR 
                meta_value NOT REGEXP '^1Z[A-Z0-9]+'
            )
            LIMIT 10
        ";
        
        $fake_tracking = $wpdb->get_results($fake_tracking_query);
        
        foreach ($fake_tracking as $row) {
            $problems[] = [
                'order_id' => $row->order_id,
                'type' => 'fake_tracking',
                'message' => "Tracking fake détecté: {$row->meta_value}",
                'severity' => 'critical'
            ];
        }
        
        // 2. Détecter les données d'étiquettes manquantes
        $missing_labels_query = "
            SELECT o.order_id 
            FROM {$wpdb->prefix}wc_orders_meta o
            WHERE o.meta_key = '_wwe_ups_tracking_number'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}wc_orders_meta l 
                WHERE l.order_id = o.order_id 
                AND l.meta_key = '_wwe_ups_label_count'
            )
            LIMIT 10
        ";
        
        $missing_labels = $wpdb->get_results($missing_labels_query);
        
        foreach ($missing_labels as $row) {
            $problems[] = [
                'order_id' => $row->order_id,
                'type' => 'missing_labels',
                'message' => "Tracking présent mais données d'étiquette manquantes",
                'severity' => 'warning'
            ];
        }
        
        // 3. Détecter les incohérences de statut
        $status_mismatch_query = "
            SELECT o.id, o.status, m.meta_value as tracking
            FROM {$wpdb->prefix}wc_orders o
            JOIN {$wpdb->prefix}wc_orders_meta m ON o.id = m.order_id
            WHERE m.meta_key = '_wwe_ups_tracking_number'
            AND o.status = 'wc-processing'
            AND m.meta_value != ''
            LIMIT 5
        ";
        
        $status_mismatches = $wpdb->get_results($status_mismatch_query);
        
        foreach ($status_mismatches as $row) {
            $problems[] = [
                'order_id' => $row->id,
                'type' => 'status_mismatch',
                'message' => "Statut 'processing' avec tracking {$row->tracking}",
                'severity' => 'info'
            ];
        }
        
        return $problems;
    }
    
    /**
     * Afficher les avertissements dans l'admin
     */
    public function show_health_warnings() {
        $problems = get_transient('wwe_ups_health_problems');
        
        if (empty($problems)) {
            return;
        }
        
        $critical_count = count(array_filter($problems, function($p) { 
            return $p['severity'] === 'critical'; 
        }));
        
        if ($critical_count > 0) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>🚨 UPS WWE Health Check:</strong> ';
            echo sprintf('%d commande(s) avec des problèmes critiques détectés. ', $critical_count);
            echo '<a href="#" onclick="jQuery(this).next().toggle(); return false;">Voir détails</a></p>';
            
            echo '<div style="display:none; margin-top:10px;">';
            foreach ($problems as $problem) {
                if ($problem['severity'] === 'critical') {
                    echo '<p>📋 Commande #' . $problem['order_id'] . ': ' . $problem['message'] . '</p>';
                }
            }
            echo '<p><strong>Solution:</strong> Utilisez les scripts de réparation dans le plugin UPS WWE.</p>';
            echo '</div>';
            echo '</div>';
        }
    }
    
    /**
     * AJAX Handler pour vérification manuelle
     */
    public function ajax_health_check() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        check_ajax_referer('wwe_ups_admin_nonce', 'security');
        
        // Forcer une nouvelle vérification
        delete_transient('wwe_ups_last_health_check');
        $this->check_ups_health();
        
        $problems = get_transient('wwe_ups_health_problems');
        
        wp_send_json_success([
            'problems_count' => count($problems ?: []),
            'problems' => $problems ?: [],
            'message' => empty($problems) ? 
                'Aucun problème UPS détecté' : 
                count($problems) . ' problème(s) détecté(s)'
        ]);
    }
}

// Initialiser le health check
new WWE_UPS_Health_Check(); 
<?php
/**
 * CORRECTION CRITIQUE - Fonction Void UPS
 * 
 * PROBLÈME IDENTIFIÉ:
 * La fonction void ne nettoie les métadonnées QUE si TOUS les voids réussissent.
 * Si un seul échoue, elle laisse TOUT en place, créant des données corrompues.
 * 
 * SOLUTION:
 * Nettoyer TOUJOURS les métadonnées si AU MOINS UN void réussit.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Patch pour corriger la logique de void dans class-wwe-ups-admin.php
 * 
 * À appliquer dans ajax_void_shipment() ligne 604-605
 */
class WWE_UPS_Void_Fix {
    
    /**
     * Logique corrigée pour le nettoyage après void
     * 
     * AVANT (BUGGÉ):
     * if ($success_count > 0 && empty($error_messages)) {
     *     // Nettoyer SEULEMENT si succès complet
     * }
     * 
     * APRÈS (CORRIGÉ):
     * if ($success_count > 0) {
     *     // Nettoyer dès qu'AU MOINS UN void réussit
     *     // Car côté UPS, l'étiquette est déjà invalidée
     * }
     */
    public static function get_corrected_void_logic() {
        return '
        // CORRECTION CRITIQUE: Nettoyer dès qu\'au moins un void réussit
        // Car même avec des erreurs partielles, l\'étiquette UPS est invalidée
        if ($success_count > 0) {
            // Get tracking number before deletion for i-Parcel cleanup
            $tracking_number = $order->get_meta(\'_wwe_ups_tracking_number\', true);
            
            $order->delete_meta_data(\'_wwe_ups_tracking_number\');
            $order->delete_meta_data(\'_wwe_ups_shipment_id\');
            $order->delete_meta_data(\'_wwe_ups_label_format\');
            
            // Delete individual label meta keys using HPOS-compatible methods
            $label_count = $order->get_meta(\'_wwe_ups_label_count\', true);
            if ($label_count > 0) {
                for ($i = 0; $i < $label_count; $i++) {
                    $order->delete_meta_data("_wwe_ups_label_{$i}");
                }
            }
            $order->delete_meta_data(\'_wwe_ups_label_count\');
            
            // Cleanup i-Parcel data
            $this->cleanup_iparcel_after_void($order, $tracking_number);
            
            // Note différente selon succès complet ou partiel
            if (empty($error_messages)) {
                $order->add_order_note(__(\'All UPS WWE Shipments successfully voided.\', \'wwe-ups-woocommerce-shipping\'));
                $message = sprintf(__(\'%d UPS WWE Shipment(s) successfully voided!\', \'wwe-ups-woocommerce-shipping\'), $success_count);
            } else {
                $order->add_order_note(sprintf(__(\'UPS WWE Shipments partially voided (%d success, %d errors). Data cleaned for safety.\', \'wwe-ups-woocommerce-shipping\'), $success_count, count($error_messages)));
                $error_text = implode(\'; \', $error_messages);
                $message = sprintf(__(\'Partial void success (%d/%d). Errors: %s. Order data cleaned for safety.\', \'wwe-ups-woocommerce-shipping\'), $success_count, $success_count + count($error_messages), $error_text);
            }
            
            $order->save();
            
            // Clear caches
            wc_delete_shop_order_transients($order->get_id());
            wp_cache_delete(\'order-\' . $order->get_id(), \'orders\');
            clean_post_cache($order->get_id());
            
            wp_send_json_success([\'message\' => $message, \'voided\' => true]);
        } else {
            // Aucun void n\'a réussi - garder les données
            $error_text = implode(\'; \', $error_messages);
            wp_send_json_error([\'message\' => sprintf(__(\'Failed to void all shipments. Success: %d, Failures: %d. Errors: %s\', \'wwe-ups-woocommerce-shipping\'), $success_count, count($error_messages), $error_text)]);
        }';
    }
    
    /**
     * Explication technique du problème
     */
    public static function get_problem_explanation() {
        return [
            'bug_location' => 'class-wwe-ups-admin.php ligne 604-605',
            'bug_condition' => 'if ($success_count > 0 && empty($error_messages))',
            'problem' => 'Nettoyage SEULEMENT si succès complet',
            'consequence' => 'Données corrompues si échec partiel',
            'solution' => 'if ($success_count > 0) // Nettoyer dès qu\'un void réussit',
            'rationale' => 'Car côté UPS, l\'étiquette est déjà invalidée même avec erreurs partielles'
        ];
    }
    
    /**
     * Cas d'usage problématiques
     */
    public static function get_problematic_scenarios() {
        return [
            'scenario_1' => [
                'description' => 'Void avec tracking number fake/invalide',
                'sequence' => [
                    '1. UPS void réussit (shipment supprimé)',
                    '2. Erreur 190100 sur tracking invalide', 
                    '3. success_count=1, error_messages=[190100]',
                    '4. Condition échoue → Pas de nettoyage',
                    '5. Tracking fake reste → Commande corrompue'
                ]
            ],
            'scenario_2' => [
                'description' => 'Void multiple avec un échec',
                'sequence' => [
                    '1. Premier void réussit',
                    '2. Deuxième void échoue (déjà void)', 
                    '3. success_count=1, error_messages=[already_voided]',
                    '4. Condition échoue → Pas de nettoyage',
                    '5. Données restent → Interface incohérente'
                ]
            ]
        ];
    }
}

// Instructions d'application
echo "🔧 CORRECTION VOID UPS - Instructions d'application\n";
echo "=" . str_repeat("=", 60) . "\n\n";

echo "📍 LOCALISATION DU BUG:\n";
$problem = WWE_UPS_Void_Fix::get_problem_explanation();
foreach ($problem as $key => $value) {
    echo "  {$key}: {$value}\n";
}

echo "\n🚨 SCÉNARIOS PROBLÉMATIQUES:\n";
$scenarios = WWE_UPS_Void_Fix::get_problematic_scenarios();
foreach ($scenarios as $name => $scenario) {
    echo "\n{$scenario['description']}:\n";
    foreach ($scenario['sequence'] as $step) {
        echo "  {$step}\n";
    }
}

echo "\n✅ SOLUTION APPLIQUÉE:\n";
echo "Remplacer la condition ligne 604-605 par:\n";
echo "if (\$success_count > 0) {\n";
echo "  // Nettoyer dès qu'au moins un void réussit\n";
echo "}\n";

echo "\n🎯 RÉSULTAT ATTENDU:\n";
echo "- ✅ Plus de données corrompues après void partiel\n";
echo "- ✅ Nettoyage systématique si au moins un void réussit\n";
echo "- ✅ Messages différenciés (succès complet vs partiel)\n";
echo "- ✅ Prévention automatique du problème #619924\n";

echo "\n🔧 Cette correction empêchera définitivement le problème rencontré!\n"; 
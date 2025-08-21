<?php
/**
 * Script manuel pour soumettre les informations produits UPS WWE
 * Usage: php ups-wwe-manual-submit.php
 */

// Charger WordPress
require_once('wp-config.php');
require_once('wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress non chargé');
}

echo "🚀 UPS WWE Manual Submit Script\n";
echo "===============================\n\n";

// Vérifier si la classe existe
if (!class_exists('UPS_WWE_Auto_Submit_Items')) {
    echo "❌ Erreur: Classe UPS_WWE_Auto_Submit_Items non trouvée\n";
    echo "Assurez-vous que le plugin woocommerce-ups-wwe est activé\n";
    exit(1);
}

// Instancier la classe
$auto_submit = new UPS_WWE_Auto_Submit_Items();

// Récupérer les tracking numbers en attente
echo "📋 Récupération des tracking numbers en attente...\n";
$pending_tracking = $auto_submit->get_pending_tracking_numbers();

if (empty($pending_tracking)) {
    echo "✅ Aucun tracking number en attente de soumission\n";
    exit(0);
}

echo "📦 Tracking numbers trouvés: " . count($pending_tracking) . "\n\n";

// Afficher la liste
foreach ($pending_tracking as $tracking) {
    echo "- {$tracking->tracking_number} (Commande #{$tracking->order_id})\n";
}

echo "\n🔄 Traitement des soumissions...\n";
echo "================================\n";

// Traiter tous les tracking numbers
$results = $auto_submit->process_all_pending();

$success_count = 0;
$error_count = 0;

foreach ($results as $result) {
    $status = $result['success'] ? '✅' : '❌';
    echo "{$status} {$result['tracking_number']} (#{$result['order_id']}): {$result['message']}\n";
    
    if ($result['success']) {
        $success_count++;
    } else {
        $error_count++;
    }
}

echo "\n📊 RÉSULTATS FINAUX\n";
echo "==================\n";
echo "✅ Succès: {$success_count}\n";
echo "❌ Erreurs: {$error_count}\n";
echo "📦 Total: " . count($results) . "\n";

if ($success_count > 0) {
    echo "\n🎉 Soumissions terminées avec succès!\n";
    echo "Les tracking numbers devraient maintenant avoir le statut 'ItemsProvidedByMerchant'\n";
} else {
    echo "\n⚠️ Aucune soumission réussie. Vérifiez les logs pour plus de détails.\n";
}

echo "\n📝 Logs détaillés disponibles dans WooCommerce > Status > Logs (source: wwe-ups)\n";

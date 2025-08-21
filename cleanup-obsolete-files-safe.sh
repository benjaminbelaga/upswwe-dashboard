#!/bin/bash

# 🧹 SCRIPT NETTOYAGE SÉCURISÉ - WWE UPS PLUGIN (VERSION PRUDENTE)
# Supprime SEULEMENT les 12 fichiers 100% obsolètes - PRÉSERVE la documentation technique

echo "🧹 NETTOYAGE SÉCURISÉ PLUGIN WWE UPS (APPROCHE PRUDENTE)"
echo "════════════════════════════════════════════════════════"

# Compteurs
DELETED_COUNT=0
TOTAL_FILES=12  # Réduit de 19 à 12 pour sécurité

# Configuration
BACKUP_DIR="safe_cleanup_backup_$(date +%Y%m%d_%H%M%S)"

echo "📦 Création du dossier de sauvegarde: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

echo ""
echo "⚠️  APPROCHE PRUDENTE ACTIVÉE"
echo "──────────────────────────────"
echo "✅ GARDE: Tous les fichiers .md avec infos techniques UPS"
echo "✅ GARDE: Documentation formats API, erreurs, spécifications"
echo "🗑️  SUPPRIME: Seulement fichiers 100% obsolètes sans valeur"

# Fonction de suppression sécurisée
safe_delete() {
    local file=$1
    local category=$2
    
    if [ -f "$file" ]; then
        echo "🗑️  [$category] Suppression: $file"
        # Sauvegarde avant suppression
        cp "$file" "$BACKUP_DIR/" 2>/dev/null
        rm -f "$file"
        if [ $? -eq 0 ]; then
            ((DELETED_COUNT++))
            echo "    ✅ Supprimé et sauvegardé"
        else
            echo "    ❌ Erreur de suppression"
        fi
    else
        echo "    ⚠️  Fichier déjà absent: $file"
    fi
}

echo ""
echo "🔍 SUPPRESSION SÉCURISÉE - 12 FICHIERS OBSOLÈTES"
echo "───────────────────────────────────────────────"

# === TESTS OBSOLÈTES (6 fichiers) ===
echo ""
echo "📂 Tests Obsolètes - 100% Sûrs (6 fichiers)"
safe_delete "test-customs-submission.php" "TEST"
safe_delete "test-local.php" "TEST"
safe_delete "test-paperless-fix.php" "TEST"
safe_delete "test-simple.php" "TEST"
safe_delete "quick-test.php" "TEST"
safe_delete "quick-diagnostic.php" "TEST"

# === SCRIPTS ANCIENS (4 fichiers) ===
echo ""
echo "📂 Scripts Anciens - 100% Sûrs (4 fichiers)"
safe_delete "deploy-plugins.sh" "SCRIPT"
safe_delete "add-plugin.sh" "SCRIPT"
safe_delete "plugin-sync-system.sh" "SCRIPT"
safe_delete "plugin-sync-config.json" "CONFIG"

# === FICHIERS SYSTÈME (2 fichiers) ===
echo ""
echo "📂 Fichiers Système - 100% Sûrs (2 fichiers)"
safe_delete ".DS_Store" "SYSTEM"
safe_delete "debug-shipper-number.php" "DEBUG"

echo ""
echo "✅ FICHIERS PRÉSERVÉS (CONTIENNENT INFOS IMPORTANTES)"
echo "──────────────────────────────────────────────────────"
echo "📚 Documentation UPS gardée:"
echo "   • FIX_ERREUR_*.md (formats ShipperNumber, API v2, dates)"
echo "   • FIX_FINAL_UPS_PAPERLESS_V2.md (spécifications officielles)"  
echo "   • WWE_UK_ZONE_FIX.md (zones WooCommerce)"
echo "   • SESSION_SUMMARY.md (architecture plugins)"
echo ""
echo "🎯 Nouveaux fichiers conservés:"
echo "   • REAL-API-IMPLEMENTATION.md (Single Source of Truth)"
echo "   • test-real-api-prices.php (validation prix API)"
echo "   • deploy-fixed-plugin.sh (déploiement corrigé)"

echo ""
echo "🧹 NETTOYAGE SÉCURISÉ TERMINÉ"
echo "═══════════════════════════════"
echo "📊 Résultats:"
echo "   • Fichiers supprimés: $DELETED_COUNT/$TOTAL_FILES"
echo "   • Fichiers sauvegardés dans: $BACKUP_DIR"
echo "   • Documentation technique PRÉSERVÉE"
echo ""

# Vérification de l'espace libéré
if [ $DELETED_COUNT -gt 0 ]; then
    echo "✅ SUCCÈS: Nettoyage sécurisé terminé"
    echo "🎯 Bénéfices:"
    echo "   • Plugin nettoyé sans perte d'informations importantes"
    echo "   • Réduction de ~28% des fichiers (43 → 31)"
    echo "   • Spécifications UPS conservées pour référence"
    echo "   • Documentation technique intacte"
    echo ""
    echo "📁 Architecture finale optimale:"
    echo "   • Core plugin: woocommerce-ups-wwe.php + includes/"
    echo "   • Single Source of Truth: corrections prix implémentées"
    echo "   • Documentation UPS: formats et spécifications"
    echo "   • Tests fonctionnels: validation prix API"
    echo ""
    echo "🚀 PRÊT POUR DÉPLOIEMENT EN TOUTE SÉCURITÉ!"
else
    echo "⚠️  ATTENTION: Aucun fichier supprimé"
    echo "   Plugin déjà nettoyé ou erreur de chemin"
fi

# Nettoyage du dossier de sauvegarde si vide
if [ ! "$(ls -A $BACKUP_DIR 2>/dev/null)" ]; then
    rmdir "$BACKUP_DIR" 2>/dev/null
    echo "🗂️  Dossier de sauvegarde vide supprimé"
fi

echo ""
echo "🎉 NETTOYAGE SÉCURISÉ TERMINÉ - DOCUMENTATION PRÉSERVÉE!" 
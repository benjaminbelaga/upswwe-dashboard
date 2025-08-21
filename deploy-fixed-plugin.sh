#!/bin/bash

# 🎯 DÉPLOIEMENT SINGLE SOURCE OF TRUTH - WWE UPS PLUGIN
# Déploie les corrections sur YOYAKU.IO et YYDistribution.fr

echo "🎯 DÉPLOIEMENT SINGLE SOURCE OF TRUTH - WWE UPS PLUGIN"
echo "════════════════════════════════════════════════════"

# Configuration
LOCAL_PATH="/Users/yoyaku/Desktop/UPSWWE PLUGIN WOOCOMMERCE/woocommerce-ups-wwe"
SSH_HOST="yoyaku-cloudways"

# Paths distants sur serveur Cloudways 134.122.80.6
YOYAKU_PATH="/home/870689.cloudwaysapps.com/jfnkmjmfer/public_html/wp-content/plugins/woocommerce-ups-wwe"
YYD_PATH="/home/870689.cloudwaysapps.com/akrjekfvzk/public_html/wp-content/plugins/woocommerce-ups-wwe"

# Vérification du chemin local
if [ ! -d "$LOCAL_PATH" ]; then
    echo "❌ ERREUR: Chemin local introuvable: $LOCAL_PATH"
    exit 1
fi

echo "📦 Plugin local: $LOCAL_PATH"
echo "🚀 Serveur cible: $SSH_HOST (134.122.80.6)"
echo "📍 Sites cibles:"
echo "   • YOYAKU.IO: $YOYAKU_PATH"
echo "   • YYDistribution.fr: $YYD_PATH"
echo ""

# Vérification de la connexion SSH
echo "🔍 Test de connexion SSH..."
if ! ssh $SSH_HOST 'echo "✅ Connexion SSH OK"'; then
    echo "❌ ERREUR: Connexion SSH échouée vers $SSH_HOST"
    exit 1
fi

echo ""
read -p "🤔 Continuer le déploiement des corrections SINGLE SOURCE OF TRUTH? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Déploiement annulé."
    exit 1
fi

# Fonction de sauvegarde
backup_plugin() {
    local remote_path=$1
    local site_name=$2
    local timestamp=$(date +%Y%m%d_%H%M%S)
    
    echo "📦 Sauvegarde de $site_name..."
    ssh $SSH_HOST "cd $(dirname $remote_path) && \
                   if [ -d 'woocommerce-ups-wwe' ]; then \
                       tar -czf woocommerce-ups-wwe_BACKUP_${timestamp}.tar.gz woocommerce-ups-wwe && \
                       echo '✅ Sauvegarde créée: woocommerce-ups-wwe_BACKUP_${timestamp}.tar.gz'; \
                   else \
                       echo '⚠️  Plugin non trouvée, pas de sauvegarde nécessaire'; \
                   fi"
}

# Fonction de déploiement
deploy_plugin() {
    local remote_path=$1
    local site_name=$2
    
    echo "🔄 Déploiement sur $site_name..."
    
    # Créer le répertoire parent si nécessaire
    ssh $SSH_HOST "mkdir -p $(dirname $remote_path)"
    
    # Rsync avec la configuration SSH correcte
    rsync -avz --delete \
          --exclude='.git*' \
          --exclude='node_modules' \
          --exclude='.DS_Store' \
          --exclude='*.log' \
          --exclude='tmp/' \
          --exclude='logs/' \
          -e "ssh" \
          "$LOCAL_PATH/" \
          "${SSH_HOST}:${remote_path}/"
    
    if [ $? -eq 0 ]; then
        echo "✅ $site_name: Déploiement réussi"
        return 0
    else
        echo "❌ $site_name: Erreur de déploiement"
        return 1
    fi
}

# Fonction de vérification
verify_deployment() {
    local remote_path=$1
    local site_name=$2
    
    echo "🔍 Vérification $site_name..."
    ssh $SSH_HOST "cd $remote_path && \
                   if [ -f 'woocommerce-ups-wwe.php' ]; then \
                       echo '✅ Plugin principal trouvé' && \
                       echo '📁 Fichiers modifiés:' && \
                       ls -la includes/wwe-ups-functions.php includes/class-wwe-ups-*.php test-*.php REAL-API-*.md 2>/dev/null | head -10; \
                   else \
                       echo '❌ Plugin non trouvé'; \
                   fi"
}

echo ""
echo "🎯 === DÉBUT DU DÉPLOIEMENT ==="

# === YOYAKU.IO ===
echo ""
echo "🏠 YOYAKU.IO (jfnkmjmfer)"
echo "─────────────────────────"
backup_plugin "$YOYAKU_PATH" "YOYAKU.IO"
deploy_plugin "$YOYAKU_PATH" "YOYAKU.IO"
YOYAKU_SUCCESS=$?

echo ""

# === YYDistribution.fr ===
echo "🏢 YYDistribution.fr (akrjekfvzk)"
echo "──────────────────────────────"
backup_plugin "$YYD_PATH" "YYDistribution.fr"  
deploy_plugin "$YYD_PATH" "YYDistribution.fr"
YYD_SUCCESS=$?

echo ""
echo "🔍 === VÉRIFICATIONS POST-DÉPLOIEMENT ==="
echo ""

verify_deployment "$YOYAKU_PATH" "YOYAKU.IO"
echo ""
verify_deployment "$YYD_PATH" "YYDistribution.fr"

echo ""
echo "🎉 === RÉSULTATS FINAUX ==="

if [ $YOYAKU_SUCCESS -eq 0 ] && [ $YYD_SUCCESS -eq 0 ]; then
    echo "✅ SUCCÈS TOTAL: Plugin WWE UPS déployé sur les deux sites"
    echo ""
    echo "🎯 SINGLE SOURCE OF TRUTH activée:"
    echo "   • ✅ i-Parcel API utilisée partout (vrais prix WWE)"
    echo "   • ✅ Fallbacks locaux éliminés"
    echo "   • ✅ Prix identiques admin/front office"
    echo ""
    echo "📝 PROCHAINES ÉTAPES:"
    echo "   1. Tester: /wp-content/plugins/woocommerce-ups-wwe/test-real-api-prices.php"
    echo "   2. Surveiller les logs WWE pour les prix API"
    echo "   3. Vérifier quelques commandes test"
    echo ""
    exit 0
else
    echo "❌ ERREURS DE DÉPLOIEMENT DÉTECTÉES"
    echo "   • YOYAKU.IO: $([ $YOYAKU_SUCCESS -eq 0 ] && echo '✅ OK' || echo '❌ ERREUR')"
    echo "   • YYDistribution.fr: $([ $YYD_SUCCESS -eq 0 ] && echo '✅ OK' || echo '❌ ERREUR')"
    echo ""
    echo "🛠️  Vérifiez les messages d'erreur ci-dessus"
    exit 1
fi 
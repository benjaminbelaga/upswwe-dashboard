# 🧹 PLAN DE NETTOYAGE UPS WWE PLUGIN

## 📋 **ANALYSE ACTUELLE**

### **🗑️ FICHIERS À SUPPRIMER (Obsolètes/Temporaires)**

#### **1. Scripts de Réparation Spécifiques (Mission Terminée)**
```bash
# Fichiers pour réparer la commande #619924 (problème résolu)
- repair-corrupted-order.php
- sql-repair-619924.php  
- quick-diagnostic-619924.php
- deploy-repair-scripts.sh
- diagnostic-ups-orders.php
- REPAIR-CORRUPTED-ORDERS-README.md
```

#### **2. Fichiers de Test Temporaires**
```bash
# Tests spécifiques maintenant obsolètes
- test-auto-customs.php
- test-complete-workflow.php
- test-setcheckout-integration.php
- test-void-cleanup.php
```

#### **3. Fichiers de Debug Temporaires**
```bash
# Debug spécifique maintenant inutile
- UPS-API-REQUEST-DEBUG.md
```

#### **4. Backups Anciens**
```bash
# Backups dans tools/backups/ (anciens)
- tools/backups/wwe-ups-functions.php.backup-before-double-call-20250720-113946
- tools/backups/wwe-ups-functions.php.backup-fatal-error-fix
- tools/backups/class-wwe-ups-shipping-method.php.backup
- tools/backups/class-wwe-ups-shipping-method.php.backup2
- tools/backups/class-wwe-ups-api-handler.php.backup-before-double-call-20250720-113941
```

#### **5. Fichiers Système**
```bash
# Fichiers système inutiles
- .DS_Store (racine et sous-dossiers)
```

### **📁 FICHIERS À DÉPLACER/RÉORGANISER**

#### **1. Documentation → docs/**
```bash
# Déplacer vers docs/
- ADMIN-INTERFACE-IMPROVEMENTS.md → docs/
- ANALYSIS-UPS-vs-IPARCEL.md → docs/
- EMAIL-UPS-WWE-SUPPORT.md → docs/
- SOLUTION-FINALE-SUBMITCATALOG.md → docs/
- AUTO-CUSTOMS-README.md → docs/
```

#### **2. Tests → tests/**
```bash
# Garder seulement les tests utiles
- tests/test-real-api-prices.php (GARDER)
```

### **✅ FICHIERS À CONSERVER (Essentiels)**

#### **1. Core Plugin**
```bash
- woocommerce-ups-wwe.php ✅
- includes/ ✅
- vendor/ ✅
- assets/ ✅
- resources/ ✅
- languages/ ✅
```

#### **2. Configuration**
```bash
- composer.json ✅
- uninstall.php ✅
- .gitignore ✅
- README.md ✅
- CHANGELOG.md ✅
```

#### **3. Documentation Essentielle**
```bash
- docs/ (tout le contenu) ✅
```

#### **4. Outils Utiles**
```bash
- tools/ups-wwe-manual-submit.php ✅
- cleanup-obsolete-files-safe.sh ✅
- deploy-fixed-plugin.sh ✅
```

## 🎯 **ACTIONS DE NETTOYAGE**

### **PHASE 1: Suppression des Fichiers Obsolètes**
1. Scripts de réparation spécifiques (mission terminée)
2. Fichiers de test temporaires 
3. Debug temporaires
4. Backups anciens
5. Fichiers système (.DS_Store)

### **PHASE 2: Réorganisation**
1. Déplacer documentation vers docs/
2. Nettoyer structure des dossiers
3. Vérifier que tout fonctionne

### **PHASE 3: Validation**
1. Test syntaxe PHP
2. Test fonctionnalités principales
3. Mise à jour README si nécessaire

## 📊 **ESTIMATION GAIN D'ESPACE**

- **Fichiers de réparation**: ~30KB
- **Tests temporaires**: ~25KB  
- **Backups anciens**: ~500KB
- **Documentation mal placée**: ~20KB
- **Total estimé**: ~575KB + organisation améliorée

## ⚠️ **PRÉCAUTIONS**

1. **Backup complet** avant nettoyage
2. **Test fonctionnalités** après nettoyage
3. **Vérification déploiement** sur serveur
4. **Garder historique Git** pour rollback si besoin

## 🚀 **RÉSULTAT ATTENDU**

- ✅ Plugin plus propre et organisé
- ✅ Documentation centralisée dans docs/
- ✅ Suppression fichiers obsolètes
- ✅ Structure plus professionnelle
- ✅ Maintenance facilitée 
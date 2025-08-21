# Changelog

Toutes les modifications notables de ce projet seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Non publié]

### Ajouté
- Structure de plugin réorganisée selon les standards WordPress/WooCommerce
- Fichier `uninstall.php` pour nettoyage propre lors de la désinstallation
- Fichier `composer.json` pour gestion des dépendances
- Organisation des fichiers de backup dans `tools/backups/`
- Déplacement des librairies externes vers `vendor/`

### Modifié
- Réorganisation de la structure des dossiers pour respecter les standards
- Nettoyage des fichiers temporaires et de développement

## [1.0.0] - 2025-07-24

### Ajouté
- **Single Source of Truth** : Unification complète des calculs de tarifs entre frontend et admin
- Support intelligent des adresses incomplètes avec fallbacks géographiques
- Validation flexible des codes postaux selon les pays (75+ pays supportés)
- Gestion multi-colis automatique pour les poids élevés (>20kg)
- Logs de débogage complets via WooCommerce Logger
- Headers WordPress/WooCommerce complets et conformes
- Documentation technique complète

### Modifié
- **BREAKING** : Suppression complète des fallbacks locaux - API UPS uniquement
- Refactorisation complète de la logique admin pour utiliser la même API que le frontend
- Amélioration de la validation des adresses avec support pays sans codes postaux
- Optimisation des requêtes API UPS avec gestion intelligente des packages
- Correction de l'erreur "Invalid Package Type" (111212) via standardisation `PackagingType`

### Corrigé
- Incohérence de prix entre simulation admin et checkout client
- Erreur "Missing State Province Code" avec fallbacks intelligents (US→NY, AU→NSW, CA→ON)
- Erreur "Adresse ShipTo incomplète" pour pays sans codes postaux obligatoires (AE, AF, etc.)
- Erreur `Undefined array key "line_subtotal"` avec null coalescing operators
- Problème de chargement des settings dans le constructeur (`debug_mode` toujours OFF)
- Erreurs API UPS 111100 et 111212 via correction des service codes et package format

### Technique
- Service Code : UPS Worldwide Economy (17) pour tarifs négociés
- API : Appel direct UPS Rate API sans intermédiaires
- Multi-packages : Division automatique par poids (20kg max/package)
- Dimensions : Cartons 33x33x33cm par défaut
- Fallbacks : États US/CA/AU, codes postaux optionnels selon pays
- Logs : WooCommerce `wc-logs/` directory via `wc_get_logger()`

### Sécurité
- Validation stricte des entrées API
- Échappement des données de sortie
- Vérification des permissions utilisateur
- Protection contre l'accès direct aux fichiers

---

## Types de modifications

- **Ajouté** pour les nouvelles fonctionnalités
- **Modifié** pour les changements dans les fonctionnalités existantes
- **Déprécié** pour les fonctionnalités qui seront supprimées dans les versions à venir
- **Supprimé** pour les fonctionnalités supprimées dans cette version
- **Corrigé** pour les corrections de bugs
- **Sécurité** en cas de vulnérabilités 
# Changelog - Nelis API Connector

## Changelog

## [1.4.1] - 2025-01-08

### Ajouté
- **Export CSV** : Bouton d'export CSV pour le tableau des contacts dans la page de synchronisation
- **Interface responsive** : Conteneur recherche/export adaptatif pour les écrans mobiles

### Amélioré
- **Design moderne** : Refonte complète de l'interface de synchronisation avec le même design que la page de réglages
- **Cartes visuelles** : Statistiques et actions organisées en cartes avec en-têtes colorés et effets de survol
- **Boutons d'action** : Nouveaux boutons avec icônes et gradients pour une meilleure UX
- **Tableau stylisé** : Amélioration du design du tableau des contacts avec hover effects
- **Badges de statut** : Mise à jour des couleurs et de la typographie pour les statuts de contact

### Technique
- **Export côté client** : Fonctionnalité d'export JavaScript sans requête serveur
- **Filtrage respecté** : L'export CSV respecte les filtres de recherche appliqués
- **Nommage automatique** : Fichiers CSV avec horodatage automatique
- **Encodage UTF-8** : Support complet des caractères spéciaux dans l'export

## [1.4] - 2025-09-03

### 🚀 Nouvelles fonctionnalités
- **API REST WordPress pour les URLs Cron** : Migration complète vers l'API REST native WordPress
- **URLs Cron sécurisées et fiables** : Nouveau système d'authentification par token pour les tâches automatisées
- **Interface d'administration des URLs Cron** : Page dédiée avec génération automatique des URLs et exemples d'utilisation

### 🔧 Améliorations majeures
- **Stabilité des URLs** : Remplacement des règles de réécriture personnalisées par des endpoints REST API
- **Token de sécurité persistant** : Le token ne se régénère plus automatiquement à chaque chargement
- **Documentation intégrée** : Exemples complets pour crontab, wget, PowerShell et curl
- **Réponses JSON standardisées** : Format uniforme pour toutes les réponses API

### 🛠️ Corrections techniques
- **Résolution des erreurs 404** : Les URLs cron fonctionnent maintenant correctement
- **Correction "rest_forbidden"** : Authentification manuelle implémentée pour contourner les restrictions WordPress
- **Bouton de test admin corrigé** : URL de test générée correctement dans l'interface d'administration
- **Namespace REST API** : `nelis-brevo/v1` pour une meilleure organisation

### 📋 Nouvelles URLs disponibles
- `/wp-json/nelis-brevo/v1/status?token={token}` - Statut de synchronisation
- `/wp-json/nelis-brevo/v1/sync-incremental?token={token}` - Synchronisation incrémentale
- `/wp-json/nelis-brevo/v1/sync-all?token={token}` - Synchronisation complète
- `/wp-json/nelis-brevo/v1/fetch-nelis?token={token}` - Récupération depuis Nelis
- `/wp-json/nelis-brevo/v1/verify-status?token={token}` - Vérification des statuts
- `/wp-json/nelis-brevo/v1/clean-brevo?token={token}` - Nettoyage Brevo
- `/wp-json/nelis-brevo/v1/clean-old?token={token}` - Suppression des anciens contacts

### 🔒 Sécurité renforcée
- **Authentification par token sécurisée** : Utilisation de `hash_equals()` pour éviter les attaques de timing
- **Token stable** : Plus de régénération automatique intempestive
- **Validation des paramètres** : Contrôle strict des actions autorisées

---

## Version 1.3 - 2025-01-03

### 🚀 Nouvelles fonctionnalités
- **Suppression en masse des contacts Brevo** : Nouveau bouton pour supprimer tous les contacts de Brevo avec confirmation
- **Interface admin centralisée** : Consolidation de tous les réglages dans une seule page "Nelis-Brevo"
- **Vérification améliorée des statuts** : Debug détaillé pour identifier les incohérences entre base locale et Brevo

### 🔧 Améliorations
- **Page de réglages unifiée** : Tous les paramètres API (Nelis et Brevo) dans une seule interface
- **Suppression des pages admin redondantes** : Élimination des anciennes pages de configuration séparées
- **Logging amélioré** : Messages de debug plus détaillés pour le diagnostic
- **Gestion d'erreur renforcée** : Meilleure détection et correction des statuts incohérents

### 🛠️ Corrections techniques
- **Méthode `deleteContactByEmail()`** : Implémentation complète dans BrevoConnector
- **Fonction `verify_brevo_status()`** : Debug ajouté pour identifier les problèmes de synchronisation
- **Noms d'options corrigés** : Cohérence dans l'utilisation des options Brevo (`brevo_config`)
- **Sécurité renforcée** : Vérification nonce et confirmation utilisateur pour les actions destructives

### 📋 Changements d'interface
- Menu principal : **Nelis-Brevo** avec sous-pages "Synchronisation" et "Réglages"
- Bouton rouge "Supprimer tous les contacts de Brevo" avec confirmation JavaScript
- Configuration des champs personnalisés déplacée vers la page Réglages
- Suppression des pages admin legacy (options-general.php)

### 🔒 Sécurité
- Confirmation obligatoire pour la suppression en masse
- Vérification des capacités utilisateur (`manage_options`)
- Protection AJAX avec nonce
- Délai entre suppressions pour éviter la surcharge API

---

## Version 1.2 - Précédente
- Synchronisation Nelis vers Brevo
- Gestion des champs personnalisés
- Interface admin de base

## Version 1.1 - Initiale
- Connexion API Nelis v4
- Récupération des contacts
- Shortcodes de base

# Changelog - Nelis API Connector

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

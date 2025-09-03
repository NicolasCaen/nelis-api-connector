# Changelog - Nelis API Connector

## Version 1.2 - 2025-09-03

### 🎉 Nouvelles fonctionnalités

#### Interface d'administration centralisée
- **Nouveau menu principal "Nelis-Brevo Sync"** avec toutes les configurations centralisées
- **Page de synchronisation** avec statistiques en temps réel et contrôles manuels
- **Page de documentation des shortcodes** avec descriptions détaillées et exemples d'usage
- **Sous-pages de configuration** pour Brevo et Nelis API

#### Gestion des menus
- Suppression des pages de configuration dispersées dans le menu "Réglages"
- Centralisation de toutes les fonctionnalités sous un seul menu principal
- Interface utilisateur améliorée avec navigation cohérente

### 🔧 Améliorations

#### Structure des menus
- **Nelis-Brevo Sync** (page principale de synchronisation)
- **Réglages** (configuration des champs personnalisés et filtres)
- **Config Brevo** (clé API Brevo et ID de liste)
- **Config Nelis** (identifiants API Nelis)
- **Shortcodes** (documentation complète des shortcodes)

#### Documentation
- Page dédiée aux shortcodes avec :
  - Descriptions détaillées de chaque shortcode
  - Syntaxe d'usage et paramètres
  - Exemples concrets d'utilisation
  - Notes importantes sur la configuration

### 🐛 Corrections

#### Problèmes d'affichage
- **Résolu** : Formulaire de configuration Brevo qui s'affichait 3 fois
- **Résolu** : Pages de configuration dupliquées dans différents menus
- **Résolu** : Erreur de lint concernant `WP_CONTENT_DIR`

#### Initialisation des classes
- Ajout de `NelisBrevoSyncAdmin` dans l'initialisation du plugin
- Correction des hooks pour les scripts JavaScript

### 📚 Shortcodes disponibles

1. `[nelis_contacts]` - Affiche tous les contacts filtrés
2. `[nelis_contact id="123"]` - Affiche un contact spécifique par ID
3. `[nelis_adherents]` - Affiche tous les adhérents
4. `[nelis_contacts_by_date date="2023-01-01"]` - Contacts par date
5. `[nelis_contacts_created_since date="2023-01-01"]` - Contacts créés depuis une date
6. `[nelis_contacts_updated_since date="2023-01-01"]` - Contacts mis à jour depuis une date

### 🔄 Migration

#### Pour les utilisateurs existants
- Les configurations existantes sont préservées
- Les shortcodes continuent de fonctionner sans modification
- Accès aux configurations via le nouveau menu "Nelis-Brevo Sync"

#### Changements d'interface
- Les pages "Brevo Config" et "Nelis API" ne sont plus dans le menu "Réglages"
- Toutes les fonctionnalités sont maintenant accessibles via "Nelis-Brevo Sync"

### 🛠️ Technique

#### Fichiers modifiés
- `nelis-api-connector.php` - Version mise à jour (1.2) et initialisation corrigée
- `class-brevo-connector.php` - Suppression du menu autonome
- `class-nelis-api-settings.php` - Suppression du menu autonome
- `class-nelis-brevo-synch-admin.php` - Ajout des sous-pages et documentation

#### Compatibilité
- WordPress 5.0+
- PHP 7.4+
- API Nelis v4
- API Brevo v3

---

## Version 1.1 - Précédente

### Fonctionnalités de base
- Connexion à l'API Nelis v4
- Synchronisation avec Brevo
- Shortcodes pour l'affichage des contacts
- Gestion des champs personnalisés
- Synchronisation automatique par cron

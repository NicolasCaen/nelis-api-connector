# Nelis API Connector - Version 1.3

Plugin WordPress pour synchroniser les contacts entre l'API Nelis v4 et Brevo (ex-Sendinblue).

## 🚀 Fonctionnalités

### Synchronisation bidirectionnelle
- **Nelis → Brevo** : Synchronisation automatique des contacts avec champs personnalisés
- **Vérification des statuts** : Détection et correction des incohérences entre les bases
- **Suppression en masse** : Possibilité de vider complètement la liste Brevo

### Interface admin centralisée
- **Page unique** : Tous les réglages dans "Nelis-Brevo"
- **Deux sections** : "Synchronisation" (actions) et "Réglages" (configuration)
- **Actions sécurisées** : Confirmations pour les opérations critiques

### Gestion avancée des données
- **Champs personnalisés** : Support complet des custom fields Nelis
- **Sanitisation JSON** : Nettoyage automatique des caractères problématiques
- **Logging détaillé** : Suivi complet des opérations de synchronisation

## 📋 Installation

1. **Télécharger** le plugin dans `/wp-content/plugins/nelis-api-connector/`
2. **Activer** le plugin dans l'admin WordPress
3. **Configurer** les API dans "Nelis-Brevo" → "Réglages"

## ⚙️ Configuration

### API Nelis
- **Client ID** : Identifiant client API Nelis
- **Client Secret** : Clé secrète API Nelis  
- **Username** : Nom d'utilisateur Nelis
- **Password** : Mot de passe Nelis

### API Brevo
- **API Key** : Clé API Brevo (ex-Sendinblue)
- **List ID** : ID de la liste de contacts Brevo

### Champs personnalisés
Configuration des mappings entre champs Nelis et attributs Brevo.

## 🔧 Utilisation

### Actions de synchronisation
- **Synchronisation incrémentale** : Contacts modifiés uniquement
- **Synchronisation complète** : Tous les contacts en attente
- **Vérification des statuts** : Contrôle de cohérence Nelis ↔ Brevo
- **Nettoyage** : Suppression des contacts obsolètes
- **Suppression totale** : Vider la liste Brevo (avec confirmation)

### Automatisation
- **Cron quotidien** : Synchronisation automatique programmée
- **Shortcodes** : Affichage des données dans les pages/articles

## 🛡️ Sécurité

- **Vérification des capacités** : Accès limité aux administrateurs
- **Protection AJAX** : Nonces pour toutes les requêtes
- **Confirmations** : Dialogues pour les actions destructives
- **Logging sécurisé** : Pas d'exposition des données sensibles

## 📊 Monitoring

### Logs disponibles
- Opérations de synchronisation
- Erreurs API et connexion
- Statistiques de traitement
- Debug des données problématiques

### Fichiers temporaires
- Réponses JSON brutes (dossier `/temp/`)
- Attributs Brevo pour debug
- Données sanitisées pour analyse

## 🔄 Changelog

Voir [CHANGELOG.md](CHANGELOG.md) pour l'historique détaillé des versions.

## 👨‍💻 Développement

### Structure du projet
```
nelis-api-connector/
├── includes/
│   ├── class-nelis-api-client.php      # Client API Nelis
│   ├── class-brevo-connector.php       # Connecteur Brevo
│   ├── class-contact-synchronizer.php  # Logique de sync
│   ├── class-nelis-brevo-synch-admin.php # Interface admin
│   └── class-nelis-api-shortcode.php   # Shortcodes
├── temp/                               # Fichiers temporaires
├── nelis-api-connector.php            # Plugin principal
├── README.md                          # Documentation
└── CHANGELOG.md                       # Historique versions
```

### Hooks disponibles
- `nelis_brevo_daily_sync` : Synchronisation automatique
- Actions AJAX sécurisées pour l'interface admin

## 📞 Support

Pour toute question ou problème :
1. Vérifier les logs WordPress
2. Contrôler la configuration des API
3. Tester la connectivité Nelis/Brevo

---

**Version 1.3** - Développé par Nicolas GEHIN

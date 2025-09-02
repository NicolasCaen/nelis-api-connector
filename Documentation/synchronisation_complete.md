# Synchronisation complète

## Description
La synchronisation complète récupère **tous les contacts** de Nelis depuis le début et les synchronise vers Brevo. C'est la synchronisation la plus exhaustive.

## Processus détaillé

### 1. Préparation
- Vide complètement la table `wp_nelis_brevo_sync`
- Crée un dossier temporaire `/temp/` pour sauvegarder les réponses JSON
- Vérifie et ajoute les colonnes de dates si nécessaires

### 2. Récupération depuis Nelis
La synchronisation récupère tous les contacts par lots de 100 :
- **Premier appel** : `https://amavea.mynelis.com/api/v4/people?range=0-99&with_custom_values=true`
- **Appels suivants** : `range=100-199`, `range=200-299`, etc.
- **Sauvegarde** : Chaque réponse JSON est enregistrée dans `/temp/nelis_response_YYYY-MM-DD_HH-mm-ss.json`
- **Limite** : Maximum 50 itérations par sécurité (5000 contacts max)

### 3. Filtrage des contacts "valides"
Pour chaque contact récupéré, vérifie s'il est valide selon ces critères :

#### Critères obligatoires
- **Email présent** : Le contact doit avoir un email valide
- **ID Nelis** : Le contact doit avoir un ID unique

#### Filtre par date (configurable)
- **Champ personnalisé** : Utilise le champ configuré (ex: `custom_80`)
- **Format requis** : Date au format `YYYY-MM-DD`
- **Règle temporelle** : Date moins de 1 an dans le passé par rapport à aujourd'hui

### 4. Stockage dans la table locale
Seuls les contacts **valides** sont insérés dans `wp_nelis_brevo_sync` avec :
- **Informations de base** : email, nom, prénom
- **Champs personnalisés** : Tous stockés dans des colonnes `custom_XX` créées dynamiquement
- **Dates** : `date_creation` et `date_update` depuis Nelis
- **Statut** : `pending` (en attente de synchronisation vers Brevo)

### 5. Synchronisation vers Brevo
- Récupère tous les contacts avec statut `pending`
- Les ajoute à la liste Brevo configurée
- Met à jour le statut : `synced` (succès) ou `error` (échec)

## Exemple concret
Si Nelis contient 1000 contacts :
- **Récupérés** : 1000 contacts depuis Nelis
- **Filtrés** : 300 contacts avec date valide récente
- **Stockés** : 300 contacts dans la table locale
- **Synchronisés** : 295 contacts vers Brevo (5 en erreur)

## Cas d'usage
- **Première installation** : Synchronisation initiale complète
- **Reset complet** : Après changement de configuration majeur
- **Audit complet** : Vérification de l'intégralité des données
- **Récupération** : Après corruption ou perte de données

## ⚠️ Attention
- **Durée** : Peut prendre plusieurs minutes selon le nombre de contacts
- **Ressources** : Consomme plus d'API calls que la synchronisation incrémentielle
- **Écrasement** : Supprime toutes les données de synchronisation existantes
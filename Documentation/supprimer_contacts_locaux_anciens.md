# Supprimer les contacts locaux trop anciens (>1 an)

## Description
Supprime de la base locale tous les contacts dont le **champ de date personnalisé** est plus ancien qu'un an.

## Processus détaillé

### 1. Récupération du champ de filtre
- Utilise le champ configuré dans les réglages (ex: `custom_80`)
- Ce champ doit contenir une date au format `YYYY-MM-DD`

### 2. Sélection des contacts à analyser
- Récupère tous les contacts de la table `wp_nelis_brevo_sync`
- Examine la valeur du champ de date personnalisé pour chaque contact

### 3. Identification des contacts anciens
Pour chaque contact :
- Vérifie si le champ de date existe et n'est pas vide
- Valide le format de date `YYYY-MM-DD`
- Compare la date avec la limite (1 an dans le passé)
- **Ancien** : Date > 1 an → À supprimer
- **Récent** : Date ≤ 1 an → À conserver

### 4. Suppression de la base locale
Pour chaque contact identifié comme ancien :
- Supprime l'enregistrement de `wp_nelis_brevo_sync`
- **Note** : Ne supprime **PAS** le contact de Brevo
- Log la suppression pour traçabilité

### 5. Statistiques retournées
- `total` : Nombre total de contacts analysés
- `to_delete` : Contacts identifiés comme trop anciens
- `deleted` : Contacts effectivement supprimés de la base locale

## Exemple concret
Si aujourd'hui = 2025-09-02 :
- Contact avec `custom_80` = "2024-08-01" → **À supprimer** (> 1 an)
- Contact avec `custom_80` = "2024-10-01" → **À conserver** (< 1 an)

## Cas d'usage
- Nettoyage automatique des données anciennes
- Respect des règles de rétention des données
- Optimisation de la taille de la base de données
- Maintenance périodique

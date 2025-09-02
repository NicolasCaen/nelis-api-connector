# Synchronisation incrémentielle

## Description
La synchronisation incrémentielle ne récupère que les contacts **créés ou modifiés** depuis la dernière synchronisation.

## Processus détaillé

### 1. Récupération de la date de référence
- Récupère la date de dernière synchronisation stockée dans `nelis_brevo_last_sync`
- Si aucune date n'existe, utilise la veille par défaut

### 2. Appels API Nelis
Fait deux appels séparés :
- **Contacts créés** : `get_contacts_created_since($last_sync)`
- **Contacts modifiés** : `get_contacts_updated_since($last_sync)`

### 3. Filtrage et traitement
Pour chaque contact récupéré :
- Vérifie s'il respecte le **filtre par date** (champ personnalisé < 1 an)
- Si valide, l'ajoute/met à jour dans la table locale
- Marque le statut comme `pending`

### 4. Synchronisation vers Brevo
- Synchronise tous les contacts `pending` vers Brevo
- Met à jour les statuts (`synced` ou `error`)

### 5. Mise à jour de la date
- Met à jour `nelis_brevo_last_sync` avec l'heure actuelle
- Prêt pour la prochaine synchronisation incrémentielle

## Avantages
- **Rapide** : Ne traite que les nouveaux/modifiés
- **Efficace** : Économise les ressources API
- **Automatisable** : Idéal pour les tâches cron quotidiennes

## Cas d'usage
- Synchronisation quotidienne automatique
- Mise à jour régulière des contacts
- Maintien de la cohérence des données

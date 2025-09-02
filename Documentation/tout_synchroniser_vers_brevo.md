# Tout synchroniser vers Brevo

## Description
Synchronise **tous les contacts en attente** de la table locale vers Brevo, sans limite de nombre.

## Processus détaillé

### 1. Vérification de la table
- Vérifie que la table `wp_nelis_brevo_sync` existe
- La crée si nécessaire

### 2. Récupération des contacts en attente
- Sélectionne tous les contacts avec le statut `pending`
- Aucune limite de nombre (contrairement à la sync normale)

### 3. Synchronisation par lots
Pour chaque contact `pending` :
- Prépare les données pour l'API Brevo
- Inclut tous les champs personnalisés (`custom_XX`)
- Ajoute le contact à la liste Brevo configurée

### 4. Gestion des résultats
- **Succès** : Marque le statut comme `synced`
- **Échec** : Marque le statut comme `error` avec message d'erreur
- Met à jour `last_sync` avec l'heure actuelle

### 5. Logs détaillés
- Log chaque contact traité
- Comptabilise le nombre total synchronisé
- Affiche les statistiques finales

## Différence avec les autres synchronisations
- **Sync complète** : Récupère depuis Nelis + synchronise vers Brevo
- **Sync incrémentielle** : Récupère les nouveaux depuis Nelis + synchronise vers Brevo  
- **Tout synchroniser** : Synchronise uniquement les contacts déjà en base locale

## Cas d'usage
- Relancer une synchronisation après une panne
- Forcer la synchronisation de tous les contacts en attente
- Rattraper les contacts non synchronisés

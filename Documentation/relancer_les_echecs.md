# Relancer les échecs

## Description
Remet en file d'attente tous les contacts qui ont échoué lors de la synchronisation vers Brevo et relance leur synchronisation.

## Processus détaillé

### 1. Identification des contacts en erreur
- Sélectionne tous les contacts avec le statut `error` dans la table locale
- Ces contacts ont échoué lors d'une précédente tentative de synchronisation vers Brevo

### 2. Remise en file d'attente
- Change le statut de `error` vers `pending` pour tous ces contacts
- Efface le message d'erreur précédent (`error_message = NULL`)
- Prépare les contacts pour une nouvelle tentative

### 3. Relance de la synchronisation
- Appelle automatiquement `sync_to_brevo()`
- Traite tous les contacts `pending` (y compris ceux remis en file)
- Tente à nouveau la synchronisation vers Brevo

### 4. Gestion des nouveaux résultats
Pour chaque contact retraité :
- **Succès** : Statut devient `synced`
- **Nouvel échec** : Statut redevient `error` avec nouveau message d'erreur

### 5. Statistiques
- Nombre de contacts remis en file d'attente
- Nombre de contacts effectivement synchronisés lors de la relance

## Causes d'échec courantes
- **Erreur API Brevo** : Limite de taux, problème réseau
- **Email invalide** : Format incorrect, domaine inexistant
- **Doublon** : Contact déjà présent dans Brevo
- **Champs manquants** : Données requises absentes

## Cas d'usage
- Après résolution d'un problème API Brevo
- Suite à une panne réseau temporaire
- Correction manuelle des données problématiques
- Maintenance périodique des échecs

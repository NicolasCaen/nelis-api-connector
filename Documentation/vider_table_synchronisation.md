# Vider la table de synchronisation

## Description
Supprime **toutes les données** de la table de synchronisation locale `wp_nelis_brevo_sync`.

## Processus détaillé

### 1. Confirmation utilisateur
- Affiche un popup de confirmation JavaScript
- Message : "Attention : Cette action va supprimer toutes les données de synchronisation. Continuer ?"
- L'utilisateur doit confirmer explicitement l'action

### 2. Exécution de la suppression
- Utilise la commande SQL `TRUNCATE TABLE wp_nelis_brevo_sync`
- **TRUNCATE** est plus rapide que DELETE pour vider complètement une table
- Remet le compteur auto-increment à zéro

### 3. Données supprimées
Toutes les informations suivantes sont perdues :
- Tous les contacts synchronisés depuis Nelis
- Tous les champs personnalisés (`custom_XX`)
- Historique des statuts de synchronisation
- Messages d'erreur
- Dates de synchronisation

### 4. Conséquences
Après vidage :
- La table existe toujours mais est vide
- Prochaine synchronisation complète repartira de zéro
- Tous les contacts devront être re-récupérés depuis Nelis
- Aucun impact sur les données dans Nelis ou Brevo

## ⚠️ Attention
Cette action est **irréversible** ! Toutes les données de synchronisation seront perdues définitivement.

## Cas d'usage
- Reset complet avant une nouvelle synchronisation
- Nettoyage après changement de configuration majeur
- Résolution de problèmes de corruption de données
- Test et développement

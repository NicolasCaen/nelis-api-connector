# Supprimer de Brevo les contacts absents localement

## Description
Supprime de Brevo tous les contacts qui ne sont **plus présents** dans la base de données locale.

## Processus détaillé

### 1. Récupération des emails Brevo
- Récupère tous les emails présents dans la liste Brevo configurée
- Utilise `getAllContactEmails()` par lots de 50 contacts
- Stocke les emails en minuscules pour comparaison

### 2. Récupération des emails locaux
- Sélectionne tous les emails de la table `wp_nelis_brevo_sync`
- Crée un tableau des emails présents localement

### 3. Identification des contacts à supprimer
- Compare les deux listes d'emails
- Identifie les contacts présents dans Brevo mais **absents localement**
- Ces contacts sont considérés comme obsolètes

### 4. Suppression dans Brevo
Pour chaque contact à supprimer :
- Appelle `deleteContactByEmail($email)` sur l'API Brevo
- **Succès** : Incrémente le compteur de suppression
- **Échec** : Log l'erreur et continue
- Ajoute un délai de 0.1 seconde entre chaque suppression (éviter surcharge API)

### 5. Statistiques retournées
- `total_brevo` : Nombre total de contacts dans Brevo
- `total_local` : Nombre total de contacts locaux
- `to_delete` : Nombre de contacts identifiés pour suppression
- `deleted` : Nombre de contacts effectivement supprimés

## ⚠️ Attention
Cette action est **irréversible** ! Les contacts supprimés de Brevo ne pourront pas être récupérés.

## Cas d'usage
- Nettoyage après filtrage des critères de synchronisation
- Suppression des contacts expirés (> 1 an)
- Maintenance de la liste Brevo
- Synchronisation "miroir" avec la base locale

# Vérifier la synchronisation avec Brevo

## Description
Compare les contacts de la base locale avec ceux présents dans Brevo pour détecter les incohérences de statut.

## Processus détaillé

### 1. Récupération des emails Brevo
- Récupère **tous les emails** présents dans la liste Brevo configurée
- Utilise la méthode `getAllContactEmails()` par lots de 50
- Stocke les emails en minuscules pour comparaison insensible à la casse

### 2. Récupération des contacts locaux
- Sélectionne tous les contacts de la table `wp_nelis_brevo_sync`
- Récupère leur email et statut actuel

### 3. Comparaison et correction
Pour chaque contact local :

#### Si marqué `synced` mais absent de Brevo
- **Problème détecté** : Contact supposé synchronisé mais introuvable dans Brevo
- **Correction** : Remet le statut à `pending` pour re-synchronisation
- **Log** : "Statut corrigé pour {email}: marqué comme en attente car absent de Brevo"

#### Si marqué `synced` et présent dans Brevo
- **OK** : Statut cohérent, aucune action

#### Si marqué `pending` ou `error`
- **Normal** : Aucune vérification nécessaire

### 4. Statistiques retournées
- `verified` : Contacts déjà avec le bon statut
- `fixed` : Contacts dont le statut a été corrigé
- `errors` : Incohérences détectées
- `in_brevo` : Contacts locaux présents dans Brevo
- `not_in_brevo` : Contacts locaux absents de Brevo

## Cas d'usage
- Audit de la synchronisation
- Détection de contacts "perdus" dans Brevo
- Correction automatique des statuts incohérents
- Vérification après une panne ou erreur API

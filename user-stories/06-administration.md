# Epic 6 — Administration du catalogue

Livrable : rubrique réservée aux administrateurs pour consulter les cartes importées et agir sur leur disponibilité au scan.

Un administrateur est un utilisateur disposant du rôle administrateur (cf. US-0.10). Il a aussi son propre stock, comme tout utilisateur, mais ne voit pas les stocks des autres.

Les champs importés depuis TCGdex sont en lecture seule : ils sont réécrits à chaque import.

## US-6.1 Accéder à l'administration — M

En tant qu'**administrateur**, je veux accéder à une rubrique d'administration afin de gérer le catalogue.

Critères d'acceptation :
- La rubrique et ses actions sont réservées au rôle administrateur, contrôlé côté serveur.
- Un utilisateur sans ce rôle reçoit un refus d'accès ; un visiteur non connecté est redirigé vers la connexion.
- Le lien vers la rubrique n'est affiché qu'aux administrateurs.

## US-6.2 Lister les cartes du catalogue — M

En tant qu'**administrateur**, je veux lister les cartes importées afin de contrôler le catalogue.

Critères d'acceptation :
- Liste paginée : image miniature, nom, extension, numéro, rareté, langue, statut (active, obsolète, exclue du scan).
- Taille de page par défaut : 50. L'administrateur peut la changer, jusqu'à 100 au maximum, et la valeur est plafonnée côté serveur.
- Nombre total de cartes affiché ; tri par défaut par extension puis numéro.

## US-6.3 Rechercher et filtrer les cartes — M

En tant qu'**administrateur**, je veux rechercher et filtrer les cartes afin de retrouver rapidement une référence.

Critères d'acceptation :
- Recherche par nom et par identifiant TCGdex, insensible à la casse et aux accents.
- Filtres : extension, numéro, rareté, langue, statut, image manquante, embedding manquant.
- Les filtres se cumulent ; recherche, filtres, tri et taille de page sont conservés d'une page à l'autre.

## US-6.4 Consulter le détail d'une carte — M

En tant qu'**administrateur**, je veux voir toutes les informations d'une carte afin de diagnostiquer un problème de reconnaissance ou d'import.

Critères d'acceptation :
- Image du catalogue et métadonnées importées.
- Statut, et dates du premier et du dernier import.
- État de l'embedding : présent ou non, modèle et date de calcul.
- Prix de référence enregistrés (source, devise, date), visibles uniquement ici.

## US-6.5 Agir sur une carte — S

En tant qu'**administrateur**, je veux agir sur une carte afin de corriger un problème sans refaire l'import.

Critères d'acceptation :
- Exclure une carte du scan ou la réintégrer : l'indicateur n'est jamais modifié par l'import.
- Recalculer l'embedding d'une carte.
- Retélécharger l'image d'une carte.
- Chaque action demande une confirmation et est protégée contre les requêtes forgées (CSRF).
- Une carte exclue du scan reste dans les stocks existants.

## US-6.6 Journaliser les actions d'administration — C

En tant qu'**administrateur**, je veux retrouver qui a fait quelle action afin de tracer les modifications du catalogue.

Critères d'acceptation :
- Chaque action de US-6.5 est enregistrée : administrateur, carte, action, date.
- Le journal est consultable dans la rubrique d'administration.

# User stories — Gestion de stock de cartes Pokémon par scan

## Vision

Application de gestion de stock de cartes Pokémon. L'utilisateur envoie une photo contenant une ou plusieurs cartes ; l'application reconnaît les cartes parmi celles du catalogue, propose les références les plus proches, et met à jour le stock de l'utilisateur après validation. Objectif : faciliter au maximum l'intégration de lots de cartes dans le stock.

## Personas

- **Utilisateur** : collectionneur ou revendeur, gère son stock de cartes.
- **Opérateur** : lance les commandes d'administration (import du catalogue, attribution du rôle administrateur) en ligne de commande.
- **Administrateur** : utilisateur disposant du rôle administrateur ; consulte et administre le catalogue importé depuis l'application.

## Epics

| Epic | Fichier | Contenu |
|------|---------|---------|
| 0 | [00-compte-authentification.md](00-compte-authentification.md) | Inscription, connexion, mot de passe perdu, accès à son stock |
| 1 | [01-catalogue.md](01-catalogue.md) | Import du catalogue TCGdex par commande, images, embeddings, prix de référence |
| 2 | [02-scan.md](02-scan.md) | Scan d'une image (1 à n cartes), sélection des candidats, validation, mise à jour du stock |
| 3 | [03-stock.md](03-stock.md) | Consultation et gestion du stock |
| 4 | [04-recherche-tri.md](04-recherche-tri.md) | Recherche, filtres et tri du stock |
| 5 | [05-export-csv.md](05-export-csv.md) | Export CSV du stock |
| 6 | [06-administration.md](06-administration.md) | Rubrique admin : liste, recherche et gestion des cartes du catalogue |

Dépendances : 2 requiert 0, 1 et 3 (le stock) ; 3 requiert 0 et 1 ; 4 et 5 requièrent 3 ; 6 requiert 0 et 1.

## Flux principal

1. L'utilisateur choisit la langue des cartes puis envoie une image contenant 1 à n cartes.
2. L'application détecte et découpe chaque carte, puis cherche les candidats dans le catalogue de cette langue.
3. Pour chaque carte reconnue, un écran propose les candidats, le plus proche étant présélectionné. L'utilisateur confirme ou choisit un autre candidat. Un seul candidat est proposé quand même. Aucun candidat : la carte est passée.
4. Écran de validation finale : les cartes sont regroupées par référence avec leur quantité ; les cartes non reconnues ou passées sont mises en évidence avec leur photo découpée et un avertissement : elles ne seront pas ajoutées.
5. À la validation, le stock de l'utilisateur est mis à jour, puis les images et données temporaires du scan sont supprimées.

Le scan ne dure que tant que l'utilisateur reste dans l'application : s'il quitte, il recommence.

## Architecture

- **Symfony + Twig** : backend et frontend. Symfony est le maître de la donnée et le seul écrivain en base.
- **Service Python** : prétraitement d'image (OpenCV) et vectorisation (DINOv2, modèle et taille à valider par le benchmark US-2.14). Deux responsabilités :
  - *Détection* : une image contenant 1 à n cartes donne les cartes détectées, découpées, redressées, redimensionnées et normalisées (suppression des marges incluse).
  - *Vectorisation* : une carte normalisée donne un vecteur. On ne vectorise jamais une image contenant plusieurs cartes : elle est d'abord découpée.
- **PostgreSQL + pgvector** : stockage des vecteurs et recherche des plus proches voisins, exécutée par Symfony.
- Le catalogue et les photos passent par le même prétraitement et le même modèle, tous deux portés par le service Python.
- Le service Python n'a aucun accès à la base : il reçoit une image et renvoie des résultats (découpes, vecteurs). Symfony écrit les vecteurs et interroge pgvector.

## Décisions

- **Référence** : une carte dans une seule langue. La même carte dans une autre langue est une autre référence (un enregistrement catalogue par couple identifiant TCGdex + langue).
- Le stock ne gère que : référence de carte et quantité.
- Pas de gestion de l'état, du prix d'achat, de la date, de la source, de l'emplacement ni de lots d'acquisition pour l'instant. Les variantes (normale, holo, reverse) ne sont pas distinguées.
- Une photo = une langue, choisie par l'utilisateur avant l'envoi ; la recherche est limitée au catalogue de cette langue.
- Les doublons d'une photo sont regroupés après validation de l'utilisateur, par référence choisie.
- Une carte non reconnue ne peut pas être ajoutée au stock. Le traitement de ce cas est reporté.
- Le stock n'est jamais modifié avant la validation finale.
- Aucun scan n'est persisté : pas de statut, pas de reprise, pas d'annulation. Les images envoyées et découpées sont jetées à la fin du scan.
- Une carte qui n'est plus renvoyée par l'API TCGdex est marquée obsolète, jamais supprimée.
- Les prix de revente issus de l'API TCGdex sont conservés en base ; ils ne sont pas exposés aux utilisateurs pour l'instant.
- L'interface est en français ; le nom d'une carte est affiché dans la langue de la carte.
- L'utilisateur garde la décision finale : le moteur propose, l'humain valide.
- La connexion est refusée tant que l'adresse email n'est pas confirmée. Un changement de mot de passe par lien reçu par email confirme aussi l'adresse.
- Le rôle administrateur est attribué par une commande console prenant l'email d'un compte existant ; aucune interface web ne le permet.
- Un administrateur a aussi son propre stock, mais ne voit pas ceux des autres utilisateurs.
- Les champs importés de TCGdex sont en lecture seule, même pour l'administrateur ; il peut seulement exclure une carte du scan, recalculer son embedding ou retélécharger son image.

## Hors périmètre actuel

- Marketplace, paiement, gestion des commandes.
- Réseau social, messagerie entre utilisateurs.
- Scraping de Leboncoin, Vinted ou Facebook.
- Suivi automatique des prix et alertes de marché.
- Grading assisté par IA.
- Reconnaissance de cartes qui se chevauchent.
- Application mobile native.
- État, prix d'achat, date d'achat, source, emplacement, lots d'acquisition, variantes.
- Accès de l'administrateur aux stocks des utilisateurs, modification manuelle des champs importés de TCGdex.

Reporté :
- Recours pour les cartes non reconnues (recherche manuelle, ajout depuis le catalogue, consultation du catalogue).
- Statuts de scan, reprise d'un scan interrompu, annulation d'un scan validé, conservation des images.
- Export au format Cardmarket (voir [05-export-csv.md](05-export-csv.md)).

## Principes transverses

- **Droits d'images** : les droits de réutilisation des illustrations du catalogue doivent être vérifiés avant toute diffusion commerciale.
- **Objectif de reconnaissance** : la bonne référence figure dans les candidats proposés dans la grande majorité des cas (seuil à fixer après benchmark).
- **Données privées** : le stock et les images temporaires d'un scan ne sont accessibles qu'à leur propriétaire.
- **Priorité MoSCoW** : **M**ust / **S**hould / **C**ould.
- **Identifiants** : `US-<epic>.<n>`. Les stories décrivent le comportement attendu, pas la technologie.

## Questions ouvertes

1. Modèle de vectorisation et dimension : DINOv2 (small, base ou large) à comparer avec une alternative lors du benchmark.
2. Durée de validité des liens de confirmation d'email (1 heure par défaut).
3. Langues du catalogue importées : toutes celles de TCGdex, ou une liste restreinte (FR, EN, JP) ?
4. Seuil de similarité en dessous duquel aucun candidat n'est proposé : à calibrer par benchmark.
5. Nombre maximal de cartes par photo (20 au départ) : bloquant ou simple avertissement au-delà ?
6. Durée d'expiration des données temporaires d'un scan après inactivité.
7. Objectifs de performance (durée de traitement d'une photo de 20 cartes, taille de stock visée) et matériel du service Python (CPU ou GPU) : à fixer après le benchmark.

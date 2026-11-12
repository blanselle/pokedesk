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

## État d'avancement

États : **Fait**, **Partiel** (le reste est indiqué), **À faire**.

| Story | État | Remarque |
|-------|------|----------|
| US-0.1 à 0.8, 0.10 | Fait | |
| US-0.9 | À faire | Gestion du compte |
| US-1.1 | Partiel | La commande `app:catalog:import` s'exécute au premier plan, pas en arrière-plan ; les cartes numériques `tcgp` sont exclues |
| US-1.2 | Fait | |
| US-1.3 | Partiel | Calcul par la commande `app:catalog:embed`, lancée à la main après l'import ; recherche exacte sans index |
| US-1.4 | Partiel | Les prix sont conservés dans le détail brut de la carte, sans champs dédiés |
| US-1.5 | À faire | |
| US-2.1 | Partiel | Consignes affichées sur l'écran d'envoi ; la limite de 20 cartes n'est pas contrôlée |
| US-2.2 | Partiel | JPEG, PNG, WebP jusqu'à 40 Mo, langue mémorisée, EXIF non conservé ; HEIC et résolution minimale non gérés |
| US-2.3 | À faire | |
| US-2.4 | Partiel | Détecteur validé sur 2 photos seulement ; carte à l'envers gérée ; dos de carte non traité |
| US-2.5 | Fait | Seuil 0,70 provisoire |
| US-2.6 | Partiel | Message en cas de service indisponible ; pas de progression ni de délai maximal |
| US-2.7 | Partiel | Pas de raccourcis clavier |
| US-2.8, 2.9 | Fait | |
| US-2.10 | Fait | Résumé simplifié : cartes ajoutées et références, sans distinguer lignes créées et augmentées |
| US-2.11 | Partiel | Cache de 1 h ; un nouveau scan remplace le précédent sans avertissement ; pas de purge explicite à la déconnexion |
| US-2.12 | Partiel | Taille maximale faite ; pas de limite de cartes ni d'envois par période |
| US-2.13 | À faire | |
| US-2.14 | Fait | CLIP ViT-B/32 retenu ; les scripts de mesure ont été supprimés |
| US-3.1 | Fait | En-tête de synthèse : totaux et répartition par langue, extension, rareté et catégorie |
| US-3.2, 3.3 | Fait | |
| US-3.4, 3.5 | À faire | |
| US-4.1 | Fait | |
| US-4.2 | Partiel | Langue, extension, numéro, rareté, catégorie et quantité min/max, avec nombre de résultats et réinitialisation ; une seule valeur par filtre |
| US-4.3 | À faire | |
| US-4.4 | Partiel | Cases à cocher pour la suppression ; ni page entière ni tous les résultats |
| US-5.x | À faire | |
| US-6.1, 6.2 | Fait | |
| US-6.3 | Partiel | Pas de filtre « embedding manquant » |
| US-6.4 | Partiel | État de l'embedding non affiché ; les prix de référence le sont |
| US-6.5, 6.6 | À faire | |

## Flux principal

1. L'utilisateur choisit la langue des cartes puis envoie une image contenant 1 à n cartes.
2. L'application détecte et découpe chaque carte, puis cherche les candidats dans le catalogue de cette langue.
3. Pour chaque carte reconnue, un écran propose les candidats, le plus proche étant présélectionné. L'utilisateur confirme ou choisit un autre candidat. Un seul candidat est proposé quand même. Aucun candidat : la carte est passée.
4. Écran de validation finale : les cartes sont regroupées par référence avec leur quantité ; les cartes non reconnues ou passées sont mises en évidence avec leur photo découpée et un avertissement : elles ne seront pas ajoutées.
5. À la validation, le stock de l'utilisateur est mis à jour, puis les images et données temporaires du scan sont supprimées.

Le scan ne dure que tant que l'utilisateur reste dans l'application : s'il quitte, il recommence.

## Architecture

- **Symfony + Twig** : backend et frontend. Symfony est le maître de la donnée et le seul écrivain en base.
- **Service Python** (FastAPI, sur CPU) : prétraitement d'image (OpenCV) et vectorisation (CLIP ViT-B/32, vecteurs de 512 dimensions, retenu face à DINOv2 small et base). Deux responsabilités :
  - *Détection* : une image contenant 1 à n cartes donne les cartes détectées, découpées, redressées, redimensionnées et normalisées (suppression des marges incluse). Les cartes qui se touchent sont séparées selon le format d'une carte (63/88).
  - *Vectorisation* : une carte normalisée donne un vecteur. On ne vectorise jamais une image contenant plusieurs cartes : elle est d'abord découpée. Chaque découpe est vectorisée dans son orientation d'origine et retournée de 180° (carte posée à l'envers).
- **PostgreSQL + pgvector** : stockage des vecteurs et recherche des plus proches voisins, exécutée par Symfony. La recherche est exacte (distance cosinus, sans index) : elle suffit pour les quelque 35 000 vecteurs du catalogue FR + EN.
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
- Aucun scan n'est persisté en base : pas de statut, pas de reprise, pas d'annulation. La photo envoyée n'est jamais enregistrée ; seules les découpes et les choix en cours vivent dans un cache serveur (1 heure d'inactivité au plus) et sont jetés à la fin du scan.
- MVP de la reconnaissance : le moteur propose jusqu'à 5 candidats par carte ; s'il y en a plusieurs, l'utilisateur tranche à la main, et il peut indiquer qu'aucun ne correspond.
- Une photo peut peser jusqu'à 40 Mo (photos de smartphone en haute résolution).
- L'utilisateur peut corriger son stock : ajuster la quantité d'une ligne (descendre à 0 supprime la ligne après confirmation) et supprimer plusieurs lignes à la fois, toujours après confirmation.
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
- Cartes numériques Pokémon TCG Pocket (série TCGdex `tcgp`) : exclues de l'import, supprimées du catalogue.

Reporté :
- Recours pour les cartes non reconnues (recherche manuelle, ajout depuis le catalogue, consultation du catalogue).
- Statuts de scan, reprise d'un scan interrompu, annulation d'un scan validé, conservation des images.
- Export au format Cardmarket (voir [05-export-csv.md](05-export-csv.md)).

## Principes transverses

- **Droits d'images** : les droits de réutilisation des illustrations du catalogue doivent être vérifiés avant toute diffusion commerciale.
- **Objectif de reconnaissance** : la bonne référence figure dans les candidats proposés dans la grande majorité des cas (seuil provisoire à calibrer sur des photos réelles).
- **Données privées** : le stock et les images temporaires d'un scan ne sont accessibles qu'à leur propriétaire.
- **Priorité MoSCoW** : **M**ust / **S**hould / **C**ould.
- **Identifiants** : `US-<epic>.<n>`. Les stories décrivent le comportement attendu, pas la technologie.

## Questions ouvertes

1. ~~Modèle de vectorisation et dimension~~ : tranché, CLIP ViT-B/32 en 512 dimensions.
2. Durée de validité des liens de confirmation d'email (1 heure par défaut).
3. ~~Langues du catalogue importées~~ : tranché pour l'instant, FR et EN (paramétrable à l'import) ; JP et les autres langues restent à décider.
4. Seuil de similarité en dessous duquel aucun candidat n'est proposé : 0,70 à titre provisoire, à calibrer sur des photos réelles.
5. Nombre maximal de cartes par photo (20 au départ) : bloquant ou simple avertissement au-delà ? Non contrôlé pour l'instant.
6. ~~Durée d'expiration des données temporaires d'un scan~~ : tranché, 1 heure d'inactivité.
7. Objectifs de performance : sur CPU (20 cœurs), la détection et la vectorisation d'une photo de 1 à 4 cartes prennent environ 0,5 s ; une photo de 20 cartes et la taille de stock visée restent à mesurer.

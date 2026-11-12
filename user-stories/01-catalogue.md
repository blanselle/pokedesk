# Epic 1 — Catalogue

Livrable : catalogue de référence complet, importé par commande en arrière-plan.

Persona : **opérateur** (ligne de commande). Le catalogue est partagé entre tous les utilisateurs.

## US-1.1 Importer tout le catalogue par commande — M

En tant qu'**opérateur**, je veux lancer une commande qui récupère l'ensemble des cartes de l'API TCGdex afin de disposer du catalogue de référence.

Critères d'acceptation :
- Une commande console importe les extensions et toutes leurs cartes.
- L'import s'exécute en arrière-plan : la commande déclenche le traitement et ne bloque pas jusqu'à la fin.
- Options : restreindre à une langue ou une extension, limiter le nombre de cartes.
- Pour chaque carte, quand la source les fournit : identifiant TCGdex stable, nom, extension, identifiant de série, numéro, rareté, langue, illustration.
- La commande est idempotente : la relancer met à jour les cartes existantes et ajoute les nouvelles, sans doublon.
- Une référence est une carte dans une seule langue : un enregistrement catalogue par couple (identifiant TCGdex, langue). La même carte dans une autre langue est une autre référence.
- Une carte présente en base mais absente de l'API après un import est marquée obsolète, jamais supprimée : elle reste dans les stocks existants, n'est plus proposée comme candidat, et redevient active si elle réapparaît dans l'API.
- Les erreurs de source sont journalisées sans interrompre l'import ; un rapport final indique cartes créées, mises à jour et en erreur.

## US-1.2 Conserver localement les images du catalogue — M

En tant qu'**opérateur**, je veux que les images des cartes soient téléchargées et stockées localement afin de les comparer sans dépendre du réseau.

Critères d'acceptation :
- Chaque carte importée a un chemin d'image local.
- Une image déjà présente n'est pas retéléchargée.
- Une image manquante ou invalide est signalée dans le rapport d'import ; la carte concernée n'est pas proposable au scan, sans repli sur l'image d'une autre langue.

## US-1.3 Calculer et indexer les embeddings — M

En tant qu'**opérateur**, je veux que chaque image du catalogue ait un embedding afin de permettre la recherche par similarité.

Critères d'acceptation :
- Le calcul suit l'import en arrière-plan.
- Chaque image est normalisée puis vectorisée une à une par le service Python, avec le même prétraitement et le même modèle que pour les photos utilisateur (cf. US-2.4).
- Seul Symfony écrit les vecteurs en base.
- Le modèle et sa version sont enregistrés avec le vecteur ; la dimension vient de la sortie réelle du modèle.
- Les vecteurs sont indexés pour une recherche des plus proches voisins, filtrable par langue.
- Seules les cartes sans embedding, ou dont le modèle a changé, sont recalculées.

## US-1.4 Conserver les prix de référence — S

En tant qu'**opérateur**, je veux conserver en base les prix de revente fournis par l'API TCGdex afin de pouvoir les exploiter plus tard.

Critères d'acceptation :
- Les prix disponibles dans l'API sont enregistrés avec la carte, avec leur source, leur devise et leur date de récupération ; seules les dernières valeurs sont conservées, sans historique.
- Ils sont mis à jour à chaque relance de la commande d'import.
- Ils ne sont exposés à aucun écran utilisateur pour l'instant.

## US-1.5 Vérifier les droits d'usage des images — M (non fonctionnel)

Les conditions de réutilisation des illustrations sont documentées afin d'éviter un usage non autorisé en diffusion commerciale.

Critères d'acceptation :
- Les conditions de TCGdex et des éditeurs d'images sont documentées.
- Décision tracée avant toute diffusion publique ou commerciale.


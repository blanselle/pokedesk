# Epic 3 — Stock

Livrable : consultation et gestion du stock de l'utilisateur connecté.

Une ligne de stock = une référence du catalogue (une carte dans une seule langue) + une quantité. Une seule ligne par utilisateur et par référence. Pas d'état, de prix, de date, de source, d'emplacement, de variante ni de lots pour l'instant.

L'ajout de cartes au stock se fait uniquement par le scan (US-2.10).

## US-3.1 Consulter son stock — M

En tant qu'**utilisateur**, je veux voir mon stock afin de savoir quelles cartes je possède.

Critères d'acceptation :
- Liste paginée avec image, nom, extension, numéro, rareté, langue et quantité.
- Seul le stock de l'utilisateur connecté est visible.
- Une carte devenue obsolète dans le catalogue reste affichée.
- Un stock vide affiche un appel à l'action vers le scan.

## US-3.2 Ajuster la quantité d'une ligne — M

En tant qu'**utilisateur**, je veux augmenter ou diminuer la quantité d'une carte afin de corriger mon stock.

Critères d'acceptation :
- Quantité entière, jamais négative.
- Passer à 0 demande une confirmation puis supprime la ligne.
- Les modifications simultanées ne perdent aucune quantité.

## US-3.3 Supprimer des lignes du stock — M

En tant qu'**utilisateur**, je veux supprimer une ou plusieurs lignes afin de retirer des cartes saisies par erreur.

Critères d'acceptation :
- Confirmation avant suppression.
- Suppression possible sur une sélection de lignes.

## US-3.4 Consulter le détail d'une carte du stock — S

En tant qu'**utilisateur**, je veux voir le détail d'une carte de mon stock.

Critères d'acceptation :
- Image du catalogue, métadonnées (nom, extension, numéro, rareté, langue) et quantité.
- Les prix de référence ne sont pas affichés pour l'instant.

## US-3.5 Consulter l'historique des mouvements — C

En tant qu'**utilisateur**, je veux consulter l'historique des entrées et sorties afin de tracer l'évolution de mon stock.

Critères d'acceptation :
- Chaque ajout, modification de quantité et suppression crée un mouvement non modifiable (date, type, quantité).
- L'historique d'une carte est consultable.

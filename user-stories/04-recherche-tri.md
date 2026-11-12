# Epic 4 — Recherche, filtres et tri

Livrable : retrouver rapidement des cartes dans son stock.

## US-4.1 Rechercher par nom — M

En tant qu'**utilisateur**, je veux rechercher dans mon stock par nom de Pokémon ou de carte.

Critères d'acceptation :
- Insensible à la casse et aux accents, correspondance partielle acceptée.

## US-4.2 Filtrer le stock — M

En tant qu'**utilisateur**, je veux combiner des filtres afin de retrouver un sous-ensemble de cartes.

Critères d'acceptation :
- Filtres : extension, numéro, rareté, langue, quantité (min/max).
- Plusieurs valeurs possibles pour extension, rareté et langue.
- Les filtres se cumulent ; le nombre de résultats est affiché ; réinitialisation en un geste.

## US-4.3 Trier le stock — S

En tant qu'**utilisateur**, je veux trier les résultats.

Critères d'acceptation :
- Tri croissant ou décroissant sur nom, extension, numéro, rareté, langue, quantité.
- Le tri est conservé avec la pagination et les filtres.

## US-4.4 Sélectionner des cartes — S

En tant qu'**utilisateur**, je veux sélectionner des lignes (individuellement, page entière ou tous les résultats filtrés) afin d'agir en lot, notamment pour l'export.

Critères d'acceptation :
- Compteur de lignes sélectionnées.
- La sélection « tous les résultats » porte sur le filtre courant, pas seulement sur la page affichée.

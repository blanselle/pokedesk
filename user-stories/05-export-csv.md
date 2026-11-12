# Epic 5 — Export CSV

Livrable : export du stock en CSV.

## US-5.1 Exporter le stock en CSV — S

En tant qu'**utilisateur**, je veux exporter tout ou partie de mon stock en CSV afin de l'utiliser dans un tableur.

Critères d'acceptation :
- Périmètre : stock complet, résultats filtrés ou sélection (cf. US-4.4).
- Colonnes : identifiant TCGdex, nom, extension, numéro, rareté, langue, quantité.
- Encodage UTF-8, séparateur et format documentés, ouverture correcte dans un tableur courant.
- Valeurs correctement échappées et protégées contre l'injection de formules (valeurs commençant par `=`, `+`, `-`, `@`).
- Export vide : message explicite, pas de fichier vide.
- Seul le stock de l'utilisateur connecté est exporté.

## US-5.2 Exporter de gros volumes — C

En tant qu'**utilisateur**, je veux exporter plusieurs milliers de lignes sans blocage.

Critères d'acceptation :
- Génération en flux ou en tâche asynchrone, fichier téléchargeable une fois prêt.

## US-5.3 Exporter au format Cardmarket — C (reporté)

Reporté tant que les prix et les états ne sont pas gérés côté utilisateur : l'import Cardmarket les requiert.

À cadrer le moment venu :
- Format validé sur la documentation actuelle et un fichier d'import réel.
- Correspondance entre identifiants TCGdex et Cardmarket à constituer, sans la présumer.
- Prix, état et langue à fournir ou à déduire.

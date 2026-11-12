# Epic 2 — Scan et mise à jour du stock

Livrable : à partir d'une image contenant 1 à n cartes, reconnaissance, sélection par l'utilisateur, validation finale et mise à jour du stock.

Une image avec une seule carte suit exactement le même flux qu'une image avec plusieurs cartes.

Le scan n'est pas persisté : il dure tant que l'utilisateur reste dans l'application. S'il quitte, il recommence.

## US-2.1 Consulter les consignes de prise de vue — M

En tant qu'**utilisateur**, je veux voir les consignes de prise de vue afin d'obtenir une bonne détection.

Critères d'acceptation :
- Consignes affichées avant l'envoi et accessibles à tout moment depuis l'écran de scan.
- Contenu : cartes d'une seule langue par photo, maximum initial de 20 cartes (configurable), disposition en grille sans chevauchement avec espacement régulier, même orientation, face visible, surface unie et contrastée, éclairage homogène sans flash ni reflets, appareil parallèle à la surface, résolution suffisante.

## US-2.2 Choisir la langue et envoyer une image — M

En tant qu'**utilisateur**, je veux choisir la langue des cartes puis envoyer une image afin de lancer la reconnaissance.

Critères d'acceptation :
- La langue est obligatoire et choisie avant l'envoi ; elle est mémorisée comme valeur par défaut au scan suivant.
- Formats d'image courants acceptés (JPEG, PNG, WebP, HEIC si possible) ; autres formats, taille excessive ou résolution insuffisante refusés avec un message clair.
- L'écran est utilisable sur mobile, avec prise de photo directe depuis le navigateur.
- Les métadonnées de l'image (EXIF, dont la géolocalisation) ne sont pas conservées.
- La recherche de candidats est limitée au catalogue de la langue choisie.

## US-2.3 Contrôler la qualité de l'image — S

En tant qu'**utilisateur**, je veux être averti d'un problème de photo avant le traitement afin de la reprendre.

Critères d'acceptation :
- Avertissement pour : cartes coupées, cartes qui se touchent, détection incomplète, flou, luminosité insuffisante, nombre de cartes supérieur à la limite.
- L'utilisateur peut reprendre la photo ou forcer le traitement.

## US-2.4 Détecter, découper et normaliser les cartes — M

Chaque carte est détectée, découpée, redressée et son orientation normalisée afin d'obtenir des entrées comparables au catalogue.

Critères d'acceptation :
- Les quatre coins de chaque carte sont localisés et chaque carte est découpée individuellement, avant toute vectorisation.
- Les marges autour de la carte sont supprimées et la carte est redimensionnée à une taille standard.
- Une carte tournée de 180° est traitée comme les autres.
- Une carte posée face cachée (dos visible) n'est jamais associée à une référence : elle est non reconnue.
- Le même prétraitement est appliqué aux images catalogue (cf. US-1.3).
- Les zones qui ne sont pas des cartes sont ignorées.
- Chaque découpe conserve sa position d'origine dans l'image.

## US-2.5 Rechercher les candidats de chaque carte — M

Les références les plus proches de chaque carte découpée sont recherchées afin d'être proposées à l'utilisateur.

Critères d'acceptation :
- Chaque carte découpée est vectorisée individuellement, puis comparée au catalogue.
- Jusqu'à 5 candidats par carte, triés par similarité décroissante, issus du catalogue de la langue choisie.
- Les cartes marquées obsolètes ou exclues du scan ne sont pas proposées.
- Un candidat dont la similarité est sous un seuil configurable n'est pas proposé ; une carte sans candidat est non reconnue.
- Chaque candidat expose : image, nom, extension, numéro, rareté, langue.

## US-2.6 Suivre le traitement et gérer les pannes — S

En tant qu'**utilisateur**, je veux un retour pendant le traitement afin de savoir où il en est et quoi faire en cas de problème.

Critères d'acceptation :
- Une progression est affichée pendant le traitement.
- Si le service de traitement d'image est indisponible ou dépasse un délai maximal, un message clair est affiché et l'utilisateur peut relancer le scan.
- Un échec n'a aucun effet sur le stock.
- Quitter la page pendant le traitement abandonne le scan.

## US-2.7 Sélectionner le candidat de chaque carte — M

En tant qu'**utilisateur**, je veux, pour chaque carte reconnue, choisir le candidat qui correspond afin d'identifier ma carte.

Critères d'acceptation :
- Un écran par carte reconnue affiche la photo découpée et ses candidats.
- Le candidat le plus proche est présélectionné ; valider ne demande qu'une action.
- Un candidat unique est proposé quand même, sans validation automatique.
- Chaque candidat montre de quoi le départager d'une réimpression : image, extension, numéro, rareté.
- L'utilisateur peut choisir un autre candidat ou indiquer qu'aucun ne correspond (la carte est alors passée).
- Une carte sans candidat est passée automatiquement et n'apparaît pas à cet écran.
- Navigation précédent / suivant et raccourcis clavier ; un choix déjà fait peut être modifié.

## US-2.8 Valider le lot sur un écran récapitulatif — M

En tant qu'**utilisateur**, je veux voir un récapitulatif avant d'écrire dans mon stock afin de vérifier le lot.

Critères d'acceptation :
- Les cartes validées sont listées par référence avec image, nom, extension, numéro, langue et quantité.
- Les cartes non validées (non reconnues, ou passées) sont mises en évidence avec leur photo découpée et un message indiquant qu'elles n'ont pas été reconnues et ne seront pas ajoutées.
- Les compteurs sont affichés : cartes détectées, validées, non validées.
- Une carte non reconnue ne peut pas être ajoutée au stock : aucun recours n'est proposé pour l'instant.
- L'utilisateur peut revenir à la sélection d'une carte qui avait des candidats (par exemple une carte passée par erreur).

## US-2.9 Regrouper les doublons après validation — M

En tant qu'**utilisateur**, je veux que les exemplaires identiques d'une même image soient regroupés afin d'ajouter la bonne quantité en une seule ligne.

Critères d'acceptation :
- Le regroupement se fait sur la référence catalogue choisie par l'utilisateur, après sa validation, et non sur la ressemblance visuelle.
- La quantité de chaque ligne est le nombre de cartes regroupées.
- L'utilisateur peut ajuster la quantité d'une ligne ou retirer la ligne avant la validation finale.

## US-2.10 Mettre à jour le stock à la validation — M

En tant qu'**utilisateur**, je veux que la validation finale mette mon stock à jour afin que le lot soit intégré.

Critères d'acceptation :
- Le stock n'est modifié qu'à la validation finale.
- Si la référence existe déjà dans le stock de l'utilisateur, sa quantité est augmentée ; sinon une ligne est créée.
- L'opération est atomique (tout ou rien) et protégée contre le double envoi.
- L'incrément de quantité est sûr en cas de validations simultanées sur la même référence : aucune quantité n'est perdue.
- Un résumé est affiché : lignes créées, quantités augmentées, cartes non ajoutées.
- Seul le stock de l'utilisateur connecté est modifié.

## US-2.11 Ne conserver les images que le temps du scan — M

En tant qu'**utilisateur**, je veux que mes photos ne soient pas conservées au-delà du scan afin de protéger ma vie privée.

Critères d'acceptation :
- L'image envoyée, les découpes et les choix en cours n'existent que le temps du scan.
- Ils sont supprimés à la validation finale, à l'abandon (nouveau scan, déconnexion, départ de l'application) et après une inactivité dépassant une durée configurable.
- Aucun historique de scan, aucune liste de scans, aucune reprise ni annulation.
- Seul l'utilisateur propriétaire peut accéder à ses images temporaires, via des adresses non devinables et un contrôle d'accès.
- Un seul scan actif par utilisateur : en lancer un nouveau remplace le précédent, avec un avertissement si un scan est en cours.

## US-2.12 Limiter l'usage du scan — S

Les envois d'images sont limités afin de préserver les ressources de traitement.

Critères d'acceptation :
- Taille maximale de fichier et nombre maximal de cartes par photo définis (cf. US-2.1).
- Limitation du nombre d'envois par utilisateur sur une période donnée, avec message explicite.

## US-2.13 Corriger une détection erronée — C

En tant qu'**utilisateur**, je veux supprimer une détection parasite ou ajuster les coins d'une carte afin de ne pas refaire la photo.

Critères d'acceptation :
- Suppression d'une découpe.
- Ajustement des coins d'une carte avec nouvelle recherche des candidats.

## US-2.14 Mesurer la qualité de reconnaissance — M (benchmark)

La présence de la bonne carte dans les candidats est mesurée afin de valider l'approche avant de construire le reste.

Critères d'acceptation :
- Jeu de photos de test étiqueté.
- Rapport : taux de bonne référence en première position et dans les 5 candidats, cas d'échec, effet du seuil de similarité.
- Seuil d'acceptation défini et décision go / no-go tracée.

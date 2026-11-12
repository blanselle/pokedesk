# Epic 0 — Compte et authentification

Livrable : interfaces de création de compte, de connexion et de réinitialisation de mot de passe ; accès à sa collection une fois connecté.

Chaque utilisateur possède sa propre collection (son stock) : le stock, l'historique et les scans associés sont strictement privés.

## US-0.1 Créer un compte — M

En tant que **visiteur**, je veux créer un compte afin d'accéder à ma collection.

Critères d'acceptation :
- Formulaire d'inscription : email, mot de passe, confirmation du mot de passe.
- L'email doit être valide et unique ; message clair en cas de doublon (sans faciliter l'énumération des comptes si possible).
- Règles de robustesse du mot de passe affichées et appliquées (longueur minimale, refus des mots de passe compromis ou trop courants).
- Le mot de passe est stocké haché, jamais en clair.
- Protection contre les soumissions automatisées (CSRF, limitation de débit).
- Après inscription : un email de vérification est envoyé (cf. US-0.2), puis l'utilisateur est redirigé vers la connexion ou sa collection selon la politique retenue.

## US-0.2 Vérifier son adresse email — S

L'application vérifie l'adresse email d'un nouveau compte afin de limiter les comptes frauduleux et garantir le fonctionnement de la réinitialisation.

Critères d'acceptation :
- Un lien de vérification signé et à durée limitée est envoyé par email.
- Un lien expiré ou invalide affiche un message clair et permet de redemander un email.
- Un compte non vérifié peut être limité (à définir : accès bloqué ou fonctionnalités restreintes).

## US-0.3 Se connecter — M

En tant qu'**utilisateur**, je veux me connecter avec mon email et mon mot de passe afin d'accéder à ma collection.

Critères d'acceptation :
- Formulaire de connexion : email, mot de passe.
- Message d'erreur générique en cas d'échec (ne révèle pas si l'email existe).
- Limitation des tentatives (anti brute force) avec message explicite en cas de blocage temporaire.
- Protection CSRF.
- Après connexion réussie, redirection vers son stock.
- Un utilisateur déjà connecté qui visite la page de connexion est redirigé vers sa collection.

## US-0.4 Rester connecté — S

En tant qu'**utilisateur**, je veux pouvoir rester connecté sur mon appareil afin de ne pas me reconnecter à chaque visite.

Critères d'acceptation :
- Case « Se souvenir de moi » sur le formulaire de connexion.
- Session persistante à durée limitée et révocable à la déconnexion.

## US-0.5 Se déconnecter — M

En tant qu'**utilisateur connecté**, je veux me déconnecter afin de protéger mon compte sur un appareil partagé.

Critères d'acceptation :
- Action de déconnexion accessible depuis toutes les pages.
- La session et le jeton « se souvenir de moi » sont invalidés.
- Retour à la page de connexion.

## US-0.6 Demander la réinitialisation d'un mot de passe perdu — M

En tant qu'**utilisateur**, je veux demander la réinitialisation de mon mot de passe afin de retrouver l'accès à mon compte.

Critères d'acceptation :
- Lien « Mot de passe oublié ? » sur la page de connexion.
- Formulaire demandant l'email.
- Le message de confirmation est identique que l'email existe ou non.
- Si le compte existe, un email contenant un lien de réinitialisation unique, signé et à durée limitée est envoyé.
- Limitation du nombre de demandes par adresse et par IP.

## US-0.7 Définir un nouveau mot de passe — M

En tant qu'**utilisateur**, je veux définir un nouveau mot de passe via le lien reçu par email.

Critères d'acceptation :
- Le lien est à usage unique et expire (durée à définir, ex. 1 heure).
- Un lien invalide, expiré ou déjà utilisé affiche un message clair et propose une nouvelle demande.
- Formulaire : nouveau mot de passe + confirmation, mêmes règles de robustesse qu'à l'inscription.
- Après changement, les sessions existantes sont invalidées et l'utilisateur est invité à se connecter.
- Un email de notification de changement de mot de passe est envoyé.

## US-0.8 Accéder à sa collection une fois connecté — M

En tant qu'**utilisateur connecté**, je veux arriver sur ma collection après connexion afin de la consulter immédiatement.

Critères d'acceptation :
- La page d'accueil après connexion est la liste du stock de l'utilisateur (cf. Epic 3).
- Une collection vide affiche un état vide avec un appel à l'action « Scanner mes cartes ».
- Un utilisateur ne voit et ne modifie que ses propres données (stock, mouvements, scans, exports) : l'accès à la ressource d'un autre utilisateur est refusé.
- Un visiteur non connecté qui tente d'accéder à une page protégée est redirigé vers la connexion, puis ramené à la page demandée après authentification.

## US-0.9 Gérer son compte — C

En tant qu'**utilisateur connecté**, je veux modifier mon email et mon mot de passe, ou supprimer mon compte.

Critères d'acceptation :
- Changement de mot de passe : demande du mot de passe actuel.
- Changement d'email : revalidation de la nouvelle adresse.
- Suppression de compte : confirmation explicite et suppression du stock et des données associées (RGPD).

## US-0.10 Promouvoir un utilisateur administrateur — M

En tant qu'**opérateur**, je veux donner le rôle administrateur à un utilisateur par commande afin de désigner les administrateurs.

Critères d'acceptation :
- Une commande console prend l'adresse email d'un compte existant en paramètre et lui attribue le rôle administrateur.
- Email inconnu : erreur explicite, aucun compte créé.
- La commande est idempotente : relancer sur un administrateur ne change rien.
- Le retrait du rôle est une option de la même commande.
- Aucune interface web ne permet de s'attribuer ou d'attribuer le rôle administrateur.
- Le rôle prend effet à la prochaine connexion ou au prochain contrôle d'accès, sans autre action.

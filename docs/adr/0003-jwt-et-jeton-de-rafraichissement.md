# ADR-0003 — JWT d'accès court et jeton de rafraîchissement rotatif

| Élément                        | Valeur                                                                                                                                                                                                                                                            |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                                                                                           |
| Date                           | 2026-11-23                                                                                                                                                                                                                                                        |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                                                                                           |
| Décision du cahier des charges | B2 (§0.2) — authentification JWT ; B3 (§0.2) — CSRF découlant du JWT ; B9 (§0.2) — mots de passe et A2F                                                                                                                                                           |
| Portée                         | Mécanisme d'authentification et de session de l'API : format des jetons, durées, stockage côté client et côté serveur, révocation, protection CSRF, paramètres de mot de passe et articulation avec l'A2F. Ne couvre ni les Voters (ADR-0006) ni les comptes SGBD |
| Tickets liés                   | #4 (C-03) · F-03, F-04, P-04, P-06                                                                                                                                                                                                                                |

## Contexte

L'API est consommée par une SPA (ADR-0001) et devra l'être demain par un client mobile (§18). Léa (P1) et Marcus (P2) se connectent depuis un téléphone et ne veulent pas ressaisir leur mot de passe à chaque écran ; ils veulent une session persistante sans perdre en sécurité (BX-03). Marcus protège un compte qui contient des clés payées et attend une A2F (BX-07). Nathalie (P5) veut qu'un vol de jeton ait un impact limité et que la révocation soit immédiate (BX-16, BX-18). Hugo (P7) veut une API exploitable et testable, avec une documentation à jour (BX-23, BX-24). Enfin O3 exige que les clés et les données personnelles soient protégées, et le §11.2 (A07) cite explicitement « JWT court, rotation du jeton de rafraîchissement, limitation de débit, A2F TOTP ».

Le cahier des charges fixe le fonctionnement au §11.4 et les durées : jeton d'accès **15 min**, jeton de rafraîchissement **7 j**. Il impose aussi le stockage : jeton d'accès **en mémoire** dans la SPA, jamais dans `localStorage` ; jeton de rafraîchissement dans un cookie `HttpOnly; Secure; SameSite=Strict; Path=/api/token/refresh`, enregistré dans Redis et donc révocable. La conséquence CSRF est tirée au même endroit (B3) : les appels porteurs de l'en-tête `Authorization` ne sont pas exposés, seule la route de rafraîchissement utilise un cookie et doit être protégée.

Deux contraintes techniques encadrent le choix. Redis est déjà retenu pour les données volatiles (ADR-0002), donc la révocation peut s'appuyer sur un stockage à TTL plutôt que sur une table supplémentaire. Et l'A2F est obligatoire pour les rôles back-office (BX-18), ce qui impose deux étapes de connexion, donc deux jeux de jetons successifs.

Un point compte pour la suite : le cahier des charges ne décrit pas seulement un format de jeton, il décrit un **cycle de vie** — émission, rafraîchissement, rotation, révocation, détection de réutilisation. C'est ce cycle, et non le JWT en lui-même, qui porte la sécurité ; chaque étape doit donc être implémentée et testée, y compris quand elle échoue.

Le critère qui tranche est la **sécurité** : durée d'exposition d'un jeton volé, capacité à le révoquer immédiatement, réutilisation possible par un futur client mobile. Le confort de développement vient ensuite, et la simplicité n'est jamais un motif suffisant pour laisser un jeton vivre sept jours sans contrôle.

## Options étudiées

| Option                                                 | Avantages                                                                                                                                     | Inconvénients                                                                                                                              | Verdict                                               |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------- |
| JWT d'accès 15 min + jeton de rafraîchissement rotatif | API sans état pour les appels courants ; impact d'un vol limité à 15 min ; révocation immédiate par Redis ; réutilisable par un client mobile | Deux jetons à gérer, révocation à implémenter, dépendance à Redis pour l'authentification                                                  | **Retenue** — couvre BX-03, BX-07, BX-23, §11.2 (A07) |
| Session serveur + cookie de session Symfony            | Révocation triviale, aucun jeton dans le code client, un seul mécanisme                                                                       | Couplage fort au domaine ; CSRF à gérer sur toutes les routes ; moins réutilisable par un client mobile                                    | Écartée — §8.3, BX-23                                 |
| JWT de longue durée, sans rafraîchissement             | Un seul jeton, aucun cookie, code minimal                                                                                                     | Aucune révocation sans liste noire, et 7 j d'exposition en cas de vol ; contredit O3 et le §11.2                                           | Écartée — sécurité (O3, A07)                          |
| JWT conservé dans `localStorage`                       | Simple, survit au rechargement de page                                                                                                        | Lisible par toute faille XSS ; exclu explicitement par le §11.4                                                                            | Écartée — sécurité (A03 XSS)                          |
| JWT symétrique HS256 avec un secret partagé            | Signature et vérification rapides, configuration réduite                                                                                      | Un secret partagé se duplique chez chaque consommateur et se remplace partout à la fois ; pas de paire de clés distincte par environnement | Écartée — rotation des secrets (§15.4)                |
| Fournisseur d'identité externe (OAuth2 / OIDC)         | Standard, A2F et fédération fournies                                                                                                          | Un service de plus à héberger ou à contractualiser, données à encadrer, intégration hors délai pour 2 à 3 semaines                         | Écartée — coût et délai (R5)                          |

## Décision

L'API délivre deux jetons. Le **jeton d'accès** est un JWT signé (RS256 ou ES256), valable **15 min**, conservé **en mémoire** par la SPA et envoyé dans l'en-tête `Authorization: Bearer`. Le **jeton de rafraîchissement** est **opaque**, valable **7 j**, **à usage unique avec rotation**, transmis dans un cookie `HttpOnly; Secure; SameSite=Strict; Path=/api/token/refresh` et enregistré dans Redis sous `refresh:{jti}`.

**Révocation.** La déconnexion supprime le jeton de rafraîchissement dans Redis et ajoute le `jti` du jeton d'accès à `jwt:deny:{jti}`, avec un TTL égal à la durée de vie restante du jeton. Un jeton présenté après révocation est refusé en **401** (§14.4).

**Détection de réutilisation.** Si un jeton de rafraîchissement déjà consommé est présenté, **toute la famille de jetons est révoquée** ; le client doit se reconnecter.

**CSRF.** Les routes qui exigent `Authorization` ne sont pas exposées, le navigateur n'ajoutant pas cet en-tête automatiquement. `/api/token/refresh` est la seule route à cookie : elle est protégée par `SameSite=Strict`, une vérification de l'en-tête `Origin` et un jeton CSRF en double soumission.

**Mots de passe et A2F.** Hachage **argon2id**, **12 caractères** minimum et 128 maximum, tous les caractères acceptés, pas d'expiration forcée, limitation de débit `rl:login:{ip}` sur **15 min**. L'A2F est **TOTP** avec **10 codes de secours** à usage unique et hachés, régénérés en bloc à chaque activation de l'A2F (les anciens sont supprimés) : **obligatoire** pour les rôles GESTIONNAIRE, MODERATEUR et ADMIN, **facultative** pour les clients. La connexion se fait en deux étapes, avec un jeton intermédiaire qui ne donne accès qu'à la saisie du code.

## Conséquences

### Positives

- Un jeton d'accès volé n'est exploitable que **15 min**, et il peut être révoqué immédiatement grâce à Redis : c'est la réponse directe au besoin de Nathalie (BX-16) et à la cible de confidentialité 4/4 du DICP (§11.1).
- Les appels ordinaires ne déclenchent aucune lecture de session : l'API reste sans état pour l'essentiel, ce qui simplifie la mise à l'échelle et la reprise par Hugo (BX-23).
- Le cookie de rafraîchissement n'est pas lisible en JavaScript, ce qui neutralise l'exploitation d'une XSS pour voler la session longue.
- La rotation avec détection de réutilisation transforme un vol de cookie en alerte : la famille entière est invalidée, plutôt que silencieusement utilisée.
- Le mécanisme est réutilisable par un futur client mobile (§18) : les mêmes routes servent la SPA et une application native.
- L'articulation avec l'A2F est cohérente : rôles back-office protégés par deux facteurs, clients libres d'activer l'A2F (BX-07, BX-18).
- Le cycle complet se teste sans navigateur : jeton expiré, falsifié, signé en `alg: none` ou révoqué se joue en test d'intégration (§14.4), ce qui sert directement la cible ENF-09.

### Négatives et risques

- **La révocation doit être gérée** : sans liste `jwt:deny:{jti}`, un jeton d'accès reste valable jusqu'à expiration. C'est du code et un test de sécurité en plus (§14.4), pas un service rendu gratuitement par JWT.
- **L'authentification dépend de Redis** : une indisponibilité de Redis empêche de valider les rafraîchissements et les révocations. Le comportement doit être tranché explicitement — refuser les jetons non vérifiables (Fail Securely, §8.5) plutôt que les accepter par défaut — et la panne doit être surveillée (§15.5).
- **Le jeton d'accès en mémoire est perdu au rechargement de page** : l'utilisateur paraît déconnecté tant que l'appel de rafraîchissement initial n'a pas abouti. Il faut un amorçage silencieux au démarrage de la SPA, avec un état de chargement visible, sinon l'expérience mobile de Léa (BX-01) se dégrade.
- **Rotation et onglets multiples** : deux onglets qui rafraîchissent en même temps présentent deux fois le même jeton et déclenchent la détection de réutilisation, donc une déconnexion involontaire. Le mécanisme doit tolérer cette course (sérialisation côté client ou courte fenêtre de grâce), sinon la promesse d'UX devient une gêne.
- **Une seule route à cookie, mais la plus sensible** : `SameSite=Strict`, `Origin` et double soumission sont trois protections à ne pas oublier, et le `Path=/api/token/refresh` est indispensable pour ne pas exposer le cookie aux autres routes.
- **Deux jetons, deux durées, deux stockages** : le nombre de cas de test augmente (expiré, falsifié, `alg: none`, révoqué, rejoué — §14.4), ce qui pèse sur un projet de 2 à 3 semaines.
- **Gestion des clés de signature** : elles sont générées localement et ne sont pas versionnées (README) ; leur génération et leur remplacement en recette et en production doivent être documentés, sinon le déploiement casse silencieusement toutes les sessions.
- **Le jeton intermédiaire de l'A2F** ajoute un troisième état à gérer ; mal validé, il ouvrirait l'API sans second facteur.
- **Même origine obligatoire** : un cookie `SameSite=Strict` n'arrive pas si le front et l'API sont servis sous des noms différents. Le déploiement doit donc les exposer sous une même origine, ce qui contraint le reverse proxy (ADR-0005).

### Actions induites

- F-03 : entités et endpoints d'inscription, connexion, rafraîchissement, déconnexion ; `LexikJWTAuthenticationBundle` ; politique de mot de passe ; `RateLimiter` sur la connexion.
- F-04 : enrôlement TOTP, contrôle obligatoire pour les rôles back-office, jetons intermédiaires, codes de secours hachés.
- Redis : clés `refresh:{jti}` (7 j) et `jwt:deny:{jti}` (durée restante), ACL par préfixe (§9.8).
- Tests de sécurité : JWT expiré, falsifié, `alg: none`, révoqué → 401 ; réutilisation d'un jeton de rafraîchissement → famille révoquée (§14.4).
- Diagramme de séquence `seq-connexion-jwt` (voir [conventions UML](../uml/README.md)).
- P-04 (réinitialisation) et P-06 (A2F client) réutilisent le même socle.
- Documentation : politique de mot de passe, cycle de vie des jetons et protection CSRF repris dans le dossier de projet au titre du §11.4.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.2 (B2, B3, B9), §5.3 (US-20 à US-25), §9.8, §11.1, §11.2, §11.4, §14.4, §18.
- [Index des ADR](README.md) — ADR-0002 (Redis), ADR-0006 (Voters et couches).
- [Conventions des diagrammes UML](../uml/README.md) — `seq-connexion-jwt`.

# Patrons de sécurité retenus — SideKey

Document produit pour le ticket **C-03** ; il applique les niveaux et les mesures de [`dicp.md`](dicp.md) et reprend la liste du CDC §8.5 (patrons de conception et de sécurité).

> **État du code.** Au 25/11/2026, F-03 a livré **Authenticator** (`security.yaml`, LexikJWT, `UtilisateurProvider`, `JwtSubscriber`), **Authorization Enforcer** pour la matrice RBAC (`Permission`, `RbacVoter` ; les voters de propriété viendront avec F-06 et F-10), **Fail Securely** (refus par défaut dans `access_control`, 401 / 403 génériques, Redis indisponible = jeton refusé), **Input Validator** (DTO `ConnexionRequest` et `InscriptionRequest`, contrainte `MotDePasseConforme`, JSON imposé) et **Least Privilege** côté applicatif (rôles relus en base à chaque requête). Chacun est vérifié par les tests de `api/tests/Security/`. F-01 ajoute la validation des paramètres du catalogue (`RechercheCatalogueRequest`, tri en liste blanche, SQL paramétré, jokers `LIKE` échappés, clés Redis construites depuis des valeurs validées), vérifiée par `CatalogueSecurityTest`. Les autres patrons restent **prévus et non encore implémentés** ; leurs noms de classes sont indicatifs jusqu'au ticket qui les livre.

## 1. Patrons de conception de sécurité retenus

Douze patrons sont retenus : les sept du CDC §8.5 — Authenticator, Authorization Enforcer, Input Validator, Secure Logger, Encrypted Storage, Limited View, Fail Securely — puis cinq principes qui conditionnent leur mise en œuvre : Least Privilege, Defense in Depth, Secure Defaults, Separation of Duties, Non-repudiation.

Un patron n'est retenu que s'il porte une mesure du DICP **et** qu'il est vérifiable par un test du §14.4 ou une règle de CI.

| Patron | Intention | Implémentation prévue (fichier ou classe) | Couche | Menaces couvertes | Test de vérification |
|---|---|---|---|---|---|
| **Authenticator** | Établir l'identité à chaque requête à partir d'un jeton signé et court, sans session serveur. | `api/config/packages/security.yaml` (firewall JWT, LexikJWTAuthenticationBundle) et `api/src/Security/` (authenticator intermédiaire de l'étape A2F) — prévu F-03, F-04 | Présentation | Usurpation d'identité, jeton falsifié, session volée | §14.4 JWT expiré, falsifié ou révoqué → 401 (`JwtAttaquesTest`, livré F-03) ; E2E connexion avec A2F (P-03) |
| **Authorization Enforcer (Voters)** | Décider l'accès sur la ressource et non sur la seule route : rôle, propriété, statut. | `api/src/Security/Voter/` — `CommandeVoter`, `CleLicenceVoter`, `AvisVoter` (prévu F-03, F-10) | Métier | IDOR, élévation de privilège, révélation d'une clé non payée | §14.4 IDOR → 403/404 ; §14.1 Voters (propriétaire, non-propriétaire, statut) ; matrice RBAC (`RbacVoterTest`, `RbacMatriceHttpTest`, livrés F-03) |
| **Input Validator** | Refuser toute entrée hors contrat avant qu'elle n'atteigne le métier. | `api/src/Dto/Request/` avec contraintes Symfony Validator ; `api/src/EventSubscriber/` pour le rendu des erreurs — prévu F-03, F-09 | Présentation | Injection, XSS stocké, valeurs hors bornes, *mass assignment* | §14.4 injection SQL et XSS dans un avis ; §14.1 validation des DTO |
| **Secure Logger** | Journaliser l'événement utile sans la donnée sensible : jamais de clé, de mot de passe ni de carte. | `api/src/Service/` (`AuditService`), `api/src/Repository/JournalAuditRepository`, `api/sql/triggers/trg_journal_audit_immuable.sql` — prévu F-10, T-02 | Métier et données | Fuite par les journaux (A09), répudiation | Critère F-10 « le journal ne contient aucune clé en clair » ; ENF-12 par tests d'intégration |
| **Encrypted Storage** | Chiffrer au repos les données à forte valeur, avec IV aléatoire et clé hors base. | `api/src/Infrastructure/Crypto/` (`ChiffreurAesGcm`, `EmpreinteurHmac`), colonne `cle_licence.cle_chiffree` en `BYTEA` — prévu F-08 | Accès aux données | Fuite d'un dump ou d'une sauvegarde, lecture directe en base | §14.1 aller-retour AES et chiffré altéré rejeté ; F-08 aucune clé en clair dans la base, les logs et les réponses |
| **Limited View** | Ne projeter que les champs nécessaires ; masquer les clés en back-office (4 derniers caractères). | `api/src/Dto/Response/`, projections explicites dans `api/src/Repository/`, `api/src/Controller/Admin/` — prévu F-08, F-09 | Présentation et données | Divulgation excessive, fuite par sérialisation | Revue de PR ; test de réponse API admin sans champ de clé en clair |
| **Fail Securely** | Refuser par défaut : une erreur produit un refus, jamais un repli permissif ni un détail technique. | `api/src/EventSubscriber/` (gestionnaire d'erreurs et en-têtes), `api/src/Exception/` (exceptions métier typées), `access_control` en refus par défaut — prévu F-03 | Présentation | Fuite d'information, contournement par état incohérent | §14.4 accès admin sans rôle → 403 ; test d'une erreur 500 sans trace technique |
| **Least Privilege** | Chaque compte, rôle et composant ne détient que les droits nécessaires. | `api/sql/roles/` (`sk_app` sans DELETE sur les tables sensibles), hiérarchie de rôles du §11.5, secrets par environnement — prévu T-01, F-03 | SGBD et métier | Élévation de privilège, exfiltration, suppression accidentelle | §14.2 droits SGBD (DELETE refusé) ; §14.4 accès admin sans le rôle → 403 |
| **Defense in Depth** | Rejouer le même contrôle à plusieurs couches : DTO, service, contrainte SQL. | Voir le §3 de ce document | Toutes | Contournement d'un contrôle unique | Definition of Done du backlog (« validation et contrôle d'accès vérifiés dans chaque couche ») |
| **Secure Defaults** | Livrer une configuration sûre sans réglage supplémentaire. | `api/config/`, `docker/` : `APP_ENV=prod`, CORS limité au domaine du front, conteneurs sans root, Redis avec commandes dangereuses désactivées — prévu T-00, T-04 | Infrastructure | Mauvaise configuration (A05) | ZAP baseline sur la recette ; Trivy sur les images |
| **Separation of Duties** | Séparer qui édite, qui valide et qui consulte. | `api/config/packages/security.yaml` (rôles), Voters, `api/src/Controller/Admin/` : le gestionnaire consulte les commandes, seul l'admin valide une commande suspecte ; le modérateur n'accède ni aux commandes ni aux clés (US-55 à US-57) — prévu F-04, F-10 | Métier | Fraude interne, abus de pouvoir, erreur non relue | Matrice RBAC du §11.5 testée ; §14.4 accès admin ; relecture croisée obligatoire en PR (§16.5) |
| **Non-repudiation** | Rendre une action sensible attribuable et non modifiable après coup. | `journal_audit` en ajout seul, `trg_journal_audit_immuable`, `commande_historique` et `trg_commande_historique`, `ip_hachee` — prévu T-02, F-10 | Données et SGBD | Répudiation d'une révélation, d'un remboursement ou d'une action admin | ENF-12 ; §14.2 trigger UPDATE et DELETE refusés ; scénario 7 du DICP |

Trois règles gouvernent l'application de ces patrons :

- **Un propriétaire par contrôle.** Chaque contrôle a une couche responsable ; les autres couches le relaient, jamais ne le remplacent.
- **Aucun contrôle de sécurité uniquement dans la SPA.** Ce qui n'est pas vérifié côté serveur est réputé absent : masquer un bouton n'est pas une autorisation.
- **Tout refus est tracé, sauf la donnée sensible.** Un 401 ou un 403 alimente le journal sans y recopier le jeton ni le mot de passe reçu.

## 2. Patrons de conception métier utiles à la sécurité

Les patrons du CDC §8.5 ne sont pas seulement des choix de structure : chacun retire une possibilité d'erreur ou d'abus au code applicatif.

| Patron | Rôle | Où | Effet sur la sécurité |
|---|---|---|---|
| **Repository** | Isoler l'accès aux données derrière une interface par agrégat. | `api/src/Repository/` (`CleLicenceRepository`, `CommandeRepository`, `JournalAuditRepository`) | Aucune concaténation SQL : les requêtes paramétrées et les requêtes sensibles (verrouillantes, filtrées par propriétaire) sont centralisées et testables une seule fois. |
| **DTO / Mapper** | Décrire explicitement le contrat d'entrée et de sortie. | `api/src/Dto/Request/`, `api/src/Dto/Response/` | Aucune entité liée directement depuis la requête : pas de *mass assignment*, pas de champ `statut`, `montant` ou `role` imposable par le client ; la réponse ne contient que les champs prévus. |
| **Service Layer** | Porter les règles de gestion et les frontières transactionnelles. | `api/src/Service/` (`CommandeService`, `PaiementService`, `LicenceService`, `AntiFraudeService`, `ImportClesService`, `AuditService`) | Le contrôle d'accès et la validation sont revérifiés hors du contrôleur ; une transaction par cas d'usage ; la logique de sécurité ne dépend pas de l'ordre des appels HTTP. |
| **Strategy** | Substituer le fournisseur de paiement sans toucher au métier. | `api/src/Infrastructure/Payment/` (`PaymentProviderInterface`, fournisseur simulé) | Aucune donnée de carte ne traverse le domaine ; les réponses sont déterministes et testables ; un prestataire réel se branche en v2 sans rouvrir les règles. |
| **Factory** | Construire une commande valide à partir du panier. | `api/src/Service/` (fabrique de commande appelée par `CommandeService`) | Statut initial, référence UUID et montant sont imposés par le serveur : le client ne peut pas fixer l'état ni le prix (RG-11). |
| **Observer / événements Symfony** | Réagir à « commande payée » : e-mail et audit. | `api/src/EventSubscriber/` | La trace et la notification ne dépendent pas du contrôleur : une action oubliée devient visible, et le découplage rend les deux effets testables séparément. |
| **Command (Messenger)** | Exécuter un traitement long hors de la requête. | `api/src/Service/`, transport Redis de Messenger | L'import asynchrone (P-13) réduit la fenêtre d'exposition d'une requête longue ; le message transporte un identifiant, pas de donnée sensible. |

Conséquence pratique : aucun contrôleur ne manipule directement une entité issue de la requête, et aucune règle de gestion ne vit dans un contrôleur. Le chemin obligé est DTO → service → repository, ce qui laisse un point unique à tester et à relire pour chaque règle de sécurité.

## 3. Défense en profondeur

Le tableau suivant montre qu'un même contrôle existe à plusieurs niveaux. La colonne « SPA » relève du confort d'usage : elle ne protège jamais seule, car le client est contournable.

| Contrôle | SPA React | Reverse proxy (Caddy) | Présentation (API) | Métier | Accès aux données | SGBD |
|---|---|---|---|---|---|---|
| Authentification | Jeton d'accès en mémoire, redirection sur 401 | TLS, HSTS | Firewall JWT, 401 sans détail | Rechargement de l'utilisateur, contrôle du compte `actif` | — | — |
| Autorisation et propriété | Masquage des actions non permises | — | `#[IsGranted]` sur les routes, `/api/admin/**` avec A2F | Voter de propriété, filtre `utilisateur_id` | Requête bornée par le propriétaire | Aucun DELETE sur les tables sensibles |
| Validation des entrées | Validation de formulaire (confort) | Limite de taille des requêtes | DTO et Symfony Validator | Revérification des bornes (quantité ≤ stock, ≤ 5 clés) | Requête paramétrée | `CHECK`, `UNIQUE`, clés étrangères |
| Intégrité des montants | Affichage du total renvoyé par l'API | — | Aucun montant dans les DTO de requête | Recalcul serveur, prix figé dans la ligne (RG-05) | Prix lu en base, jamais dans la requête | `CHECK` prix positif, `quantite BETWEEN 1 AND 10` |
| Chiffrement et masquage des clés | Clé affichée seulement sur l'écran de révélation | TLS | Réponse admin limitée aux 4 derniers caractères | Déchiffrement dans `LicenceService::reveler()` après Voter | AES-256-GCM, colonne `BYTEA`, empreinte HMAC `UNIQUE` | Rôle sans DELETE, trigger de protection |
| Traçabilité | — | Journal d'accès du proxy | Identifiant de corrélation dans la réponse | `AuditService` sur chaque action sensible | Insertion seule dans `journal_audit` | `trg_journal_audit_immuable` |
| Gestion des erreurs | Message générique traduit | Pages d'erreur sans détail | `ExceptionSubscriber` → code HTTP normalisé | Exception métier typée, sans donnée sensible | — | — |
| Limitation de débit | Bouton désactivé après échec (confort) | Limite de connexions | RateLimiter Redis par IP ou par compte | Compteur anti-fraude 24 h (RG-14) | Compteur atomique Redis | — |
| Secrets et configuration | Aucun secret dans le bundle | Variables d'environnement du conteneur | Lecture depuis l'environnement | Injection par constructeur, jamais de constante en dur | Jamais de secret en base | Un mot de passe distinct par rôle |

Trois contrôles sont réellement rejoués à quatre couches : **validation**, **autorisation** et **chiffrement**. Les autres sont portés par une ou deux couches, ce qui est assumé : un en-tête HSTS n'a de sens qu'au proxy, et un trigger d'immuabilité n'a de sens qu'en base.

La duplication a un coût : elle n'est retenue que là où une couche peut être contournée seule (le client) ou là où une erreur de programmation est probable (le métier). Ailleurs, une seule implémentation suffit, testée et relue.

## 4. Mapping OWASP Top 10 (2021)

Le CDC §11.2 fixe la correspondance menace → parade pour les dix risques. Le tableau la complète par le patron mobilisé, la couche qui le porte et le test qui le vérifie.

| Risque | Parade SideKey | Patron(s) | Couche | Test |
|---|---|---|---|---|
| **A01** Contrôle d'accès | Voters de propriété, références UUID, `/api/admin/**` réservé aux rôles back-office avec A2F (CDC §11.2) | Authorization Enforcer, Least Privilege, Fail Securely | Métier, présentation | §14.4 IDOR → 403/404 ; admin sans rôle ou sans A2F → 403 |
| **A02** Défaillances cryptographiques | TLS, AES-256-GCM à IV aléatoire, argon2id, secrets hors dépôt, comparaison de jetons en temps constant | Encrypted Storage, Secure Defaults | Données, SGBD, infrastructure | §14.1 chiffré altéré rejeté ; §14.4 JWT falsifié → 401 ; ZAP |
| **A03** Injection | Doctrine et requêtes paramétrées, clés Redis construites depuis des identifiants validés, échappement JSX, CSP stricte, HTML refusé dans les avis | Input Validator, Limited View | Présentation, données, SPA | §14.4 injection SQL dans la recherche et les filtres ; XSS dans un avis |
| **A04** Conception non sécurisée | Anti-fraude Équilibré, réservation à TTL, idempotence du paiement, séparation des devoirs sur les commandes suspectes | Separation of Duties, Fail Securely | Métier | §14.1 anti-fraude ; §14.4 double paiement ; scénario 6 du DICP |
| **A05** Mauvaise configuration | En-têtes de sécurité, `APP_ENV=prod`, aucune trace technique renvoyée, CORS limité au domaine du front, conteneurs sans root, Redis avec commandes dangereuses désactivées | Secure Defaults, Fail Securely | Proxy, présentation, infrastructure | ZAP baseline sur la recette ; Trivy sur les images |
| **A06** Composants vulnérables | Dependabot, `composer audit`, `npm audit`, scan Trivy des images, Semgrep sur le PHP | Least Privilege (surface minimale) | CI | CI bloquante sur les failles hautes et critiques (ENF-09) |
| **A07** Authentification | JWT de 15 min, refresh rotatif à usage unique, limitation de débit, A2F TOTP obligatoire pour le back-office | Authenticator, Defense in Depth | Présentation, métier, Redis | §14.4 force brute → blocage ; JWT et réutilisation du refresh ; E2E A2F |
| **A08** Intégrité des données et du logiciel | Prix jamais acceptés depuis le client, import CSV validé et dédupliqué par empreinte, images Docker taguées par le SHA du commit et non reconstruites entre recette et production (§15.3) | Input Validator, Encrypted Storage | Métier, données, CI/CD | §14.4 prix modifié → ignoré ; §14.2 import CSV ; contrôle du tag d'image en CD |
| **A09** Journalisation et supervision défaillantes | Journal d'audit sans données sensibles, `ip_hachee`, logs JSON, identifiant de corrélation | Secure Logger, Non-repudiation | Métier, données | ENF-12 ; critère F-10 « aucune clé en clair dans le journal » ; P-11 |
| **A10** Falsification de requête côté serveur (SSRF) | Aucun appel vers une URL fournie par l'utilisateur : le PSP simulé est un composant interne sans URL paramétrable, les jaquettes sont téléversées et jamais récupérées depuis une URL fournie (F-09) | Input Validator, Secure Defaults | Métier, infrastructure | Revue de code ; Semgrep ; aucune URL distante dans les DTO |

**A04** et **A08** sont les deux risques propres au métier : une boutique de clés est attaquée par la fraude au paiement et par la falsification des prix et des stocks, plus que par la technique pure. Les deux sont traités dans le métier et dans la base, pas seulement dans la présentation.

Deux risques se vérifient hors du §14.4 : **A06** par la CI (audits et Trivy) et **A09** par les tests d'intégration d'ENF-12. Les huit autres s'appuient sur le §14.4, sur ZAP baseline pour A05 et sur Semgrep pour A10.

## 5. Anti-patrons explicitement évités

| Anti-patron | Risque | Règle appliquée à la place | Contrôle |
|---|---|---|---|
| JWT dans `localStorage` | Lisible par toute XSS, vol persistant | Jeton d'accès en mémoire (`front/src/api/`), refresh opaque en cookie `HttpOnly; Secure; SameSite=Strict` | Revue de PR ; §14.4 XSS ; CSP |
| `SELECT *` non borné | Divulgation de colonnes sensibles (hash, secret TOTP, clé), coût inutile | Projections explicites, pagination à 20 (US-01), DTO de sortie | Revue de PR ; Limited View |
| Entité Doctrine exposée en JSON | Fuite de champs, couplage du contrat à la base | DTO de réponse et normalisation explicite | Tests de contrat ; revue de PR |
| `eval` / `unserialize` sur une entrée | Exécution de code, désérialisation d'objet | Aucune désérialisation PHP d'entrée utilisateur ; JSON typé par DTO | Semgrep bloquant |
| Secrets dans le dépôt | Compromission totale, historique Git irréversible | Secrets GitHub Environments, `.env.example` sans secret, fichiers `.env` ignorés | Secret scanning et push protection (§15.4) |
| Message d'erreur technique remonté au client | Fuite de chemins, de requêtes SQL ou de versions | `ExceptionSubscriber` : code normalisé et message traduit ; détail réservé aux logs | Test d'erreur 500 ; ZAP |
| Comparaison de jeton non constante | Attaque temporelle sur les jetons et les signatures | `hash_equals()` pour tout jeton et toute signature | Revue de PR ; Semgrep |
| `DELETE` physique sur les clés | Perte de preuve, clé rachetable | Cycle de statuts `DISPONIBLE` → `RESERVEE` → `VENDUE` (RG-04), pas de DELETE pour `sk_app`, `trg_cle_licence_protection` | §14.2 trigger ; T-01 |
| `SET ROLE` dynamique | Élévation de privilège au moment de l'exécution | Un rôle par connexion, fixé par configuration ; `sk_owner` réservé au job de migration | Revue des scripts `api/sql/roles/` |
| Comparaison ou recherche par concaténation SQL | Injection, erreur 500 exploitable | Paramètres liés et liste blanche pour les colonnes de tri | §14.4 injection SQL ; PHPStan et Semgrep |
| Journalisation du corps des requêtes ou des jetons | Fuite par les journaux, y compris dans les sauvegardes de logs | Logs structurés limités à l'action, à la ressource et à `ip_hachee` ; aucun corps de requête sensible | Critère F-10 ; revue de PR |
| Compteur de débit en mémoire PHP | Inefficace avec plusieurs conteneurs, remis à zéro au redémarrage | Compteurs atomiques Redis avec TTL (`rl:login:{ip}`, `rl:pay:{userId}`) | §14.4 force brute ; §14.2 TTL |
| Refus d'autorisation renvoyé en HTTP 200 | Le contrôle devient invisible pour les tests et pour le monitoring | Codes HTTP normalisés : 401, 403, 404, 409, jamais un succès déguisé | §14.4 IDOR et accès admin → 403 ; tests de contrat |

Chaque anti-patron reçoit un contrôle automatique quand il en existe un ; sinon il relève de la revue de PR, dont la Definition of Done exige la vérification de la validation et du contrôle d'accès dans chaque couche.

## 6. Limites assumées

- **Aucun patron ne remplace un WAF ni un anti-DDoS** : la protection volumétrique reste à la charge de l'hébergeur et de la supervision.
- **Pas de HSM ni de KMS** : la clé maître reste un secret d'environnement, protégé par les droits GitHub et non par un matériel dédié.
- **Preuve non scellée cryptographiquement** : l'immuabilité du journal repose sur les droits SGBD et les triggers, pas sur un chaînage de hachages.
- **A2F facultative côté client** : un compte client compromis par hameçonnage reste exploitable jusqu'à révocation ; seul le back-office impose le second facteur.
- **Administrateur malveillant non couvert** : un compte disposant de `sk_owner` et des secrets peut contourner l'immuabilité ; la séparation des devoirs reste organisationnelle (binôme, relecture croisée).
- **Paiement simulé** : aucune couverture PCI DSS ni 3-D Secure, et la détection de fraude se limite aux seuils du niveau Équilibré.
- **Import supérieur à 1 000 lignes non couvert** en v1 (US-52) ; le traitement asynchrone est reporté en P-13.
- **Chaîne d'approvisionnement partiellement couverte** : Dependabot, les audits et Trivy détectent les composants connus vulnérables, pas une compromission du dépôt ni une dépendance malveillante encore inconnue.
- **Pas de supervision comportementale** : les patrons empêchent les abus prévus ; ils ne détectent pas l'usage anormal d'un compte légitime au-delà des seuils anti-fraude.
- **Charge mesurée sur les seules cibles du CDC** : ENF-01 à ENF-03 (catalogue, création de commande, 50 achats simultanés de la dernière clé) ; ni endurance ni pic de trafic ne sont éprouvés.
- **Aucun patron n'est encore vérifié** : les tests cités sont ceux prévus au §14.4 et deviennent exigibles à la livraison du ticket correspondant, selon la Definition of Done du backlog.

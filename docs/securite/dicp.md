# Analyse DICP — SideKey

Analyse des risques du système d'information SideKey (boutique en ligne de clés de licence de jeux vidéo), produite pour le critère d'acceptation du ticket **C-03**.

Sources : cahier des charges v1.2 — §11.1 (niveaux), §9 (données, clés, rôles SGBD), §10 (concurrence et transactions), §11.2 à §11.6 (menaces, validation, JWT, RBAC, paiement), §12 (RGPD), §13 (ENF), §14.4 (tests de sécurité) — et backlog `docs/backlog/backlog_SideKey.md`. Les patrons qui portent les mesures sont détaillés dans [`security-patterns.md`](security-patterns.md).

## 1. Objet et méthode

**DICP** = **D**isponibilité, **I**ntégrité, **C**onfidentialité, **P**reuve. Le niveau retenu pour un bien et un critère est celui de l'**impact si la menace se réalise**, pas la probabilité de la menace.

| Critère | Question posée | Bien protégé en priorité |
|---|---|---|
| **D** — Disponibilité | Le service répond-il encore ? | Catalogue, tunnel d'achat, livraison des clés |
| **I** — Intégrité | La donnée est-elle exacte et non altérée ? | Clés, commandes, montants, statuts |
| **C** — Confidentialité | Qui peut lire la donnée ? | Clés en clair, secrets, données personnelles |
| **P** — Preuve | Peut-on démontrer ce qui s'est passé ? | Révélations, actions d'administration, paiements |

**Échelle de niveau** (CDC §11.1) :

| Niveau | Impact | Conséquence type pour SideKey | Décision |
|:-:|---|---|---|
| 1 | faible | gêne passagère, aucune donnée perdue | accepté sans mesure dédiée |
| 2 | modéré | service dégradé, reprise manuelle | mesures de continuité |
| 3 | important | perte financière, donnée fausse, preuve incomplète | mesures obligatoires et testées |
| 4 | critique | clé monnayable, fuite de données personnelles | mesures obligatoires, plusieurs couches |

**Méthode ANSSI simplifiée**, en quatre étapes :

| Étape | Contenu | Résultat |
|---|---|---|
| 1. Biens | recenser ce qui a de la valeur, nommer son propriétaire, coter sa sensibilité | §2 |
| 2. Menaces | décrire les scénarios crédibles, classés STRIDE | §3 et §5 |
| 3. Mesures | choisir la parade, la couche qui la porte, le ticket qui la livre | §3, §6, §7 |
| 4. Vérification | associer à chaque mesure un test du §14.4 et une exigence ENF | §3 et §7 |

> **État du code.** Au 23/11/2026, `api/src` ne contient que le noyau Symfony et `HealthController` : **aucune mesure décrite ici n'est encore implémentée**. La colonne « Où » désigne l'emplacement prévu dans l'arborescence du `README.md`, le §7 le ticket qui livre la mesure.
>
> **Noms de classes.** Ils sont indicatifs tant que le ticket n'est pas livré ; les dossiers sont ceux du `README.md` (`Controller`, `Dto`, `Service`, `Repository`, `Entity`, `Infrastructure/Crypto`, `Infrastructure/Redis`, `Security/Voter`, `EventSubscriber`, `Exception`).
>
> **Rôles SGBD.** La nomenclature du CDC §9.5 est retenue : `sk_owner` (migrations), `sk_app` (application), `sk_reporting` (lecture seule), `sk_backup` (sauvegardes). Le `README.md` a été aligné sur cette nomenclature dans C-03 ; T-01 crée les comptes avec ces noms.

## 2. Biens (actifs) et propriétaires

| Bien | Support | Propriétaire | Niveau de sensibilité |
|---|---|---|---|
| Clés de licence chiffrées | `cle_licence.cle_chiffree` (BYTEA), PostgreSQL | Administrateur — Nathalie (P5) | 4 |
| Clés de licence en clair | mémoire de l'API, réponse HTTPS de révélation, écran E09 | Client acheteur (P1–P3) | 4 |
| Empreintes HMAC des clés | `cle_licence.empreinte` (contrainte `UNIQUE`) | Administrateur (P5) | 3 |
| Clé maître AES-256 et secret HMAC | secrets GitHub Environments, variables d'environnement | Administrateur et équipe projet (P5, P7) | 4 |
| Comptes et mots de passe | `utilisateur.mot_de_passe_hash` (argon2id) | Client titulaire du compte | 4 |
| Secrets TOTP et codes de secours | `utilisateur.totp_secret_chiffre`, `code_secours.code_hash` | Client titulaire (P2, P5) | 4 |
| Commandes et montants | `commande`, `ligne_commande` | Administrateur (P5) | 3 |
| Paiements (code retour, 4 derniers chiffres) | `paiement` | Administrateur (P5) | 3 |
| Données personnelles (e-mail, prénom, nom, langue) | `utilisateur`, e-mails envoyés | Administrateur — Nathalie (P5) | 3 |
| Journal d'audit | `journal_audit` (`ip_hachee`, action, ressource) | Administrateur (P5) | 3 |
| Catalogue et prix | `jeu`, `offre`, `prix_ttc`, cache `cache:jeu:*` | Gestionnaire catalogue — Karim (P4) | 2 |
| Jetons d'accès et de rafraîchissement | en-tête `Authorization`, cookie `HttpOnly`, Redis `refresh:{jti}` | Client titulaire | 3 |
| Paniers | Redis `cart:{userId}` et `cart:anon:{uuid}` | Client ou visiteur | 1 |
| Sauvegardes | `pg_dump -Fc` horodaté (`api/sql/backup/`) | Administrateur (P5) | 4 |

## 3. Analyse par critère

### 3.1 Disponibilité — **2/4**

| Rubrique | Contenu |
|---|---|
| **Niveau** | **2/4** (CDC §11.1) |
| **Justification** | Impact modéré : une indisponibilité dégrade l'achat de Léa (BX-01, BX-02) et retarde la vente, sans perte de données ni divulgation. Le service peut être réparé par redéploiement, et le RTO visé est d'une heure (ENF-14). |
| **Menaces principales** | saturation du tunnel (ENF-03 : 50 achats simultanés de la dernière clé), réservations expirées non libérées, indisponibilité de Redis, panne du PSP simulé, migration défaillante en déploiement, disque plein côté PostgreSQL. |
| **Mesures retenues** | cache Redis 5 min sur le catalogue ; réservations TTL 15 min libérées chaque minute par `fn_liberer_reservations_expirees()` ; PSP appelé hors transaction (§10.3) ; sonde `/api/health` ; sauvegarde quotidienne avant migration, restauration `pg_restore --clean` (RPO 24 h, RTO 1 h) ; retour arrière par redéploiement du tag précédent ; Redis déclaré non source de référence (perte = paniers et sessions seulement, §9.8). |
| **Où c'est implémenté (couche · dossier · classe)** | Accès aux données : `api/src/Infrastructure/Redis/` (cache, paniers, réservations). Métier : `api/src/Service/` (orchestration commande et paiement). SGBD : `api/sql/functions/fn_liberer_reservations_expirees.sql`, `api/migrations/`. Infra : `docker/` (images et configuration des conteneurs), `api/sql/backup/`. |
| **Comment c'est vérifié (test, §14.4)** | ENF-03 par k6 (§14.4 « concurrence sur la dernière clé ») ; §14.2 pour les TTL et l'expiration ; ENF-14 par l'exercice de restauration (L-02) ; sonde de santé appelée après chaque déploiement (T-04). |

### 3.2 Intégrité — **3/4**

| Rubrique | Contenu |
|---|---|
| **Niveau** | **3/4** (CDC §11.1) |
| **Justification** | Impact important : une clé vendue deux fois, un montant modifié ou un statut incohérent produisent un litige commercial, une perte financière et une atteinte à la confiance (BX-03). Une corruption propagée aux sauvegardes devient difficile à corriger après coup. |
| **Menaces principales** | double attribution de la dernière clé ; prix ou quantité forgés dans la requête ; transition de statut interdite ; modification ou suppression d'une clé `VENDUE` ; doublons à l'import CSV ; écriture partielle en cas d'erreur ; édition concurrente d'une offre (BX-14). |
| **Mesures retenues** | transaction unique pour la commande et la réservation ; `SELECT … FOR UPDATE SKIP LOCKED` (§10.1) ; `fn_reserver_cles` lève une exception si le stock manque ; `fn_valider_commande` atomique et idempotente ; montant recalculé côté serveur et prix figé dans la ligne (RG-05, RG-11) ; `CHECK (quantite BETWEEN 1 AND 10)` ; empreinte HMAC `UNIQUE` (RG-03) ; triggers `trg_cle_licence_protection`, `trg_commande_transition`, `trg_commande_historique` ; verrou optimiste `version` → HTTP 409 ; import par lots de 500, doublons rejetés ligne à ligne ; clé d'idempotence de paiement `UNIQUE`. |
| **Où c'est implémenté (couche · dossier · classe)** | Présentation : `api/src/Dto/Request/` (aucun montant transmis). Métier : `api/src/Service/` (`CommandeService`, `PaiementService`, `AntiFraudeService`). Données : `api/src/Repository/CleLicenceRepository` (requête verrouillante), `api/src/Entity/` (colonne `version`). SGBD : `api/sql/functions/`, `api/sql/triggers/`, `migrations/`. |
| **Comment c'est vérifié (test, §14.4)** | §14.4 « double paiement → un seul paiement », « prix modifié → ignoré », « concurrence sur la dernière clé → 1 succès, 0 doublon » ; §14.2 pour la réservation, la validation et les triggers ; §14.1 pour les transitions de statut. |

### 3.3 Confidentialité — **4/4**

| Rubrique | Contenu |
|---|---|
| **Niveau** | **4/4** (CDC §11.1) |
| **Justification** | Impact critique : une clé en clair est de la valeur immédiatement monnayable et non remboursable une fois révélée (RG-20) ; une fuite de données personnelles déclenche une notification RGPD (BX-17) et détruit la confiance (BX-03). C'est le critère le plus exigeant du projet. |
| **Menaces principales** | exfiltration d'un dump ou d'une sauvegarde ; lecture de la clé d'un autre client (IDOR) ; vol de jeton par XSS ; interception réseau ; compte SGBD trop privilégié ; secret versionné par erreur ; fuite dans les logs ou un message d'erreur ; force brute sur la connexion ; accès back-office sans second facteur. |
| **Mesures retenues** | AES-256-GCM avec IV aléatoire, clé maître hors base et hors Git (§9.4) ; empreinte HMAC-SHA256 pour l'unicité — le chiffré ne peut pas y servir, l'IV aléatoire le rendant différent à chaque fois ; déchiffrement uniquement dans `LicenceService::reveler()` après passage du Voter ; back-office masqué (4 derniers caractères, BX-13) ; argon2id, 12 à 128 caractères ; TOTP + 10 codes de secours hachés, A2F obligatoire pour GESTIONNAIRE, MODERATEUR et ADMIN ; jeton d'accès 15 min en mémoire, refresh opaque 7 j en cookie `HttpOnly; Secure; SameSite=Strict` stocké dans Redis ; TLS et HSTS ; Voters de propriété et références UUID ; rôles SGBD à moindre privilège ; secrets par environnement, secret scanning et push protection. |
| **Où c'est implémenté (couche · dossier · classe)** | Données : `api/src/Infrastructure/Crypto/`, colonne `BYTEA`. Métier : `api/src/Service/` (`LicenceService::reveler()`, `AuditService`). Autorisation : `api/src/Security/Voter/`. Infrastructure : `api/src/Infrastructure/Redis/` (refresh, révocation `jti`), `api/sql/roles/`. Présentation : `api/src/EventSubscriber/` (erreurs), `api/src/Controller/Admin/`. SPA : `front/src/api/` (jeton en mémoire). |
| **Comment c'est vérifié (test, §14.4)** | §14.4 IDOR → 403/404, XSS dans un avis, accès admin avec rôle mais sans A2F → 403, JWT expiré ou falsifié → 401, force brute → blocage ; §14.1 aller-retour AES et chiffré altéré rejeté ; critère F-08 « pas de clé en clair dans la base, les logs ou les réponses » ; ZAP baseline, `composer audit`, `npm audit`, Trivy (ENF-09). |

### 3.4 Preuve — **3/4**

| Rubrique | Contenu |
|---|---|
| **Niveau** | **3/4** (CDC §11.1) |
| **Justification** | Impact important : sans trace fiable, une contestation de livraison, une révélation abusive ou une action d'administration ne peuvent être instruits (BX-16, RG-21, ENF-12). La preuve est seconde : elle sert après l'incident, elle ne l'empêche pas. |
| **Menaces principales** | suppression ou modification d'une ligne de `journal_audit` ; action sensible non tracée ; trace contenant elle-même des données sensibles ; horodatage ou auteur absent ; écrasement de l'historique de statut ; contestation d'une révélation de clé. |
| **Mesures retenues** | journal en ajout seul, `trg_journal_audit_immuable` refuse UPDATE et DELETE, aucun DELETE pour `sk_app` ; `ip_hachee` et non l'adresse en clair ; champs utilisateur, action, ressource, ressource_id, cree_le ; `commande_historique` rempli par trigger ; révélation journalisée à chaque appel (US-34) ; transitions de statut historisées (RG-21) ; consultation du journal en lecture seule (US-57). |
| **Où c'est implémenté (couche · dossier · classe)** | Métier : `api/src/Service/` (`AuditService`). Événements : `api/src/EventSubscriber/` (commande payée → audit et e-mail). Données : `api/src/Repository/JournalAuditRepository`. SGBD : `api/sql/triggers/trg_journal_audit_immuable.sql`, `trg_commande_historique.sql`. Présentation : `api/src/Controller/Admin/` (filtres, lecture seule). |
| **Comment c'est vérifié (test, §14.4)** | ENF-12 par tests d'intégration (§14.2) ; §14.4 contrôle d'accès sur les routes admin ; critère F-10 « le journal ne contient aucune clé en clair » ; P-11 pour les filtres et le caractère lecture seule. |

## 4. Matrice de synthèse DICP

● fort (niveau 3 ou 4) · ◐ moyen (niveau 2) · ○ faible (niveau 1)

| Bien | D | I | C | P |
|---|:-:|:-:|:-:|:-:|
| Clés de licence chiffrées | ◐ | ● | ● | ◐ |
| Clés de licence en clair | ○ | ● | ● | ● |
| Empreintes HMAC | ○ | ● | ◐ | ○ |
| Clé maître AES et secret HMAC | ● | ● | ● | ○ |
| Comptes et mots de passe (argon2id) | ◐ | ● | ● | ◐ |
| Secrets TOTP et codes de secours | ○ | ● | ● | ◐ |
| Commandes et montants | ● | ● | ◐ | ● |
| Paiements (code retour, 4 derniers chiffres) | ◐ | ● | ◐ | ● |
| Données personnelles | ◐ | ● | ● | ◐ |
| Journal d'audit | ◐ | ● | ◐ | ● |
| Catalogue et prix | ● | ● | ○ | ○ |
| Jetons JWT et de rafraîchissement | ◐ | ● | ● | ◐ |
| Paniers Redis | ○ | ◐ | ○ | ○ |
| Sauvegardes | ● | ● | ● | ◐ |

Lecture : la colonne C concentre les ●, ce qui justifie le niveau 4/4 du §11.1 ; la colonne P est portée par les biens qui servent de preuve (journal, commande, paiement). Les biens cotés ○ en confidentialité (catalogue, panier) sont ceux qui peuvent être perdus ou lus sans conséquence métier, ce qui autorise à les placer dans Redis sans en faire une source de référence.

## 5. Scénarios de menace

Modèle STRIDE simplifié : **S** usurpation, **T** altération, **R** répudiation, **I** divulgation, **D** déni de service, **E** élévation de privilège. Vraisemblance appréciée qualitativement : faible, moyenne, élevée.

| # | Scénario (STRIDE) | Critère | Impact | Vraisemblance | Parade | Effet résiduel |
|:-:|---|---|---|---|---|---|
| 1 | **T** — Deux clients achètent en même temps la dernière clé : lecture puis écriture non verrouillées, la même ligne est attribuée aux deux commandes. | I | Deux clients paient une clé unique : litige, remboursement, atteinte à BX-03 et à O2. | Élevée sans verrou (condition de concurrence). | Transaction unique ; `SELECT … FOR UPDATE SKIP LOCKED` ; `fn_reserver_cles` lève « stock insuffisant » ; `trg_cle_licence_protection` ; TTL 15 min. | Un client voit sa commande refusée alors que la clé semblait disponible : message explicite, aucun doublon. |
| 2 | **I** — Un compte SGBD trop privilégié (`sk_app` avec DDL ou DELETE) sert à vider une table ou à extraire un dump. | C, D, P | Fuite des données personnelles, destruction de preuves, arrêt du service. | Moyenne (compte mal configuré, mot de passe partagé). | `sk_app` sans DELETE sur `cle_licence`, `commande`, `paiement`, `journal_audit` ; DDL réservé à `sk_owner` (déploiement) ; `scram-sha-256`, réseau Docker interne ; `sk_reporting` sur vues sans données personnelles ; clé maître hors base. | `sk_owner` ou un accès au VPS reste capable d'exfiltrer ; les clés restent chiffrées hors de la base. |
| 3 | **E** — Un client remplace la référence UUID d'une commande dans `GET /api/commandes/{reference}` et lit les clés d'un tiers. | C, P | Divulgation de clés payées par un autre client, perte sèche. | Moyenne (UUID non devinable, mais URL partagée ou journalisée). | UUID en référence ; `CommandeVoter` de propriété ; requête filtrée par `utilisateur_id` ; `CleLicenceVoter` (propriétaire et commande `PAYEE`) ; 404 pour un non-propriétaire. | Une référence diffusée par un canal externe reste exploitable par son seul propriétaire ; le journal permet de retracer les consultations. |
| 4 | **S/I** — Une XSS (avis, champ mal échappé, dépendance compromise) exécute du code dans la SPA et lit le jeton d'accès. | C | Vol de session pendant 15 min, révélation de clés, actions au nom du client. | Moyenne (dépend de l'échappement et de la CSP). | Jeton d'accès en mémoire uniquement ; échappement JSX ; CSP stricte ; HTML refusé dans les avis (RG-30) ; jeton 15 min ; refresh `HttpOnly; SameSite=Strict` ; `jti` révocable. | Une XSS dans une dépendance permet d'utiliser le jeton en mémoire tant qu'il vit ; pas de vol persistant, révocation possible. |
| 5 | **S** — Un jeton de rafraîchissement volé est rejoué après l'usage légitime. | C | Prise de contrôle durable du compte (7 j). | Faible à moyenne (cookie `HttpOnly`, `Path` restreint). | Jeton opaque à usage unique et rotation ; détection de réutilisation → révocation de toute la famille ; stockage Redis révocable ; vérification `Origin` et double soumission CSRF sur `/api/token/refresh`. | L'utilisateur légitime est déconnecté lors d'une détection (déni de service mineur) ; aucune reconnexion silencieuse de l'attaquant. |
| 6 | **S** — Un fraudeur crée un compte avec un e-mail jetable et paie un gros panier avec une carte volée. | I, P | Clés livrées avant détection, non remboursables une fois révélées (RG-20), perte financière. | Élevée (schéma courant des boutiques de clés). | Niveau Équilibré (RG-14) : 5 clés par offre, 10 clés par 24 h, 3 échecs → blocage 1 h, compte de moins de 24 h et montant supérieur à 150 € ou 1re commande supérieure à 200 € → `A_VERIFIER` ; e-mail vérifié ; compteurs Redis ; file « à vérifier » (US-56). | Un compte ancien compromis, ou un réseau de comptes restant sous les seuils, passe ; la validation manuelle reste la dernière barrière. |
| 7 | **R** — Un utilisateur disposant d'un accès base supprime ou modifie une ligne de `journal_audit` pour effacer une révélation abusive. | P, I | La traçabilité (ENF-12) et la défense en cas de litige s'effondrent. | Faible (nécessite un accès direct). | `trg_journal_audit_immuable` refuse UPDATE et DELETE ; aucun DELETE pour `sk_app` ; lecture seule (US-57, P-11) ; journal exporté avec les sauvegardes. | `sk_owner` peut modifier la structure (pas de scellement cryptographique) ; l'immuabilité repose sur les droits et sur la sauvegarde. |
| 8 | **T** — Un gestionnaire importe un CSV piégé : cellules commençant par `=`, doublons, lignes surdimensionnées. | I, C | Formule exécutée à l'ouverture du rapport dans un tableur, doublon vendu deux fois, saturation mémoire. | Moyenne (compte back-office compromis ou fichier fournisseur malveillant). | Type MIME réel et taille contrôlés ; validation ligne à ligne ; normalisation puis empreinte HMAC `UNIQUE` ; doublons rejetés et comptés ; neutralisation des préfixes de formule à l'affichage et à l'export ; lots de 500, 1 000 lignes maximum en v1 ; rapport acceptées / doublons / erreurs (BX-11). | Le format et l'unicité ne prouvent pas l'authenticité fournisseur : un gestionnaire malveillant peut injecter des clés bien formées, détectées seulement à l'usage. |

## 6. Risque résiduel accepté

| Risque résiduel | Critère | Pourquoi il est accepté en v1 | Réduction prévue ou condition |
|---|---|---|---|
| Paiement simulé : aucun PSP réel, pas de 3-D Secure | I, P | Décision F12 et §11.6 : objectif pédagogique, PCI DSS hors périmètre ; aucune donnée de carte n'entre dans le système. | `PaymentProviderInterface` (Strategy) permet un prestataire réel en v2 ; cas accepté, refusé et délai testés ; bandeau « paiement fictif » FR/EN. |
| Pas de WAF ni d'anti-DDoS devant Caddy | D | Périmètre v1 ; l'application ne peut pas absorber une attaque volumétrique. | Limite de taille de requête au proxy, RateLimiter Redis, hébergement UE, supervision et coupure manuelle. |
| Clé maître AES et secret HMAC dans les secrets GitHub, sans HSM ni KMS | C | Coût et complexité hors projet de formation. | Secrets distincts par environnement `staging` et `production` (§15.4), accès restreint, secret scanning et push protection, rotation documentée. |
| A2F facultative pour les clients (P-06) | C | Friction pour Léa (P1) ; le CDC la veut facultative côté client. | A2F obligatoire pour le back-office, jeton court, refresh révocable, révélations journalisées. |
| Supervision minimale (§15.5) : ni SIEM, ni alerte temps réel sur la fraude | P, D | Moyens disponibles. | `/api/health`, logs JSON, alerte d'échec de déploiement, revue périodique du journal d'audit (P-11). |
| Preuve non scellée cryptographiquement | P | Complexité hors v1 ; le besoin est couvert par les droits et les triggers. | Trigger d'immuabilité, absence de DELETE pour `sk_app`, sauvegardes horodatées. |
| Ni chiffrement au repos du volume PostgreSQL, ni TLS interne pour PostgreSQL et Redis, prévus au CDC | C | Le CDC s'appuie sur le cloisonnement réseau et `scram-sha-256`. | Réseau Docker interne uniquement, Redis avec mot de passe et ACL par préfixe, ports ouverts sur `127.0.0.1` en développement. |
| Import CSV synchrone limité à 1 000 lignes | D | Périmètre v1 (US-52) ; le traitement asynchrone est prévu en P-13 (pool). | Lots de 500, limite de taille de requête, rapport d'erreurs ; au-delà, import asynchrone Messenger. |

## 7. Traçabilité

Toutes les mesures ci-dessous sont **prévues** : aucune n'est encore livrée au 23/11/2026 (voir l'encadré du §1). Le ticket indique qui la livre.

| Mesure | Exigence ENF (§13) | Test (§14.4 sauf mention) | Ticket |
|---|---|---|---|
| Réservation verrouillante des clés (`SKIP LOCKED`) | ENF-03 | Concurrence sur la dernière clé (k6) | F-06 |
| Refus explicite si le stock est insuffisant | ENF-03 | §14.2 réservation (exception levée) | F-06, T-02 |
| Montant recalculé côté serveur | ENF-09 | Prix modifié dans la requête → ignoré | F-06 |
| Idempotence du paiement (clé `UNIQUE`) | ENF-09 | Double paiement → un seul paiement | F-07 |
| Seuils anti-fraude Équilibré (RG-14) | ENF-09 | §14.1 anti-fraude (unitaires) et scénario 6 | F-07 |
| Voters de propriété sur commandes et clés | ENF-09 | IDOR sur une commande ou une clé → 403/404 | F-03, F-10 |
| Rôle et A2F exigés sous `/api/admin/**` | ENF-09 | Accès admin sans rôle, ou avec rôle mais sans A2F → 403 | F-04 |
| JWT court, rotation et révocation du refresh | ENF-09 | JWT expiré, falsifié (`alg: none`), révoqué → 401 ; réutilisation du refresh | F-03 |
| Limitation de débit connexion et paiement | ENF-09 | Force brute sur la connexion → blocage | F-03 |
| AES-256-GCM et empreinte HMAC unique | ENF-09 | §14.1 chiffré altéré rejeté ; F-08 aucune clé en clair | F-08 |
| Révélation réservée au propriétaire d'une commande `PAYEE` | ENF-12 | §14.1 Voter ; critère F-08 révélation journalisée ; scénario 3 | F-08 |
| Journal d'audit immuable (UPDATE et DELETE refusés) | ENF-12 | §14.2 trigger ; scénario 7 | T-02, F-10 |
| Historique des statuts de commande | ENF-12 | §14.2 trigger `trg_commande_historique` | T-02 |
| Rôles SGBD à moindre privilège (`sk_app` sans DELETE) | ENF-09 | §14.2 droits refusés ; scénario 2 | T-01 |
| Validation des DTO et contraintes SQL | ENF-09 | Injection SQL dans la recherche et les filtres | F-01, F-09 |
| Sauvegarde et restauration testées | ENF-14 | Exercice documenté (RPO 24 h, RTO 1 h) | T-01, L-02 |
| En-têtes de sécurité et erreurs sans fuite technique | ENF-09 | ZAP baseline sur la recette | T-04, P-02 |
| Dépendances et images contrôlées | ENF-09 | CI bloquante (audit, Trivy) | T-03 |
| Couverture des services et repositories ≥ 70 % | ENF-07 | PHPUnit et SonarQube Cloud | T-03, P-07 |

Les mesures sans identifiant ENF — droits RGPD du §12.1 (export, anonymisation), accessibilité RGAA, éco-conception — ne figurent pas dans ce tableau : elles sont tracées par le ticket P-05 et le dossier de projet.

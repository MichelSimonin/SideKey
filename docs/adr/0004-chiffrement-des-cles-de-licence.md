# ADR-0004 — Chiffrement AES-256-GCM et empreinte HMAC-SHA256 des clés de licence

| Élément                        | Valeur                                                                                                                                                                                    |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                   |
| Date                           | 2026-11-23                                                                                                                                                                                |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                   |
| Décision du cahier des charges | §9.4 — protection des clés de licence (application de RG-03, §6.1)                                                                                                                        |
| Portée                         | Représentation en base d'une clé de licence : chiffrement au repos, unicité, contrôle d'accès au déchiffrement. Ne couvre ni le stockage des jetons (ADR-0003) ni les comptes SGBD (§9.5) |
| Tickets liés                   | #4 (C-03) · F-08, T-02, F-10, P-03                                                                                                                                                        |

## Contexte

Une clé de licence est le seul bien qui a de la valeur dans SideKey : elle ne doit jamais être vendue deux fois (objectif O2, BX-03) ni fuiter. Karim (P4) importe des fichiers de milliers de clés et refuse de les voir en clair, pour ne pas être soupçonné en cas de fuite (BX-13). Nathalie (P5) est responsable des données et veut pouvoir restaurer la base sans que la restauration expose les clés (BX-19). Léa (P1) attend sa clé immédiatement après le paiement (BX-02), mais seulement si elle en est propriétaire (BX-03). Chaque révélation doit être tracée (BX-16).

Le DICP porte la confidentialité à **4/4**, le niveau le plus élevé (§11.1), et le §11.2 (A02) cite « TLS, AES-256-GCM, argon2id, secrets dans GitHub et l'environnement ». Le §9.4 fixe le mécanisme : **chiffrement AES-256-GCM** dans la couche d'accès aux données, avec un IV aléatoire ; clé maître fournie par un secret d'environnement, jamais en base, jamais dans Git. Et l'unicité repose sur une **empreinte `HMAC-SHA256(clé normalisée, secret)`** avec contrainte `UNIQUE`, parce que le chiffré ne peut pas servir à vérifier l'unicité : l'IV aléatoire le rend différent à chaque chiffrement.

Trois contraintes pèsent sur la mise en œuvre. La base reçoit des imports par lot, jusqu'à 1 000 lignes en synchrone en v1 (US-52). Le déchiffrement doit rester dans la couche d'accès applicative (§8.2), donc pas dans le SGBD. Et les secrets sont séparés par environnement dans GitHub Environments (`staging`, `production`, §15.4), avec secret scanning et push protection activés.

Enfin le mécanisme doit être **démontrable** : le jury attend la preuve que la clé n'est pas lisible en base et qu'une clé altérée est refusée. Un chiffrement que l'on ne sait ni expliquer ni tester ne vaut pas mieux, ici, qu'une absence de chiffrement.

Le critère qui tranche est la **confidentialité 4/4** du DICP, combinée à l'obligation de restituer la clé à son acheteur : un mécanisme qui empêche la restitution est disqualifié d'emblée, et un mécanisme qui place la clé maître au même endroit que les données ne protège de rien.

## Options étudiées

| Option                                          | Avantages                                                                                                   | Inconvénients                                                                                                                            | Verdict                                            |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| AES-256-GCM + empreinte HMAC-SHA256             | Chiffrement authentifié en une opération ; détection de doublon par empreinte ; pas de clé en clair en base | Deux secrets à gérer ; rotation de la clé maître à prévoir                                                                               | **Retenue** — couvre BX-02, BX-03, BX-13, DICP 4/4 |
| Hachage seul (SHA-256 de la clé)                | Irréversible, pas de clé maître, unicité immédiate                                                          | La clé ne peut plus être restituée au client : BX-02 devient impossible                                                                  | Écartée — besoin BX-02                             |
| AES-256-CBC + HMAC séparé (encrypt-then-MAC)    | Chiffrement éprouvé, authentification explicite                                                             | Deux opérations et deux clés à ordonner correctement ; une inversion MAC/chiffré casse tout sans erreur visible                          | Écartée — simplicité et sécurité                   |
| Chiffrement du disque ou du volume (TDE)        | Transparent pour l'application, aucun code de chiffrement à écrire                                          | Protège le support volé, pas un `pg_dump`, une réplication ni un `SELECT` avec un compte compromis ; aucun contrôle visible dans le code | Écartée — BX-13, DICP 4/4                          |
| `pgcrypto` (chiffrement exécuté par PostgreSQL) | Un seul point de déchiffrement, aucune couche applicative à écrire                                          | La clé maître traverserait les requêtes, donc les logs SQL et les traces ; contredit le §8.2                                             | Écartée — sécurité (A02, A09)                      |
| Clé en clair, protégée par les droits SGBD      | Simple et rapide                                                                                            | Un `pg_dump` ou une compromission de `sk_app` expose toutes les clés ; DICP 4/4 non tenu                                                 | Écartée — BX-13, confidentialité 4/4               |

## Décision

Une clé de licence est **chiffrée en AES-256-GCM** dans la couche d'accès aux données, avec un **IV aléatoire unique par chiffrement** et le tag d'authentification conservé avec le chiffré dans la colonne `cle_chiffree` (`BYTEA`, §9.2). La **clé maître de 32 octets** est fournie par un secret d'environnement, jamais en base, jamais dans Git ; elle est distincte par environnement (§15.4).

L'unicité repose sur une **empreinte `HMAC-SHA256(clé normalisée, secret)`** stockée dans `empreinte`, avec contrainte `UNIQUE` (RG-03). Un import qui présente une empreinte déjà connue est rejeté comme doublon et compté dans le rapport (US-52).

Le déchiffrement n'existe qu'à un seul endroit : `LicenceService::reveler()`, après passage du **Voter** de propriété et vérification que la commande est `PAYEE` (§9.4, §11.5). Chaque révélation est journalisée dans `journal_audit` (BX-16). Le back-office n'affiche qu'une forme masquée (BX-13), et le secret TOTP des comptes utilise le même mécanisme.

## Conséquences

### Positives

- Un `pg_dump` volé, une sauvegarde restaurée ou une lecture directe de `cle_licence` ne livrent que des chiffrés : la confidentialité 4/4 du DICP est tenue sans dépendre des droits SQL.
- L'unicité reste vérifiable en base malgré l'IV aléatoire, grâce à l'empreinte : deux imports du même fournisseur sont détectés ligne à ligne, ce qui répond à BX-11 et BX-03.
- GCM authentifie le chiffré : une clé altérée dans la base est rejetée au déchiffrement au lieu d'afficher une valeur fausse (§14.1).
- La séparation des rôles est cohérente : `sk_app` n'a pas le `DELETE` sur `cle_licence` (§9.5) et le trigger `trg_cle_licence_protection` interdit de modifier une clé `VENDUE` (RG-04).
- Le même mécanisme protège `totp_secret_chiffre`, sans introduire un second système cryptographique.
- La clé n'est jamais écrite en clair dans les journaux : le journal d'audit enregistre l'action de révélation, pas la valeur, ce qui satisfait A09 (§11.2) et BX-16.

### Négatives et risques

- **Rotation de la clé maître à prévoir** : elle n'est pas outillée en v1. Changer la clé impose de déchiffrer avec l'ancienne puis de rechiffrer toutes les clés de licence, donc un identifiant de version de clé dans le chiffré et une procédure de migration. Sans cela, une clé compromise est irremplaçable — c'est le premier sujet d'ADR en v2.
- **La perte de la clé maître est définitive** : plus aucune clé de licence ne peut être révélée, y compris pour des commandes payées. Le secret doit être sauvegardé hors ligne et documenté, pas seulement déposé dans GitHub Environments.
- **Deux secrets, pas un** : la clé AES et le secret HMAC doivent être distincts et gérés séparément. Si le secret HMAC change, toutes les empreintes deviennent incomparables : les doublons ne sont plus détectés et l'unicité est perdue jusqu'à un réimport complet.
- **Aucune recherche sur la valeur** : un chiffré n'est pas interrogeable (`LIKE` impossible). L'écran de back-office ne peut donc pas rechercher une clé par son contenu, seulement par offre, statut ou date.
- **L'affichage des 4 derniers caractères pose une question non tranchée** : ils ne se déduisent pas du chiffré. Il faut soit un déchiffrement au back-office — contraire à BX-13 —, soit une colonne dédiée en clair, qui affaiblit légèrement la confidentialité. Ce point doit être arbitré avant F-08.
- **Deux opérations par ligne à l'import** : chiffrement et HMAC pour chaque clé, dans des lots de 500 lignes (§10.3) ; sur 1 000 lignes en synchrone, le coût reste faible mais la transaction devient longue, d'où le report éventuel en asynchrone (P-13).
- **Le déchiffrement dépend du Voter** : une erreur d'autorisation dans `LicenceService::reveler()` exposerait la clé d'autrui. C'est le point le plus sensible du projet en matière d'IDOR (§11.2, A01) et il exige un test dédié (§14.4).
- **Une restauration dépend du secret** : une sauvegarde restaurée sur un autre environnement reste illisible sans la clé maître, ce qui est voulu, mais impose de documenter le lien entre `infra/restore.sh` et les secrets d'environnement (§9.7, §15.4).

### Actions induites

- F-08 : `Infrastructure/Crypto` (chiffrement, déchiffrement, empreinte), import CSV avec rapport, écran d'import, révélation journalisée.
- T-02 : trigger `trg_cle_licence_protection` et contrôle de transition de statut de clé.
- F-10 : écriture du journal d'audit sans valeur de clé (§11.2, A09), consultation en lecture seule (P-11).
- Tests : aller-retour de chiffrement, chiffré altéré rejeté, absence de clé en clair dans la base, les logs et les réponses de l'API (§14.1, F-08).
- Secrets : `APP_AES_KEY` et `APP_HMAC_KEY` dans les environnements GitHub `staging` et `production`, avec secret scanning et push protection (§15.4).
- Documentation : diagramme `seq-import-cles` et `seq-revelation-cle` (voir [conventions UML](../uml/README.md)).
- F-08 : n'exposer en sortie d'API que les quatre derniers caractères de la clé, quel que soit l'arbitrage retenu sur l'écran de back-office.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §6.1 (RG-03, RG-04), §8.2, §9.2, §9.4, §9.5, §11.1, §11.2, §14.1, §15.4.
- [Index des ADR](README.md) — ADR-0002 (accès aux données), ADR-0006 (couche d'accès et Voters).
- [Conventions des diagrammes UML](../uml/README.md) — `seq-import-cles`, `seq-revelation-cle`, `etats-cle-licence`.

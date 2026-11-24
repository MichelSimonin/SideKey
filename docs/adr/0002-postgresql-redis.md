# ADR-0002 — PostgreSQL (relationnel) et Redis (NoSQL clé/valeur)

| Élément                        | Valeur                                                                                                                                                                                             |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                            |
| Date                           | 2026-11-23                                                                                                                                                                                         |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                            |
| Décision du cahier des charges | R1 (§0.3) — NoSQL : « Redis seul », en réponse à la question F4 (§0.1)                                                                                                                             |
| Portée                         | Choix et répartition des deux bases : données de référence, données volatiles, TTL, droits d'accès, sauvegarde. Ne couvre ni le chiffrement des clés (ADR-0004) ni le format des jetons (ADR-0003) |
| Tickets liés                   | #4 (C-03) · T-01, T-02, F-01, F-03, F-05, F-07                                                                                                                                                     |

## Contexte

SideKey manipule deux familles de données très différentes. D'un côté des données de référence et de preuve : jeux, offres, clés de licence, commandes, lignes de commande, paiements, avis, journal d'audit (§9.2). Elles exigent des transactions, des contraintes d'intégrité et des triggers : une clé ne doit jamais être vendue deux fois (objectif O2, BX-03), une clé `VENDUE` ne peut plus être modifiée (RG-04), et chaque changement de statut de commande est historisé (§6.4, RG-21). Le REAC (compétences 7 et 8, §2) demande en outre un MCD en 3FN, des procédures et triggers PL/pgSQL, et des rôles SGBD à moindre privilège.

De l'autre côté des données volatiles, dont la valeur tient à leur durée de vie : panier (7 j pour un client, 24 h pour un visiteur, US-10), réservation de clés (15 min, RG-12), jetons de rafraîchissement et liste de révocation (7 j), limitation de débit, compteurs anti-fraude sur 24 h, cache catalogue (5 min), clé d'idempotence du paiement (24 h). Le REAC cite explicitement le « format clé/valeur » (compétence 8), ce qui impose de désigner explicitement la base NoSQL retenue (F4).

Le binôme a tranché en deux temps : PostgreSQL + Redis, MongoDB n'étant qu'une option (F4), puis **Redis seul** (R1). Le cahier des charges fixe la répartition au §9.8 et la règle de sécurité qui en découle : « Aucune donnée de référence dans Redis : la perte de Redis coûte des paniers et des sessions, jamais des commandes ni des clés. » L'image Redis du dépôt est persistée en AOF, ce qui n'en fait pas une base de référence pour autant.

La conséquence pratique est une règle de conception simple, vérifiable en revue : aucune donnée dont la perte serait irréversible ne peut être écrite dans Redis. Une commande, un paiement ou une clé de licence ne quittent jamais PostgreSQL, même temporairement.

Le critère qui tranche est la **nature de la donnée**, pas la performance : ce qui doit être prouvé, contraint et restauré va dans PostgreSQL ; ce qui doit expirer, se compter ou se recalculer va dans Redis. Le second critère est l'attendu du REAC (compétence 8), qui demande explicitement une base relationnelle **et** une base NoSQL de type clé/valeur.

## Options étudiées

| Option                                        | Avantages                                                                                                                                                   | Inconvénients                                                                                                                           | Verdict                                                     |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| PostgreSQL + Redis                            | ACID, `FOR UPDATE SKIP LOCKED` et PL/pgSQL d'un côté ; TTL natif, compteurs atomiques et cache de l'autre ; deux technologies distinctes démontrées au jury | Deux systèmes à sécuriser, superviser et expliquer                                                                                      | **Retenue** — couvre BX-01, BX-03, BX-04, BX-15, REAC C7/C8 |
| PostgreSQL seul                               | Un seul système, une seule sauvegarde, aucune synchronisation                                                                                               | L'attendu NoSQL du REAC n'est pas couvert ; TTL, compteurs et cache à recoder en SQL, avec écritures et purge sur la base de référence  | Écartée — REAC compétence 8                                 |
| Redis comme base de référence (tout en Redis) | Très rapide, un seul magasin                                                                                                                                | Pas de contraintes, de clés étrangères ni de triggers ; commandes et clés dans un cache volatile ; aucun modèle relationnel à présenter | Écartée — O2, RG-04, §9.8                                   |
| MongoDB (base document)                       | Modèle souple, bonne intégration des avis                                                                                                                   | Aucun besoin documentaire identifié : le périmètre est limité aux clés, et les avis tiennent dans une table                             | Écartée — R1                                                |
| MySQL + Redis                                 | Relationnel éprouvé, `SKIP LOCKED` disponible                                                                                                               | Rôles et PL moins riches ; PL/pgSQL exigé par le REAC et par les triggers du §9.6                                                       | Écartée — §8.3, attendu REAC                                |

## Décision

**PostgreSQL** est la base relationnelle de référence : elle porte toutes les entités du §9.2, les contraintes d'intégrité, les procédures et triggers du §9.6, les comptes `sk_owner`, `sk_app`, `sk_reporting` et `sk_backup` du §9.5. **Redis** est la base NoSQL clé/valeur, et **uniquement** pour les usages listés au §9.8 : panier, réservation, jetons de rafraîchissement, révocation, limitation de débit, compteurs anti-fraude, cache catalogue, idempotence du paiement.

Les TTL sont ceux du cahier des charges : panier **7 j** (client) et **24 h** (visiteur), réservation **15 min**, jeton de rafraîchissement **7 j**, cache catalogue **5 min**, compteurs anti-fraude **24 h**, idempotence **24 h**. Aucune donnée de référence ne vit dans Redis.

Redis tourne sur le réseau Docker interne uniquement, avec mot de passe, ACL par préfixe de clé et commandes dangereuses désactivées (§9.8). Les noms de clés sont construits à partir d'identifiants validés, jamais de chaînes fournies par l'utilisateur (§11.2, A03).

## Conséquences

### Positives

- Une clé n'est attribuée qu'à une commande : la sélection se fait sous verrou avec `FOR UPDATE SKIP LOCKED` (§10.1), ce que Redis seul ne saurait garantir (O2).
- Les TTL sont natifs : panier expiré, réservation libérée, cache périmé, compteur remis à zéro sans tâche de purge maison (BX-03, BX-04).
- Les compteurs de limitation de débit et d'anti-fraude sont atomiques et rapides, sans écrire dans la base de référence à chaque tentative (BX-15).
- La charge de lecture du catalogue est absorbée par le cache de 5 min, ce qui sert la cible ENF-01 (< 200 ms au 95e centile) pour Léa (BX-01).
- Les deux compétences SQL et NoSQL du REAC sont démontrées par du code réel : repositories Doctrine et PL/pgSQL d'un côté, `Infrastructure/Redis` de l'autre.
- Les rôles SGBD à moindre privilège limitent l'impact d'une compromission de l'API (§9.5).
- Chaque magasin reste remplaçable : changer la politique de cache ou déplacer le panier ne touche ni les commandes ni les clés, ce que le §9.8 protège explicitement.

### Négatives et risques

- **Perte de Redis** : les paniers en cours, les réservations actives, les jetons de rafraîchissement et les compteurs disparaissent. Les commandes et les clés restent en base, mais les clients sont déconnectés et une réservation perdue doit être libérée par la tâche planifiée `fn_liberer_reservations_expirees()` (§9.6). La persistance AOF réduit la fenêtre, elle ne la supprime pas.
- **Pas de sauvegarde de Redis** : aucun RPO n'est défini pour ces données. C'est acceptable pour un cache et un panier, à condition de l'écrire dans le dossier et de ne jamais y placer de donnée de référence.
- **Deux systèmes à exploiter** : deux mots de passe, deux images à mettre à jour (Dependabot), deux contrôles de santé, deux sources de logs. La charge retombe sur un binôme de deux personnes pendant 2 à 3 semaines.
- **Invalidation du cache** : un cache de 5 min mal invalidé affiche un prix ou une disponibilité périmés. Le prix est de toute façon relu en base au calcul du montant du panier (RG-11, US-13), mais l'écart d'affichage reste possible.
- **Pas de jointure entre les deux bases** : une ligne de panier ne contient qu'un identifiant d'offre et une quantité ; tout le reste est relu dans PostgreSQL, ce qui coûte un aller-retour supplémentaire par affichage du panier.
- **Sous pression mémoire**, une politique d'éviction agressive ferait disparaître des paniers sans erreur visible. La configuration Redis doit fixer explicitement cette politique plutôt que la laisser par défaut.
- **Tests** : les tests d'intégration ont besoin d'un vrai PostgreSQL et d'un vrai Redis (§14.2), donc de services dans la CI (T-03), ce qui allonge chaque exécution.
- **Double écriture dans le tunnel** : la création de commande écrit en base et pose une réservation dans Redis. Les deux ne sont pas atomiques ; une panne entre les deux laisse une réservation orpheline, que seule la tâche de nettoyage des 15 min rattrape.

### Actions induites

- T-01 : migrations Doctrine, contraintes, comptes PostgreSQL avec `GRANT`/`REVOKE`, jeu d'essai, scripts `pg_dump`/`pg_restore`.
- T-02 : fonctions et triggers PL/pgSQL du §9.6, droits `EXECUTE` limités à `sk_app`.
- F-05 : panier Redis avec TTL et fusion visiteur/client (P-10).
- F-01 : cache catalogue et invalidation à la modification du catalogue.
- F-03 et F-07 : `RateLimiter` sur Redis, compteurs `rl:*` et `fraud:*`.
- Infrastructure : service `redis` avec mot de passe, AOF et politique mémoire fixée ; contrôle de santé ; aucune exposition hors du réseau Docker (README).
- Documentation : diagramme `deploiement` et table des clés Redis reprise dans le dossier de projet (voir [conventions UML](../uml/README.md)).
- Revue de PR : vérifier qu'aucune donnée de référence n'est écrite dans Redis et que chaque clé Redis a bien un TTL documenté au §9.8.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.1 (F4), §0.3 (R1), §6.1 à §6.4, §9.1 à §9.8, §10.1, §14.2.
- [Index des ADR](README.md) — ADR-0003 (jetons dans Redis), ADR-0004 (chiffrement des clés), ADR-0006 (accès aux données).
- [README du dépôt](../../README.md) — services `database` (`postgres:17-alpine`) et `redis` (`redis:8-alpine`, AOF).

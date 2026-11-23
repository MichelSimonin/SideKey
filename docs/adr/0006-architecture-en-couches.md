# ADR-0006 — Architecture en couches de l'API

| Élément                        | Valeur                                                                                                                                                                                                              |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                                             |
| Date                           | 2026-11-23                                                                                                                                                                                                          |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                                             |
| Décision du cahier des charges | §8.1 et §8.2 — architecture multicouche répartie, attendu de la compétence 6 du REAC (§2)                                                                                                                           |
| Portée                         | Découpage interne de l'API REST en couches, règles de dépendance, emplacement des Voters, des DTO, des transactions et des exceptions. Ne couvre ni le choix des frameworks (ADR-0001) ni les diagrammes (ADR-0007) |
| Tickets liés                   | #4 (C-03) · T-00, F-01 à F-10, T-02, T-03                                                                                                                                                                           |

## Contexte

Le REAC (compétence 6, §2) exige une « architecture multicouche répartie sécurisée » et demande le rôle de chaque couche selon la stratégie de sécurité. Le cahier des charges la décrit au §8.1 (navigateur, reverse proxy, présentation, métier, accès aux données, puis SGBD) et la détaille au §8.2, couche par couche, avec les contrôles de sécurité attendus : validation des DTO en présentation, Voters et transactions en métier, requêtes paramétrées et chiffrement en accès aux données.

Les besoins s'y rattachent directement. Karim (P4) ne doit jamais accéder aux clés en clair ni écraser la modification d'un collègue (BX-13, BX-14) : cela suppose une couche d'accès aux données qui ne laisse pas passer n'importe quelle écriture. Nathalie (P5) veut tracer les actions sensibles et empêcher la fraude (BX-15, BX-16) : les contrôles doivent être au même endroit pour toutes les routes. Sophie (P3) attend des messages d'erreur compréhensibles et associés aux champs (BX-10) : la présentation doit produire des codes HTTP normalisés et des messages sans fuite technique. Hugo (P7) veut comprendre le code et livrer sans peur (BX-24) : l'emplacement des responsabilités doit être prévisible.

Trois contraintes structurent la réponse. Le projet est en binôme et dure 2 à 3 semaines (R5) : chaque couche ajoute des fichiers, donc du temps. Le jury doit pouvoir vérifier la séparation sans lire tout le code. Enfin les transactions ne sont pas négociables : la création de commande et la réservation des clés forment une seule transaction (§10.3), et le PSP simulé est appelé hors transaction.

Un point de méthode en découle : le découpage se vérifie à la lecture d'un contrôleur. S'il contient une règle métier, une requête SQL ou une décision d'autorisation, la couche est franchie et la revue doit le refuser.

Le critère qui tranche est l'attendu du REAC (compétence 6) et la **testabilité** : chaque règle du §6 doit pouvoir être vérifiée sans navigateur et sans base, et chaque contrôle d'accès doit exister à un seul endroit. Le coût d'abstraction est l'arbitre secondaire, ce qui écarte les architectures plus raffinées mais plus lourdes.

## Options étudiées

| Option                                                                     | Avantages                                                                             | Inconvénients                                                                                                                       | Verdict                                       |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| Couches explicites : contrôleur → service → repository, avec DTO et Voters | Séparation visible et testable ; transactions localisées ; autorisations centralisées | Plus de fichiers par feature ; discipline de revue nécessaire                                                                       | **Retenue** — couvre le REAC C6/C8 et le §8.2 |
| Logique métier dans les contrôleurs                                        | Rapide à écrire, peu de fichiers                                                      | Règles du §6 dupliquées entre routes et non testables unitairement ; transactions diffuses ; aucune couche métier à montrer au jury | Écartée — REAC C6, testabilité                |
| Architecture hexagonale (ports et adaptateurs)                             | Découplage maximal, remplacement d'implémentation aisé                                | Interfaces et adaptateurs à écrire pour chaque dépendance ; abstraction disproportionnée pour trois semaines                        | Écartée — délai (R5)                          |
| Active Record (entités qui se persévèrent elles-mêmes)                     | Peu de code, mapping implicite                                                        | Couche d'accès implicite, donc invisible pour le jury ; entités couplées à la persistance ; verrouillage optimiste moins net        | Écartée — REAC C8, BX-14                      |
| CQRS / event sourcing                                                      | Traçabilité naturelle, lecture optimisée                                              | Complexité d'infrastructure sans besoin identifié ; l'audit est déjà couvert par les triggers et le journal                         | Écartée — aucun besoin persona                |

## Décision

L'API est organisée en trois couches, avec des dépendances qui ne vont que dans un sens : **présentation → métier → accès aux données**.

**Présentation** (`Controller/Api`, `Controller/Admin`, `Dto/Request`, `Dto/Response`, `EventSubscriber`) : routes, désérialisation, validation par Symfony Validator, codes HTTP normalisés, authentification JWT. Un contrôleur ne contient aucune règle du §6 et n'appelle jamais Doctrine directement ; il valide un DTO, appelle un service et renvoie un DTO de sortie.

**Métier** (`Service`, `Security/Voter`, `Message`) : règles de gestion du §6, orchestration, **frontières transactionnelles** (§10.3), anti-fraude, audit. Les **Voters** y sont interrogés avant toute opération sensible, avec le refus comme valeur par défaut ; ils portent le contrôle RBAC et la propriété des ressources, donc l'anti-IDOR (§11.2, A01).

**Accès aux données** (`Repository`, `Infrastructure/Redis`, `Infrastructure/Crypto`, `sql/`) : requêtes Doctrine paramétrées, appels PL/pgSQL, accès Redis, chiffrement et empreinte des clés. Aucun appel HTTP, aucune règle métier.

Les entités ne dépendent d'aucune couche. Aucune entité n'est exposée telle quelle par l'API : les contrats d'entrée et de sortie sont des DTO, ce qui évite l'affectation de masse. Les exceptions métier sont traduites en codes HTTP par un gestionnaire global, sans trace technique (§8.2). Côté SPA, le même sens de dépendance s'applique : `pages` → `features` → `hooks` → `api`, et aucun secret ni accès direct aux bases.

## Conséquences

### Positives

- Les règles du §6 sont testables sans noyau Symfony, ce que prévoit l'arborescence de tests du dépôt (`tests/Unit`, `tests/Integration`, `tests/Security`) et la cible de couverture ENF-07.
- Les autorisations sont écrites une fois : un Voter par ressource couvre toutes les routes, y compris les routes d'administration réservées aux rôles back-office avec A2F (§11.5).
- Les transactions sont lisibles aux bons endroits : création de commande et réservation dans une seule transaction, appel du PSP simulé hors transaction (§10.3).
- Les DTO empêchent un client de renseigner un champ interdit — prix, statut, identifiant de propriétaire —, ce qui répond à RG-11 et à A08 (§11.2).
- L'arborescence `Controller` / `Service` / `Repository` rend la couche attendue prévisible pour Hugo (BX-24) et vérifiable rapidement par le jury.
- Les erreurs ne fuient pas : un seul gestionnaire produit la réponse, avec un code HTTP normalisé et un message traduit en FR et en EN (BX-05, BX-10).
- Le découpage rend les tests ciblés possibles : un Voter se teste seul, un service se teste sans base, un repository se teste en intégration (§14.1, §14.2), ce qui aide à tenir la couverture d'ENF-07.

### Négatives et risques

- **Plus de fichiers par feature** : un cas d'usage simple demande un DTO d'entrée, un DTO de sortie, un service, parfois un Voter et un Mapper. Sur une feature estimée à une journée (F-10), cette mécanique se paie.
- **Risque de services anémiques** : sous la pression du délai, une règle remonte dans le contrôleur ou descend dans le repository. Seule la revue croisée en PR le détecte, et c'est le point de vigilance principal de l'équilibre par feature (F2).
- **Duplication entité / DTO** : le mapping est manuel et doit être maintenu à chaque évolution du modèle ; une erreur de mapping produit un champ absent plutôt qu'une erreur visible.
- **Validation en double** : la SPA valide pour l'ergonomie et l'API valide pour la sécurité (§11.3). Les deux jeux de règles peuvent diverger, au détriment du message affiché à Sophie (BX-10).
- **Couplage assumé à l'infrastructure** : les services appellent directement `Infrastructure/Redis` et le chiffrement, sans interface dédiée. C'est simple, mais un changement de mécanisme toucherait la couche métier.
- **Une part de logique en base** : les triggers et fonctions PL/pgSQL (§9.6) portent des règles d'intégrité. Une partie du comportement échappe donc au code PHP et doit être testée en intégration (§14.2), pas en unitaire.
- **La couche d'accès est la plus sensible** : c'est elle qui détient le chiffrement et les droits SQL. Une erreur y est plus coûteuse qu'ailleurs, et `sk_app` doit rester à privilège minimum (§9.5).
- **Les contrôleurs peuvent grossir** : la tentation est d'y traiter un cas particulier plutôt que d'ouvrir un service. Chaque exception acceptée en revue affaiblit la règle de dépendance, et les contrôleurs d'administration sont les premiers concernés.

### Actions induites

- T-00 : création de l'arborescence `src/` du [README](../../README.md) et des dossiers de tests.
- Toutes les features : respect de la règle de dépendance, contrôle en revue de PR (Définition de terminé).
- T-02 : fonctions et triggers du §9.6 livrés dans les migrations, avec tests d'intégration.
- T-03 : PHPStan niveau 8 et 0 erreur ESLint en bloquant (ENF-08) pour tenir la séparation sur la durée.
- Diagramme `archi-couches` (voir [conventions UML](../uml/README.md)), repris dans le dossier de projet au titre de la compétence 6.
- ADR connexes : ADR-0004 pour la couche d'accès chiffrée, ADR-0003 pour la couche de sécurité des jetons.
- Revue de PR : vérifier qu'aucun contrôleur n'appelle Doctrine, qu'aucun repository ne contient de règle du §6 et que chaque opération sensible est couverte par un Voter.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §2 (compétences 6 à 8), §6, §8.1, §8.2, §8.5, §10.3, §11.2, §11.3, §11.5, §13, §14.
- [Index des ADR](README.md) — ADR-0001 (API et SPA), ADR-0003 (authentification), ADR-0004 (clés de licence), ADR-0007 (diagrammes).
- [Conventions des diagrammes UML](../uml/README.md) — `archi-couches`, `classes-domaine`.
- [README du dépôt](../../README.md) — arborescence `api/src/` et `front/src/`.

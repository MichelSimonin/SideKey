# Dossier de projet — SideKey

## Boutique en ligne de clés de licence de jeux vidéo

| Élément | Valeur |
|---|---|
| Titre visé | Concepteur Développeur d'Applications (CDA) — REAC TP-01281 v04 du 24/05/2023 |
| Activité type couverte | **AT2** — Concevoir et développer une application sécurisée organisée en couches · **AT3** (partie DevOps : CI/CD) |
| Projet | SideKey, projet de formation en binôme (MSP) |
| Auteurs | [Michel Simonin] · [Benjamin Nativel] |
| Version du dossier | **v1 — 24/11/2026** (première version, rédigée en cours de projet ; mises à jour le 24/11 : PR #40, vérification de la conception C-03 et décisions sur le modèle de données) |
| Dépôt | `MichelSimonin/SideKey` sur GitHub |

> **Comment lire cette version.** Le dossier est rédigé au fil du projet, comme le prévoit la définition de « terminé » du backlog. Chaque section porte un état :
> ✅ rédigé à partir de livrables existants · 🟡 partiellement rédigé · ⏳ à rédiger quand la fonctionnalité sera livrée.
> Le récapitulatif complet est au [§13](#13-état-davancement-de-la-v1).
>
> **Dossier individuel.** Chaque membre du binôme présente son propre dossier. Cette v1 est une **base commune** : chacun la reprend, met en avant les features qu'il a menées de bout en bout (§4.3) et rédige seul les §10 et §11.

---

## Sommaire

1. [Compétences du référentiel couvertes](#1-compétences-du-référentiel-couvertes)
2. [Résumé du projet / Project summary](#2-résumé-du-projet--project-summary)
3. [Contexte et expression des besoins](#3-contexte-et-expression-des-besoins)
4. [Gestion de projet](#4-gestion-de-projet)
5. [Spécifications fonctionnelles](#5-spécifications-fonctionnelles)
6. [Spécifications techniques](#6-spécifications-techniques)
7. [Sécurité](#7-sécurité)
8. [Réalisations : extraits de code commentés](#8-réalisations--extraits-de-code-commentés)
9. [Plan de tests et jeu d'essai](#9-plan-de-tests-et-jeu-dessai)
10. [Veille sur les vulnérabilités](#10-veille-sur-les-vulnérabilités)
11. [Situation de travail : recherche anglophone](#11-situation-de-travail--recherche-anglophone)
12. [Conclusion et perspectives](#12-conclusion-et-perspectives)
13. [État d'avancement de la v1](#13-état-davancement-de-la-v1)
14. [Annexes](#14-annexes)

---

## 1. Compétences du référentiel couvertes

| Compétence (AT2) | Ce qui la démontre dans SideKey | Section | État |
|---|---|---|---|
| **C5** — Analyser les besoins et maquetter une application | 7 personas, besoins BX-01 à BX-24, user stories MoSCoW, maquettes Figma FR (EN en cours), schéma d'enchaînement, exigences RGAA, RGPD et éco-conception | §3, §5 | 🟡 |
| **C6** — Définir l'architecture logicielle d'une application multicouche répartie | Architecture SPA ↔ API REST en couches ↔ PostgreSQL + Redis, matrice de justification de la stack, 8 ADR, diagrammes de composants et de déploiement, DICP, patrons de conception et de sécurité | §6, §7 | ✅ conception · ⏳ implémentation |
| **C7** — Concevoir et mettre en place une base de données relationnelle | MCD (20 entités), MLD (21 tables), MPD PostgreSQL 17 ; rôles SGBD, jeu d'essai, sauvegarde et restauration | §6.4, §8.5 | ✅ conception · ✅ schéma, rôles, jeu d'essai (T-01) · ✅ PL/pgSQL (T-02) |
| **C8** — Développer des composants d'accès aux données SQL et NoSQL | Repositories Doctrine, PL/pgSQL défensif, `FOR UPDATE SKIP LOCKED`, verrouillage optimiste, Redis (panier, réservations, jetons, limitation de débit) | §6.6, §6.7, §8 | 🟡 PL/pgSQL et `SKIP LOCKED` faits (T-02) · ⏳ repositories, Redis |
| **AT3 (partie DevOps)** — Préparer et automatiser le déploiement | Environnement Docker de développement, CI GitHub Actions bloquante, protection de `main`, CD vers une recette dans l'UE | §4.4, §8.1 | 🟡 CI faite · ⏳ CD (T-04) |

---

## 2. Résumé du projet / Project summary

### 2.1 Résumé (français)

SideKey est une boutique en ligne **fictive** de **clés de licence** de jeux vidéo (Steam, PlayStation, Xbox, Nintendo). Le client choisit un jeu, paie (paiement **simulé**) et récupère sa clé immédiatement dans son espace. Un back-office permet aux gestionnaires d'importer des clés en lot, de gérer le catalogue, de traiter les commandes suspectes et de modérer les avis.

La difficulté du projet tient à la nature du produit : **une clé est un bien unique et sensible**. Elle ne doit jamais être vendue deux fois, même si deux clients achètent la dernière en même temps, et elle ne doit jamais fuiter, y compris aux yeux des employés. Le projet répond à ces contraintes par une architecture en couches (React, API Symfony, PostgreSQL, Redis), un verrouillage des lignes en base, le chiffrement AES-256-GCM des clés, une authentification JWT avec A2F et un journal d'audit immuable. L'application est bilingue français / anglais, conçue pour l'accessibilité (RGAA) et l'éco-conception, et livrée par une chaîne CI/CD GitHub Actions.

### 2.2 Summary (English)

SideKey is a **fictional** online store selling video game **licence keys** (Steam, PlayStation, Xbox, Nintendo). Customers pick a game, pay through a **simulated** payment provider and get their key instantly in their account. A back office lets staff import keys in bulk, manage the catalogue, review suspicious orders and moderate reviews.

The main challenge comes from the product itself: **a key is a unique and sensitive asset**. It must never be sold twice, even when two customers buy the last one at the same time, and it must never leak, not even to employees. The project addresses this with a layered architecture (React, Symfony API, PostgreSQL, Redis), row-level locking in the database, AES-256-GCM encryption of keys, JWT authentication with two-factor authentication, and an append-only audit log. The application is bilingual (French / English), designed for accessibility and low environmental impact, and delivered through a GitHub Actions CI/CD pipeline.

---

## 3. Contexte et expression des besoins

### 3.1 Contexte et objectifs ✅

| # | Objectif | Indicateur de réussite |
|---|---|---|
| O1 | Acheter un jeu en moins de 5 écrans | Tunnel catalogue → fiche → panier → paiement → confirmation |
| O2 | Une clé n'est attribuée qu'à une seule commande | 0 doublon au test de concurrence |
| O3 | Protéger les clés et les données personnelles | Clés chiffrées, A2F back-office, tests OWASP passés |
| O4 | Outiller les gestionnaires | Catalogue, import de clés, commandes, modération |
| O5 | Application bilingue FR / EN | 100 % des libellés traduits |
| O6 | Livrer en continu de façon fiable | Pipeline CI/CD vert, déploiement automatisé en recette |

**Périmètre v1** : catalogue, recherche et filtres, fiche jeu, panier, compte client (A2F facultative), commande, paiement simulé, livraison des clés dans l'espace client, avis, back-office, FR / EN, CI/CD.
**Hors périmètre** : jeux d'occasion et stock physique (périmètre limité aux clés de licence), paiement réel, autres langues, application mobile native, autre base NoSQL que Redis.


### 3.2 Une démarche guidée par les personas ✅

Règle que nous nous sommes fixée, en cohérence avec le REAC : **chaque outil et chaque technologie doit être justifié par un besoin exprimé par un persona fictif**. Sept personas ont été définis ; chacun porte des besoins numérotés (`BX-nn`) qui sont repris dans les user stories, les écrans et la matrice de justification de la stack.

| Persona | Profil | Besoins clés |
|---|---|---|
| P1 Léa | 23 ans, étudiante, achète sur mobile | Recherche rapide (BX-01), clé immédiate (BX-02), confiance (BX-03), tunnel court (BX-04) |
| P2 Marcus | Anglophone installé en France | Application entièrement en anglais (BX-05, BX-06), A2F (BX-07) |
| P3 Sophie | Malvoyante, lecteur d'écran | Navigation clavier (BX-08), PEGI lisible (BX-09), erreurs claires (BX-10) |
| P4 Karim | Gestionnaire catalogue | Import en lot (BX-11), stock bas (BX-12), clés jamais en clair (BX-13), pas d'écrasement (BX-14) |
| P5 Nathalie | Gérante, administratrice | Anti-fraude (BX-15), traçabilité (BX-16), RGPD et UE (BX-17), A2F obligatoire (BX-18), restauration (BX-19) |
| P6 Thomas | Modérateur | Modérer sans accès aux commandes ni aux clés (BX-20) |
| P7 Hugo | Développeur qui reprendra le projet | Installation en une commande (BX-21), tests et qualité (BX-22), déploiements réversibles (BX-23), historique lisible (BX-24) |

### 3.3 User stories et règles de gestion ✅

Les besoins sont traduits en **user stories priorisées MoSCoW**, avec critères d'acceptation : catalogue (US-01 à US-05), panier (US-10 à US-13), compte (US-20 à US-25), commande et paiement (US-30 à US-36), avis (US-40 à US-42), back-office (US-50 à US-58). Elles sont accompagnées de règles de gestion numérotées, dont :

- **RG-04** — cycle de vie d'une clé : `DISPONIBLE` → `RESERVEE` → `VENDUE` ; une clé vendue ne peut plus être modifiée ;
- **RG-11** — le montant est toujours calculé côté serveur ;
- **RG-12** — les clés sont réservées 15 minutes pendant le paiement ;
- **RG-14** — anti-fraude de niveau « Équilibré » : 5 clés par offre et par commande, 10 clés par client sur 24 h, blocage d'1 h après 3 échecs de paiement, commande mise « à vérifier » si le compte a moins de 24 h et le montant dépasse 150 €, ou si la première commande dépasse 200 €.

Le niveau anti-fraude a été **choisi par comparaison** de trois niveaux (Souple, Équilibré, Strict) selon leur effet sur Léa (fluidité) et sur Nathalie (maîtrise du risque).

Source : CDC §5 et §6.

---

## 4. Gestion de projet

### 4.1 Méthode ✅

- **Durée** : 2 à 3 semaines, soutenance en semaine 3 ; planning de 13 jours.
- **Sprints journaliers**, adaptés à la durée courte du projet : planification de 15 min, point de mi-journée, démo du soir sur la recette, rétrospective express, compte rendu.
- **Le binôme porte le rôle de Product Owner** ; les personas servent de voix de l'utilisateur.
- **Gestion par feature** : chaque membre mène des features de bout en bout (front, API, base, tests), pour pouvoir défendre seul toutes les compétences.

### 4.2 Outils ✅

| Besoin | Outil | Justification |
|---|---|---|
| Backlog, Kanban, jalons | GitHub Projects | Lié aux issues, PR et CI au même endroit |
| Code et revues | GitHub, PR obligatoires | Relecture croisée systématique |
| CI/CD | GitHub Actions | Bloque la fusion si un contrôle échoue |
| Modélisation | PlantUML | Diagrammes en texte, versionnés et relus en PR |
| Maquettes | Figma | Composants, variantes FR / EN, desktop et mobile |
| Environnement | Docker Compose + Makefile | Installation identique sur chaque poste (BX-21) |

### 4.3 Backlog et répartition ✅

Le backlog compte **36 éléments**, importés dans GitHub Projects par un script (`scripts/import_backlog.py`) qui crée les issues, labels, jalons et champs (Priorité, Attribution, Estimation, Jour, ID) :

| Groupe | Contenu | Attribution |
|---|---|---|
| Conception et socle (S1) | C-01 à C-03, T-00 à T-04, F-02 à F-05 | Ensemble, M1, M2 |
| Features prioritaires (S2) | F-01, F-06 à F-10, T-03 | M1 / M2 |
| Pool (J10+) | P-01 à P-13, classées par rang | Attribuées au fil de l'avancement |
| Livraison (S3) | L-01 à L-05 | Ensemble |

Règles d'attribution du pool : le premier membre libre prend la feature la mieux classée ; une seule feature en cours par personne ; une feature en retard est réattribuée à la revue du soir ; aucune nouvelle feature après le J10.

> ⏳ **À compléter par chaque candidat** : la liste des features menées personnellement, avec le lien des PR.

### 4.4 Conventions Git et protection de `main` ✅

- **Une branche par tâche**, nommée `<type>/<ID>-<description>` (ex. `ci/T-00-ci-et-tests-unitaires`, `docs/C-02-maquettes-enchainement-ecrans`), créée depuis un `main` à jour.
- **Conventional Commits** ; le titre de la PR devient le message du commit (squash merge) et il est vérifié en CI (`pr-title`).
- **Protection de `main`** : PR obligatoire, approbation de l'autre membre, contrôles `ci-success` et `pr-title` exigés, historique linéaire, pas de push forcé, aucune exception même pour les administrateurs ; tags `v*` protégés.
- **Sécurité du dépôt** : A2F sur les comptes, Dependabot, secret scanning et push protection, aucun `.env` versionné (seuls les `.env.example`).

### 4.5 Historique réel ✅

| PR | Contenu | Issue |
|---|---|---|
| #37 | CI GitHub Actions et premiers tests unitaires | T-00 (#2) |
| #38 | Modélisation des données, architecture, 8 ADR, analyse DICP | C-03 (#4, fermée) |
| #39 | Inventaire des écrans, enchaînement, maquettes Figma desktop | C-02 (#3) |
| #40 | Préparation du mobile et de l'anglais : textes FR → EN de E01 à E08, spécification mobile, grille de revue persona | C-02 (#3) |
| #41 | Première version du dossier de projet ; décisions de cadrage reformulées en choix du binôme selon le REAC | L-03 (#34) |
| #42 | Décisions sur les 4 points ouverts du modèle de données | C-03 (#4) |
| #43 | Internationalisation FR / EN : react-i18next, sélecteur, `lang`, erreurs de l'API traduites selon `Accept-Language` | F-02 (#7, fermée) |
| #44 | Schéma PostgreSQL, contraintes, comptes SGBD, jeu d'essai, sauvegarde et restauration | T-01 (#5, fermée) |
| (en cours) | Fonctions et triggers PL/pgSQL défensifs, test de concurrence sur la dernière clé | T-02 (#8) |

> ⏳ Les comptes rendus quotidiens (`docs/cr/AAAA-MM-JJ.md`) prévus au CDC §16.1 ne sont pas encore tenus : à démarrer pour alimenter ce paragraphe.

---

## 5. Spécifications fonctionnelles

### 5.1 Acteurs et cas d'utilisation ✅

| Acteur | Persona | Rôle |
|---|---|---|
| Visiteur | P1, P2, P3 | Consulte le catalogue |
| Client | P1, P2, P3 | Commande, révèle ses clés, dépose des avis |
| Gestionnaire | P4 | Catalogue, offres, import de clés |
| Modérateur | P6 | Modération des avis |
| Administrateur | P5 | Comptes et rôles, audit, commandes suspectes |
| PSP simulé, serveur SMTP | — | Systèmes externes |

Diagrammes : [cas d'utilisation global](../uml/exports/uc-global.svg), [front-office](../uml/exports/uc-front-office.svg), [back-office](../uml/exports/uc-back-office.svg).

### 5.2 Maquettes et enchaînement des écrans 🟡

16 écrans (E01 à E16) sont spécifiés ; chacun est relié aux user stories, aux besoins et aux personas qui le justifient, avec ses points RGAA ([inventaire](../maquettes/ecrans.md)).

- **Schéma d'enchaînement** en PlantUML ([source](../maquettes/enchainement-ecrans.puml), [SVG](../maquettes/enchainement-ecrans.svg)) :

![Enchaînement des écrans de SideKey](../maquettes/enchainement-ecrans.png)

- **Maquettes Figma** « SideKey — Maquettes » : composants SideKey (en-tête et pied de page FR / EN, carte jeu, badge PEGI, étapes du tunnel, bandeau « paiement fictif », menu admin) construits sur les composants et tokens du Simple Design System ; **E01 à E16 en desktop FR** réalisés.
- 📝 Préparés : [spécification des écrans mobiles](../maquettes/mobile.md) (E02, E03, E04, E07), [textes FR → EN](../maquettes/traduction-en.md) du parcours E01 → E08, [grille de revue persona](../maquettes/revue-persona.md).
- ⏳ Restent : dessiner le **mobile** et le **parcours EN** dans Figma, puis faire la **revue persona** à deux.

> ⏳ Insérer ici les captures des écrans clés (E02 catalogue, E03 fiche jeu, E07 paiement, E14 commandes à vérifier) exportées de Figma.

### 5.3 Exigences transverses ✅

| Domaine | Exigences principales |
|---|---|
| **Accessibilité (RGAA 4)** | Contrastes AA, navigation clavier, focus visible, labels et erreurs reliés aux champs, `lang` mis à jour, PEGI et stock écrits en texte |
| **RGPD** | Minimisation (pas d'adresse, pas de date de naissance), durées de conservation (anonymisation après 3 ans d'inactivité, 10 ans pour les pièces comptables, 6 mois à 1 an pour les journaux), export et suppression du compte, hébergement UE |
| **Éco-conception (RGESN)** | Images WebP en différé, pagination, cache Redis, une seule police ; cible page < 1 Mo et EcoIndex B |
| **Multilingue** | FR / EN uniquement ; interface, erreurs de l'API, e-mails et contenus du catalogue traduits ; test CI si une clé manque |

---

## 6. Spécifications techniques

### 6.1 Stack et justification ✅

| Couche | Choix | Besoins couverts | Alternative écartée |
|---|---|---|---|
| API | **Symfony 7.4 LTS**, PHP 8.4 | BX-03, BX-15, BX-16, BX-18, BX-22 | Laravel : couches moins explicites |
| ORM | Doctrine ORM + Migrations | BX-03, BX-14, BX-21 | SQL écrit à la main partout |
| Front | **React 19** + Vite + TypeScript | BX-01, BX-04, BX-08 | Rendu serveur Twig |
| SGBDR | **PostgreSQL 17** | BX-03, BX-19 | MySQL : rôles et PL moins riches |
| NoSQL | **Redis 8** (clé/valeur) | BX-01, BX-04, BX-15 | MongoDB : pas de besoin identifié |
| Authentification | JWT + jeton de rafraîchissement + A2F TOTP | BX-03, BX-07, BX-18 | Session serveur, A2F par SMS |
| Conteneurs | Docker Compose | BX-21, BX-23 | Installation locale |
| CI/CD | GitHub Actions | BX-22, BX-23 | GitLab CI |

Chaque décision structurante est tracée dans un **ADR** (contexte, options étudiées, décision, conséquences) : [index des 8 ADR](../adr/README.md).

### 6.2 Architecture en couches ✅

```text
Navigateur (SPA React)
   │  HTTPS, JSON, jeton d'accès en mémoire
   ▼
API REST Symfony
   ├── Présentation : contrôleurs, DTO validés, codes HTTP
   ├── Métier       : services, Voters (RBAC, propriété), transactions, anti-fraude, audit
   └── Données      : repositories Doctrine, appels PL/pgSQL, client Redis, chiffrement
   ▼                         ▼
PostgreSQL (référence)     Redis (paniers, réservations, jetons, compteurs, cache)
```

Chaque couche ne dépend que de la couche inférieure ; les règles de dépendance sont vérifiées en revue. Détail, cycle de vie d'une requête et contrat d'erreurs : [architecture en couches](../architecture/architecture-couches.md) · diagrammes [composants](../uml/exports/archi-couches.svg) et [déploiement](../uml/exports/deploiement.svg).

**Patrons de conception** : Repository, DTO, Service Layer, Strategy (fournisseur de paiement), Factory, Observer (événements Symfony), Command (Messenger).

### 6.3 Modélisation UML ✅

17 diagrammes PlantUML versionnés (`docs/uml/src/`), exportés en PNG et SVG par `make uml` : cas d'utilisation, classes du domaine, états (commande, clé de licence), séquences (achat concurrent, paiement, connexion JWT, révélation de clé, import de clés). Index : [docs/uml](../uml/README.md).

### 6.4 Base de données relationnelle ✅

- **MCD** de 20 entités et 26 associations, **MLD** de 21 tables, **MPD** PostgreSQL 17 avec types, contraintes, index partiels et triggers : [modèle de données commenté](../architecture/modele-donnees.md), [MCD](../uml/exports/mcd.svg), [MLD](../uml/exports/mld.svg), [MPD](../uml/exports/mpd.svg).
- **Contraintes clés** : empreinte HMAC unique par clé, une seule offre active par couple jeu × plateforme (index unique partiel), `CHECK (quantite BETWEEN 1 AND 10)`, colonne `version` pour le verrouillage optimiste.
- **Comptes SGBD à moindre privilège** : `sk_owner` (migrations), `sk_app` (API, sans `DELETE` sur les clés, commandes, paiements et audit), `sk_reporting` (lecture de vues sans données personnelles), `sk_backup`.
- **Objets PL/pgSQL défensifs** (T-02) : `fn_reserver_cles`, `fn_valider_commande` (idempotente), `fn_liberer_reservations_expirees` ; triggers de transitions de statut (§6.4 du CDC), d'historique, de protection des clés vendues et d'immuabilité du journal d'audit et de l'historique. Paramètres vérifiés, `SECURITY INVOKER`, `search_path` figé, `EXECUTE` pour `sk_app` seulement, codes d'erreur `SK001` à `SK005` traduits en HTTP 409 par l'API ([modèle de données §4.4](../architecture/modele-donnees.md), extrait §8.5).
- **Jeu d'essai** (T-01) : 40 jeux FR / EN, 60 offres, 500 clés dans tous les états, 20 utilisateurs de tous rôles, commandes dans chaque statut ; sauvegarde `pg_dump -Fc` et restauration `pg_restore --clean`.

- **Preuves automatisées** (job CI « database ») : migrations sur base vide, tests SQL des contraintes, des droits, des fonctions et des triggers (cas d'erreur et cas nominaux), sauvegarde puis restauration comparée table par table, scénario de concurrence à deux sessions, annulation complète des migrations.

### 6.5 Validation de la conception (C-03) ✅

La conception (issue C-03, #4) a été livrée par la PR #38, relue et approuvée, CI verte. Ses critères d'acceptation ont été revérifiés dans le dépôt le 24/11/2026 :

| Critère | Vérification |
|---|---|
| Sources PlantUML versionnées avec leurs exports | ✅ 17 sources, chacune avec son export PNG et SVG |
| MCD, MLD et MPD cohérents | ✅ jeu, éditeur, plateforme, utilisateur, commande, ligne de commande et clé de licence présents aux trois niveaux |
| Séquence de l'achat concurrent | ✅ `seq-achat-concurrent`, avec `FOR UPDATE SKIP LOCKED` |
| ADR des choix structurants | ✅ 8 ADR au statut « Accepté » (Symfony + React, PostgreSQL + Redis, JWT, chiffrement, hébergement UE, couches, PlantUML, paiement simulé) |
| DICP et patrons de sécurité | ✅ [dicp.md](../securite/dicp.md), [security-patterns.md](../securite/security-patterns.md) |

**Décisions prises par le binôme le 24/11/2026** sur les points restés ouverts (détail : [modèle de données §7](../architecture/modele-donnees.md)) :

| # | Point | Décision |
|---|---|---|
| 1 | Langue d'un avis | Détectée automatiquement à partir du commentaire, côté serveur et sans service externe ; à défaut, langue de l'utilisateur (P-01) |
| 2 | Langue de la commande | Conservée : langue au moment de l'achat, utilisée pour l'e-mail et la preuve (F-06) |
| 3 | Purge du journal d'audit | Conservation 12 mois (RGPD, recommandation CNIL) ; purge automatisée en v2 |
| 4 | Codes de secours A2F | 10 codes régénérés en bloc à chaque activation de l'A2F, anciens supprimés (F-04) |

Aucune de ces décisions ne change la structure des tables : T-01 peut démarrer.

Les points de la définition de terminé liés au code (tests, FR / EN, validation par couche, déploiement en recette) ne s'appliquent pas à un ticket de conception.

### 6.6 NoSQL : Redis ✅ conception · ⏳ implémentation

| Usage | Clé | TTL |
|---|---|---|
| Panier | `cart:{userId}` / `cart:anon:{uuid}` | 7 j / 24 h |
| Réservation des clés | `resa:{commandeId}` | 15 min |
| Jetons de rafraîchissement / révocation | `refresh:{jti}`, `jwt:deny:{jti}` | 7 j / durée restante |
| Limitation de débit, anti-fraude | `rl:login:{ip}`, `fraud:keys24h:{userId}` | 15 min à 24 h |
| Cache catalogue | `cache:jeu:{slug}:{langue}` | 5 min |

Principe : **aucune donnée de référence dans Redis** ; sa perte coûte des paniers et des sessions, jamais une commande ni une clé.

### 6.7 Concurrence et transactions ✅ conception · ⏳ implémentation

**Cas de référence : deux clients achètent la dernière clé en même temps.**

```sql
SELECT id FROM cle_licence
 WHERE offre_id = $1 AND statut = 'DISPONIBLE'
 ORDER BY id LIMIT $2
 FOR UPDATE SKIP LOCKED;
-- moins de lignes que demandé → exception → ROLLBACK « stock insuffisant »
```

`SKIP LOCKED` fait ignorer à la seconde transaction les lignes déjà verrouillées par la première : un seul client obtient la clé, sans doublon ni interblocage. Diagramme : [séquence de l'achat concurrent](../uml/exports/seq-achat-concurrent.svg). Pour deux gestionnaires qui modifient la même offre, un **verrouillage optimiste** (colonne `version`) refuse la seconde sauvegarde avec un HTTP 409. Le PSP simulé est appelé **hors transaction** pour ne pas garder de verrous pendant un appel externe.

### 6.8 Environnement de développement ✅

`make init` lance toute la stack : PHP 8.4-FPM, Nginx, PostgreSQL 17 (bases `sidekey` et `sidekey_test`), Redis 8 avec mot de passe, Node 24 pour Vite. Les ports ne sont ouverts que sur `127.0.0.1`. Le front appelle `/api` sur sa propre origine et Vite relaie vers Nginx : pas de CORS en développement.

---

## 7. Sécurité

### 7.1 Analyse DICP ✅

| Critère | Niveau | Biens principaux | Mesures |
|---|---|---|---|
| Disponibilité | 2/4 | Catalogue, tunnel | Cache, sauvegardes, contrôles de santé, retour arrière |
| Intégrité | 3/4 | Commandes, clés, prix | Contraintes, triggers, transactions, montants calculés côté serveur |
| Confidentialité | **4/4** | **Clés**, identifiants, données personnelles | AES-256-GCM, argon2id, A2F, Voters, TLS |
| Preuve | 3/4 | Révélations, actions admin, paiements | Journal d'audit immuable, historique des commandes |

Analyse complète (biens, scénarios de menace, risque résiduel accepté) : [dicp.md](../securite/dicp.md).

### 7.2 Mesures par menace (OWASP Top 10) ✅

| Menace | Parade retenue |
|---|---|
| A01 Contrôle d'accès, IDOR | Voters Symfony (rôle et propriété), références UUID, back-office réservé aux rôles avec A2F |
| A02 Cryptographie | TLS, AES-256-GCM, argon2id, secrets hors dépôt |
| A03 Injection, XSS | Doctrine et requêtes paramétrées, échappement JSX, CSP, HTML refusé dans les avis |
| A04 Conception | Anti-fraude, réservation avec TTL, idempotence du paiement |
| A05 Configuration | En-têtes de sécurité, aucune trace technique renvoyée, conteneurs sans root |
| A06 Composants vulnérables | Dependabot, `composer audit` et `npm audit` **bloquants en CI** |
| A07 Authentification | JWT de 15 min, rotation du jeton de rafraîchissement, limitation de débit, A2F TOTP |
| A09 Journalisation | Audit sans mot de passe, clé ni carte |

Patrons de sécurité retenus (Authenticator, Authorization Enforcer, Input Validator, Encrypted Storage, Limited View, Fail Securely…) : [security-patterns.md](../securite/security-patterns.md).

### 7.3 Authentification ✅ conception · ⏳ implémentation (F-03, F-04)

- **Jeton d'accès** JWT de 15 min, gardé **en mémoire** dans la SPA (jamais dans `localStorage`, lisible en cas de XSS).
- **Jeton de rafraîchissement** opaque de 7 jours, à usage unique avec rotation, dans un cookie `HttpOnly; Secure; SameSite=Strict`, stocké et révocable dans Redis ; la réutilisation d'un jeton déjà utilisé révoque toute la famille.
- **CSRF** : les appels à l'API passent par l'en-tête `Authorization`, que le navigateur n'envoie pas tout seul ; seule la route de rafraîchissement, à cookie, reçoit une protection dédiée.
- **Mots de passe** : 12 caractères minimum, hachage argon2id, pas d'expiration forcée (recommandations ANSSI et CNIL).
- **A2F TOTP** obligatoire pour le back-office, facultative pour les clients.
- Diagramme : [séquence de connexion JWT](../uml/exports/seq-connexion-jwt.svg) · décision : [ADR-0003](../adr/0003-jwt-et-jeton-de-rafraichissement.md).

### 7.4 Protection des clés de licence ✅ conception · ⏳ implémentation (F-08)

Chiffrement **AES-256-GCM** avec IV aléatoire, clé maître dans un secret d'environnement ; unicité vérifiée par une **empreinte HMAC-SHA256** (le chiffré ne le permet pas, puisqu'il change à chaque chiffrement) ; déchiffrement uniquement pour le propriétaire d'une commande payée, et journalisé. Décision : [ADR-0004](../adr/0004-chiffrement-des-cles-de-licence.md) · séquence : [révélation d'une clé](../uml/exports/seq-revelation-cle.svg).

### 7.5 Paiement simulé ✅

Choix du binôme, justifié : la compétence évaluée porte sur la conception et l'accès aux données, pas sur l'intégration bancaire ; manipuler de vraies cartes imposerait la norme PCI DSS. Le patron **Strategy** permettra de brancher un vrai prestataire en v2 sans toucher au métier. Décision : [ADR-0008](../adr/0008-paiement-simule-et-anti-fraude.md).

---

## 8. Réalisations : extraits de code commentés

> 🟡 Les extraits ci-dessous viennent du code **déjà livré** (socle et CI). Les réalisations métier (concurrence, chiffrement, Voters, Redis) seront ajoutées au fil des features.

### 8.1 Une CI bloquante extensible sans toucher à la protection de `main`

`.github/workflows/ci.yml` (extrait) — un seul contrôle, `ci-success`, est exigé par la règle de protection ; il agrège les autres jobs. Ajouter un job ne demande donc pas de modifier la règle.

```yaml
  ci-success:
    name: ci-success
    if: always()
    needs: [api, front, docker]
    runs-on: ubuntu-latest
    steps:
      - name: Tous les jobs doivent réussir
        env:
          RESULTS: ${{ join(needs.*.result, ' ') }}
        run: |
          for r in $RESULTS; do
            if [ "$r" != "success" ]; then
              echo "::error::Un job de la CI n'a pas réussi ($r)"
              exit 1
            fi
          done
```

`if: always()` est indispensable : sans lui, un job en échec ferait **sauter** `ci-success`, et un contrôle sauté ne bloque pas la fusion.

### 8.2 Vérifier le titre de PR sans risque d'injection

`.github/workflows/pr-title.yml` (extrait) — le titre, saisi par l'utilisateur, est passé par **variable d'environnement** et jamais interpolé dans le script : un titre contenant `$(…)` ne peut pas exécuter de commande sur le runner.

```yaml
        env:
          TITLE: ${{ github.event.pull_request.title }}
        run: |
          pattern='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9._-]+\))?!?: .{3,100}$'
          if printf '%s' "$TITLE" | grep -Eq "$pattern"; then
            echo "Format valide."
          else
            echo "::error::Titre invalide. Attendu : type(portée optionnelle): description"
            exit 1
          fi
```

### 8.3 Une sonde de santé qui ne divulgue rien

`api/src/Controller/Api/HealthController.php` — utilisée par la CI, le déploiement et la supervision ; elle ne renvoie ni version ni environnement, et n'est jamais mise en cache.

```php
#[Route('/api/health', name: 'api_health', methods: ['GET'])]
public function __invoke(): JsonResponse
{
    $response = new JsonResponse(['status' => 'ok'], Response::HTTP_OK);
    $response->headers->set('Cache-Control', 'no-store');

    return $response;
}
```

### 8.4 Client HTTP du front

`front/src/api/client.ts` (extrait) — même origine (`credentials: 'same-origin'`), erreurs HTTP transformées en exceptions explicites, `fetch` injectable pour les tests.

```ts
export async function fetchHealth(fetchFn: typeof fetch = fetch): Promise<HealthStatus> {
  const response = await fetchFn(apiUrl('health'), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })
  if (!response.ok) {
    throw new Error(`API indisponible (HTTP ${response.status})`)
  }
  return (await response.json()) as HealthStatus
}
```

### 8.5 Réserver la dernière clé sans jamais la vendre deux fois (T-02)

Le cœur du scénario de concurrence est en base, dans `fn_reserver_cles` ([source](../../api/sql/functions/001_fonctions.sql)) :

```sql
SELECT array_agg(id) INTO v_cles FROM (
    SELECT id FROM cle_licence
    WHERE offre_id = p_offre AND statut = 'DISPONIBLE'
    ORDER BY importee_le, id
    LIMIT p_quantite
    FOR UPDATE SKIP LOCKED
) AS libres;

IF coalesce(cardinality(v_cles), 0) < p_quantite THEN
    RAISE EXCEPTION 'Stock insuffisant pour l''offre % : % demandée(s), % disponible(s)',
        p_offre, p_quantite, coalesce(cardinality(v_cles), 0)
        USING ERRCODE = 'SK001';
END IF;
```

- `FOR UPDATE` verrouille les clés choisies jusqu'à la fin de la transaction ; `SKIP LOCKED` fait ignorer à un second acheteur les clés déjà verrouillées au lieu de le faire attendre.
- Tout ou rien : si le stock ne suffit pas, l'exception annule la transaction et aucune clé n'est réservée.
- Avant cette sélection, la fonction refuse les paramètres absents ou hors bornes, une ligne qui ne correspond pas à l'offre, une commande expirée ou close et une double réservation : chaque cas a son code d'erreur, testé.
- **Preuve** : `api/tests/Database/60_concurrence.sh` ouvre deux sessions PostgreSQL réelles sur l'offre qui n'a plus qu'une clé. A réserve et garde sa transaction ouverte ; B échoue aussitôt avec `SK001`, sans attendre ; A obtient la clé, et une seule clé est réservée.

Une clé vendue est ensuite protégée par `trg_cle_licence_protection` : toute modification ou suppression lève `SK003`, sauf l'enregistrement de la première révélation par l'acheteur.

### 8.6 Extraits à ajouter ⏳

| Extrait prévu | Feature | Compétence |
|---|---|---|
| Service de chiffrement AES-GCM + empreinte HMAC | F-08 | C8 |
| Voter de propriété d'une commande (anti-IDOR) | F-03, F-06 | C6 |
| Panier et réservation dans Redis (TTL) | F-05 | C8 |
| Verrouillage optimiste et réponse 409 | F-09 | C8 |
| Migration Doctrine créant les rôles SGBD | T-01 | C7 |

---

## 9. Plan de tests et jeu d'essai

### 9.1 Stratégie ✅

| Niveau | Outils | Exemples |
|---|---|---|
| Unitaires | PHPUnit, Vitest + Testing Library | Calcul du total, anti-fraude, transitions, Voters, chiffrement aller-retour |
| Intégration | PHPUnit contre un vrai PostgreSQL et un vrai Redis | Réservation, triggers, TTL, limitation de débit, import CSV |
| Bout en bout | Playwright | Tunnel complet FR et EN, connexion A2F, import back-office |
| Sécurité | Tests dédiés, OWASP ZAP | Injection, IDOR, accès admin sans A2F, JWT falsifié ou révoqué, prix modifié, double paiement |
| Charge et concurrence | k6 | 50 achats simultanés de la dernière clé → 1 succès |

### 9.2 Tests existants ✅

| Partie | Tests | Résultat |
|---|---|---|
| API | 3 unitaires + 2 d'intégration sur `/api/health` (réponse, absence de cache, aucune fuite, `POST` refusé en 405) | 5 / 5 ✅ |
| Front | 4 tests du client HTTP + 2 tests de rendu | 6 / 6 ✅ |
| CI | `composer validate --strict`, `composer audit`, lint YAML et container, `npm audit`, oxlint, build typé, validation `compose.yaml` | Verte sur les PR #37, #38, #39 |

### 9.3 Jeu d'essai de la fonctionnalité la plus représentative ⏳

Fonctionnalité retenue : **achat concurrent de la dernière clé** (O2, ENF-03). Tableau à remplir quand F-06 sera livrée :

| Cas | Données en entrée | Résultat attendu | Résultat obtenu |
|---|---|---|---|
| 1 client achète la dernière clé | Offre avec 1 clé `DISPONIBLE` | Commande `EN_ATTENTE_PAIEMENT`, clé `RESERVEE` | |
| 2 clients simultanés, 1 clé | 2 requêtes parallèles | 1 succès, 1 « stock insuffisant », 0 doublon | |
| 50 clients simultanés (k6), 1 clé | 50 requêtes | 1 succès, 49 refus | |
| Réservation expirée | Pas de paiement pendant 15 min | Commande `EXPIREE`, clé `DISPONIBLE` | |
| Paiement après expiration | Paiement à 16 min | Refus, aucune clé vendue | |

---

## 10. Veille sur les vulnérabilités ⏳

> À rédiger **individuellement**. Sources prévues au CDC §16.6 : CERT-FR, bulletins de sécurité Symfony, PostgreSQL et Redis, OWASP, CNIL. Note de veille à tenir dans `docs/veille.md` (source, résumé, impact sur SideKey).

Gabarit :

| Date | Source | Vulnérabilité / sujet | Impact sur SideKey | Action |
|---|---|---|---|---|
| | | | | |

---

## 11. Situation de travail : recherche anglophone ⏳

> À rédiger **individuellement** : décrire une recherche faite pendant le projet à partir d'une source en anglais, citer un court extrait et en donner la traduction.

**Piste possible (à réécrire avec ses propres mots)** : pendant la mise en place des tests (PR #37), les tests fonctionnels Symfony échouaient avec l'erreur `You cannot create the client used in functional tests if the "framework.test" config is not set to true.` La documentation de Symfony sur les fichiers `.env`, en anglais, indique : « Real environment variables win over .env files. » (*Les vraies variables d'environnement l'emportent sur les fichiers .env.*) La variable `APP_ENV=dev`, imposée par `compose.yaml`, écrasait donc `APP_ENV=test` demandé par PHPUnit. Correction : retirer la variable de `compose.yaml` et laisser `api/.env` la définir.

---

## 12. Conclusion et perspectives 🟡

**À ce stade**, la conception est complète et documentée (besoins, maquettes desktop, architecture, données, sécurité, 8 ADR, 17 diagrammes), et le socle technique est en place (environnement Docker, CI bloquante, protection de `main`, backlog outillé). Le développement des features métier commence.

**Perspectives v2** (CDC §18 et ADR) : prestataire de paiement réel, rotation versionnée de la clé maître de chiffrement, sauvegardes externalisées, import asynchrone des clés, A2F généralisée, application mobile native consommant la même API.

> ⏳ Bilan personnel à rédiger en fin de projet : difficultés, apprentissages, ce que je ferais autrement.

---

## 13. État d'avancement de la v1

### 13.1 Fait

| Domaine | Livré | Preuve |
|---|---|---|
| Expression des besoins | CDC v1.2, 7 personas, 24 besoins, user stories MoSCoW, règles de gestion | [CDC](../cahier_des_charges_v1.2_SideKey.md) |
| Gestion de projet | Backlog de 36 éléments dans GitHub Projects, conventions Git, branches par tâche, PR relues | Issues #1–#36, PR #37–#44 |
| Maquettes | 16 écrans desktop FR dans Figma, composants FR / EN, inventaire, enchaînement ; textes FR → EN de E01 à E08, spécification mobile, grille de revue persona | [docs/maquettes](../maquettes/ecrans.md) |
| Conception | 8 ADR, 17 diagrammes PlantUML, architecture en couches, MCD / MLD / MPD commentés ; C-03 fermée, critères revérifiés (§6.5) | [docs/adr](../adr/README.md), [docs/uml](../uml/README.md), [docs/architecture](../architecture/README.md) |
| Sécurité (conception) | Analyse DICP, patrons de sécurité, mapping OWASP | [docs/securite](../securite/README.md) |
| Socle technique | Monorepo Symfony 7.4 / React 19, Docker Compose de dev, Makefile | `compose.yaml`, `Makefile` |
| CI | Jobs api / front / docker / database, `ci-success`, `pr-title`, audits bloquants | `.github/workflows/` |
| Base de données | Schéma PostgreSQL (21 tables), contraintes et index, comptes SGBD à moindre privilège, jeu d'essai, sauvegarde et restauration testées (T-01) ; fonctions et triggers défensifs, test de concurrence (T-02) | `api/sql/`, `api/tests/Database/` |
| Internationalisation | Front FR / EN (sélecteur mémorisé, `lang`, clés typées, parité testée) ; erreurs de l'API traduites selon `Accept-Language` (F-02) | `front/src/i18n/`, `api/translations/` |

### 13.2 Pas encore fait

| Domaine | Reste à faire | Ticket |
|---|---|---|
| Maquettes | Mobile (E02, E03, E04, E07), parcours EN (E01 → E08), revue persona | C-02 (#3) |
| Socle | CODEOWNERS, modèles d'issue et de PR, CONTRIBUTING, import du ruleset de `main` | T-00 (#2) |
| CI complète | PHPStan, Semgrep, tests avec PostgreSQL et Redis, couverture | T-03 (#14) |
| Déploiement | Hébergeur UE, recette automatique, production sur tag | T-04 (#6) |
| Features | Catalogue, authentification, A2F, panier, commande et concurrence, paiement, clés, back-office | F-01, F-03 à F-10 |
| Dossier | Comptes rendus quotidiens, veille (§10), recherche anglophone (§11), extraits métier (§8.6), jeu d'essai (§9.3), captures, bilan | L-03 (#34) |
| Livraison | Tests de sécurité croisés et audit RGAA, v1.0.0 et restauration, diaporama, répétition | L-01, L-02, L-04, L-05 |

### 13.3 Points de vigilance

- **Planning** : les features métier (F-01 à F-10) n'ont pas commencé, alors que le socle et la conception sont faits ; c'est la priorité absolue pour la suite.
- **Figma** : le plan Starter limite le fichier à 3 pages et l'outil d'automatisation à un quota mensuel, déjà atteint ; la fin des maquettes se fera à la main ou au renouvellement du quota.
- **Dossier individuel** : les §4.3, §10, §11 et §12 doivent être personnalisés par chaque candidat.
- **Traçabilité** : la case « Dossier de projet complété » de C-03 a été cochée avant que le dossier n'existe ; elle est devenue vraie avec la fusion de la PR #41.

---

## 14. Annexes

| Annexe | Lien |
|---|---|
| Cahier des charges v1.2 | [cahier_des_charges_v1.2_SideKey.md](../cahier_des_charges_v1.2_SideKey.md) |
| Backlog | [backlog_SideKey.md](../backlog/backlog_SideKey.md) |
| Inventaire des écrans et revue persona | [ecrans.md](../maquettes/ecrans.md) · [revue-persona.md](../maquettes/revue-persona.md) |
| Spécification mobile | [mobile.md](../maquettes/mobile.md) |
| Textes FR → EN | [traduction-en.md](../maquettes/traduction-en.md) |
| ADR | [docs/adr](../adr/README.md) |
| Diagrammes UML | [docs/uml](../uml/README.md) |
| Architecture et modèle de données | [docs/architecture](../architecture/README.md) |
| Sécurité (DICP, patrons) | [docs/securite](../securite/README.md) |
| Installation et commandes | [README du dépôt](../../README.md) |

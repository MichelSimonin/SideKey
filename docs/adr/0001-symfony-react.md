# ADR-0001 — Symfony (API) et React (SPA)

| Élément                        | Valeur                                                                                                                                                                |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                               |
| Date                           | 2026-11-23                                                                                                                                                            |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                               |
| Décision du cahier des charges | B1 (§0.2) — stack Symfony + React, justifiée par les personas                                                                                                         |
| Portée                         | Découpage de l'application en API REST et SPA, monorepo, choix des frameworks serveur et client. Ne couvre ni le stockage (ADR-0002) ni l'authentification (ADR-0003) |
| Tickets liés                   | #4 (C-03) · T-00, F-01, F-02, T-03, T-04                                                                                                                              |

## Contexte

SideKey vend des clés de licence numériques et doit tenir un tunnel d'achat court. Léa (P1) le parcourt depuis un téléphone, avec une connexion moyenne, et refuse les rechargements de page en cours d'achat (BX-01, BX-04). Marcus (P2) utilise toute l'application en anglais, interface et e-mails compris (BX-05, BX-06). Sophie (P3) navigue au clavier et au lecteur d'écran (BX-08) et attend des messages d'erreur associés aux champs (BX-10). Karim (P4) et Nathalie (P5) travaillent dans un back-office qui manipule des stocks de clés, des rôles, un journal d'audit et des commandes suspectes (BX-11 à BX-16, BX-18). Hugo (P7) reprend le projet et veut installer l'environnement en une commande, des tests à chaque modification, des déploiements réversibles et une documentation d'API à jour (BX-21 à BX-24).

Le REAC (compétence 6, §2) exige une architecture multicouche répartie sécurisée, avec des frameworks et un ORM identifiés et justifiés. Le cahier des charges retient Symfony + React (décision B1) et fixe la vue d'ensemble au §8.1 : navigateur (SPA React) → reverse proxy → API REST Symfony → PostgreSQL et Redis. La règle directrice du formateur (F6) impose que chaque outil réponde à un besoin persona identifié ou à un attendu du REAC ; la matrice du §8.3 s'y emploie.

Trois contraintes de projet pèsent sur le découpage. Le binôme travaille par feature (F2) : une feature traverse l'API, la base et le front, ce qui plaide pour un dépôt unique (§8.4). Les sprints sont journaliers (F7) et la durée totale de 2 à 3 semaines (R5), donc le temps d'apprentissage de deux écosystèmes doit rester maîtrisé. Enfin la livraison passe par une seule chaîne CI/CD (F5, §15), qui doit construire et tester les deux applications.

Le découpage doit aussi rester **explicable en dix minutes** devant le jury (livrable L3, §17) : une architecture que le binôme ne sait pas justifier ne vaut pas mieux qu'une architecture plus simple. C'est un critère de choix à part entière, et il explique plusieurs arbitrages de cette fiche.

Le critère qui tranche est double : l'attendu du REAC (compétence 6, §2) et la couverture directe d'un besoin persona du §3. Une technologie qui ne peut citer ni l'un ni l'autre est écartée, même si elle est répandue ; le délai (R5) départage ensuite deux options équivalentes sur ce plan.

## Options étudiées

| Option                                           | Avantages                                                                                                                                                               | Inconvénients                                                                                                                                         | Verdict                                                |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Symfony (API REST) + React (SPA)                 | Composants Security, Validator, RateLimiter, Translation et Messenger intégrés ; SPA sans rechargement ; composants React accessibles et testables (Vitest, Playwright) | Deux écosystèmes à maintenir ; premier affichage dépendant du bundle et de l'appel API                                                                | **Retenue** — couvre BX-01, BX-03, BX-04, BX-22, BX-24 |
| Symfony + Twig, rendu serveur                    | Un seul langage, premier affichage immédiat, moins de plomberie                                                                                                         | Rechargements de page dans le tunnel ; aucune API réutilisable par un futur client mobile ; i18n et accessibilité à porter dans les gabarits          | Écartée — contredit BX-04 et le §8.3                   |
| Laravel + React                                  | Conventions rapides à prendre en main, écosystème large                                                                                                                 | Façades et Active Record : la séparation des couches est moins lisible pour le jury ; Voters et composants Security à reconstruire                    | Écartée — attendu REAC compétence 6 (§8.3)             |
| Symfony + API Platform, client TypeScript généré | Contrats OpenAPI produits par le framework, CRUD rapide, documentation toujours alignée                                                                                 | Sur-abstraction pour un domaine aussi métier ; génération et personnalisation à maîtriser ; les couches que l'on veut démontrer deviennent implicites | Écartée — REAC C6, délai                               |
| Deux dépôts séparés (`api`, `front`)             | Historique, droits et pipelines indépendants                                                                                                                            | Une feature = deux PR et deux revues ; versions et contrats à synchroniser ; deux fois la configuration CI et les secrets                             | Écartée — décision F2 et §8.4 (monorepo)               |

## Décision

L'API est une application **Symfony 7.4 LTS** (PHP 8.3+) exposant une API REST JSON sous `/api`. L'interface est une **SPA React avec TypeScript**, construite par **Vite**. Les deux applications vivent dans le **monorepo** `sidekey/` décrit au §8.4 (`api/`, `front/`, `infra/`, `docs/`, `.github/`).

La SPA n'accède jamais directement à PostgreSQL ni à Redis : tout passe par l'API en HTTPS/JSON, avec l'en-tête `Authorization: Bearer` (§8.1, §11.4). Elle ne détient aucun secret (§8.2).

Les traductions restent dans chaque écosystème : Symfony Translation pour l'API, les e-mails et les messages de validation ; react-i18next pour l'interface (§12.4).

Une seule chaîne CI/CD construit, teste et déploie les deux images (T-03, T-04), avec une PR par feature.

## Conséquences

### Positives

- Les besoins de sécurité du back-office (BX-03, BX-15, BX-16, BX-18) s'appuient sur des composants fournis : Security, Voters, Validator, RateLimiter, sans réimplémentation.
- Le tunnel d'achat ne recharge pas la page : panier et tunnel restent dans la SPA (BX-04), ce qui sert directement Léa (P1).
- L'API REST sans état est réutilisable telle quelle par une future application mobile (§18, BX-23), et documentée par OpenAPI (§16.2).
- Le monorepo donne une PR par feature (F2) et un seul pipeline (F5), donc une seule revue pour un changement qui touche API, base et front.
- Vite produit un bundle léger et le découpage par `features/` limite le code chargé, ce qui aide la cible EcoIndex B (§12.3) et l'usage mobile.
- La séparation présentation / métier / accès aux données est visible dans l'arborescence (`Controller`, `Service`, `Repository`), ce que le jury peut vérifier directement (ADR-0006).
- L'accessibilité et l'internationalisation s'appuient sur les outils de chaque écosystème (composants ARIA, react-i18next ; Symfony Translation), ce qui couvre BX-05, BX-06 et BX-08 sans mécanisme maison.

### Négatives et risques

- **Deux écosystèmes** à maîtriser en 2 à 3 semaines : PHP/Composer et TypeScript/npm, avec deux jeux de dépendances à auditer (`composer audit`, `npm audit`, Dependabot).
- **Pas de rendu serveur** : le premier affichage du catalogue dépend du chargement du bundle puis de l'appel API. Sur un mobile en connexion moyenne (BX-01) et pour la cible « page catalogue < 1 Mo, < 200 ms » (ENF-01), la marge est plus étroite qu'avec du rendu serveur. Le référencement du catalogue n'est pas une exigence du cahier des charges, mais il est dégradé.
- **Traduction en double** : `api/translations/` et `front/src/i18n/locales/` doivent rester alignés. Le test CI du §12.4 détecte une clé manquante, pas une divergence de formulation entre les deux fichiers.
- **Cycle de vie des jetons côté client** : la SPA doit gérer le rafraîchissement silencieux et l'expiration, ce qui est une source de bugs classique (voir ADR-0003).
- **Pipeline unique** : sans découpage des jobs, chaque PR paie la durée des deux applications ; les jobs `api` et `front` sont donc séparés et regroupés par `ci-success`.
- Le monorepo impose une discipline de portée dans les commits (`feat(api):`, `feat(front):`, §16.5), sans quoi l'historique devient illisible pour Hugo (BX-24).
- **Le contrat entre les deux applications est implicite** : il repose sur les DTO et sur la documentation OpenAPI (§16.2), sans client typé généré. Un champ renommé côté API casse le front à l'exécution, pas à la compilation, et le défaut n'apparaît qu'au test de bout en bout.

### Actions induites

- T-00 : arborescence `api/` et `front/`, services Docker `front`, `nginx`, `php`, `database`, `redis`, cible `make init`.
- F-02 : i18n des deux côtés, sélecteur de langue, test CI des clés manquantes (§12.4).
- T-03 : jobs `api` et `front` distincts, dépendances bloquantes, `ci-success` agrégateur.
- T-04 : construction et publication des deux images vers GHCR, déploiement par `docker compose pull`.
- Documentation : diagramme `archi-couches` et `deploiement` (voir [conventions UML](../uml/README.md)), structure du dépôt reprise dans le [README racine](../../README.md).
- F-01 : poser les premiers DTO de sortie du catalogue, qui serviront de contrat stable entre l'API et la SPA.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.2 (B1), §2 (compétence 6), §8.1 à §8.5, §12.4, §15.
- [Index des ADR](README.md) — ADR-0002 (données), ADR-0003 (authentification), ADR-0006 (couches), ADR-0007 (modélisation).
- [Conventions des diagrammes UML](../uml/README.md) — `archi-couches`, `deploiement`.
- [README du dépôt](../../README.md) — structure du monorepo et environnement Docker.

# Diagrammes UML et modèles de données — SideKey

Tous les diagrammes du projet sont écrits en **PlantUML** (décision B5 du cahier des
charges, [ADR-0007](../adr/0007-modelisation-plantuml.md)) : les sources sont versionnées
avec le code, comparables en revue de PR, et les exports sont régénérés à la demande.

```text
docs/uml/
├── src/        17 sources PlantUML (.puml) — le contenu de référence
└── exports/    exports .png (dossier de projet, diaporama) et .svg (navigation)
```

Chaque source a **deux exports** : `nom.png` et `nom.svg`, versionnés dans la même PR.

## Régénérer les exports

```bash
make uml            # tous les diagrammes : PNG + SVG dans docs/uml/exports/
```

`make uml` appelle `scripts/export-uml.sh`, qui utilise **PlantUML s'il est installé**,
sinon l'**image Docker `plantuml/plantuml:1.2026.10`** (Graphviz inclus). Aucune
installation locale n'est donc nécessaire sur une machine qui a Docker.

Équivalents directs :

```bash
plantuml -tpng -o docs/uml/exports docs/uml/src
plantuml -tsvg -o docs/uml/exports docs/uml/src
# ou
docker run --rm -v "$PWD/docs/uml:/data" plantuml/plantuml:1.2026.10 -tpng -o /data/exports /data/src
```

> Un export ne se modifie **jamais à la main** : on modifie le `.puml`, on régénère, et
> les deux fichiers sont versionnés dans la même PR. Une source qui ne se compile pas
> fait échouer l'export : le rendu est la première vérification de la revue.

## Conventions

| Règle | Valeur |
|---|---|
| Encodage | UTF-8 **sans BOM** |
| Langue des libellés | français (le dossier de projet est francophone) |
| Nommage des sources | `type-sujet.puml` : `uc-*` (cas d'utilisation), `classes-*`, `seq-*`, `etats-*`, `mcd`, `mld`, `mpd`, `archi-*`, `deploiement` |
| En-tête | Bloc de commentaires : objet, source (section du cahier des charges), fichiers exportés |
| Style | `skinparam shadowing false`, police Segoe UI, traits `#455A64` ; pas de thème exotique |
| Palette | fonds `#FFFFFF` / `#F7F9FB`, traits `#37474F`, bordures `#90A4AE` |
| Notes | encadrés `note` pour les règles de gestion citées, jamais de secret ni de donnée réelle |

Les sources ne contiennent **aucune donnée réelle** : ni clé de licence, ni secret, ni
donnée personnelle. Les identifiants des exemples sont fictifs (`K1`, `4242 4242 4242 4242`).

## Index des diagrammes

### Besoins et conception fonctionnelle

| Fichier | Contenu | Référence |
|---|---|---|
| `uc-global` | Cas d'utilisation : acteurs, frontière du système, grandes fonctions, acteurs secondaires | CDC §4.1, §5 |
| `uc-front-office` | Parcours client détaillé (catalogue, panier, compte, commande, clés, avis) | CDC §5.1 à §5.5 |
| `uc-back-office` | Back-office : catalogue, offres, import, commandes, modération, rôles, audit | CDC §5.6, §11.4 |

### Données

| Fichier | Contenu | Référence |
|---|---|---|
| `mcd` | Modèle conceptuel : 20 entités, 26 associations, cardinalités (min,max) | CDC §9.2 |
| `mld` | Modèle logique relationnel : 21 tables, clés primaires et étrangères | CDC §9.1, §9.2 |
| `mpd` | Modèle physique PostgreSQL 17 : types, défauts, contraintes, index partiels, triggers | CDC §9.1 à §9.6 |

### Comportement

| Fichier | Contenu | Référence |
|---|---|---|
| `classes-catalogue` | Classes du domaine : référentiels, catalogue, clés de licence, chiffrement | CDC §6.1, §8.3 |
| `classes-commande` | Classes du domaine : comptes, commande, paiement, avis, audit | CDC §5.3 à §5.5, §6.2 |
| `etats-commande` | Cycle de vie d'une commande, événements, gardes, triggers | CDC §6.4 |
| `etats-cle-licence` | `DISPONIBLE` → `RESERVEE` → `VENDUE`, libération, immuabilité | CDC §6.1 (RG-04) |
| `seq-achat-concurrent` | **Achat concurrent de la dernière clé** : `FOR UPDATE SKIP LOCKED`, 1 succès / N refus | CDC §10.1, ENF-03 |
| `seq-paiement` | Paiement simulé, idempotence, refus, anti-fraude, validation atomique | CDC §6.3, §10.3, §11.6 |
| `seq-connexion-jwt` | Connexion, A2F, jetons, rotation, détection de réutilisation, déconnexion | CDC §11.4 |
| `seq-revelation-cle` | Révélation d'une clé par son propriétaire, déchiffrement, audit, vue masquée admin | CDC §9.4, BX-13 |
| `seq-import-cles` | Import CSV : validation, empreinte, doublons, chiffrement, rapport | CDC §5.6 (US-52) |

### Architecture et déploiement

| Fichier | Contenu | Référence |
|---|---|---|
| `archi-couches` | Vue composants : SPA, présentation, métier, accès aux données, infrastructure | CDC §8.1, §8.2 |
| `deploiement` | Poste de dev, GitHub Actions, recette et production sur VPS UE, retour arrière | CDC §15 |

## Documents liés

- [`docs/architecture/`](../architecture/) — architecture en couches et modèle de données commenté
- [`docs/adr/`](../adr/) — décisions d'architecture (8 ADR)
- [`docs/securite/`](../securite/) — analyse DICP et patrons de sécurité retenus

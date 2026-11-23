# ADR-0007 — PlantUML pour tous les diagrammes

| Élément                        | Valeur                                                                                                                                                                                                              |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                                             |
| Date                           | 2026-11-23                                                                                                                                                                                                          |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                                             |
| Décision du cahier des charges | B5 (§0.2) — modélisation en PlantUML (et maquettes en Figma)                                                                                                                                                        |
| Portée                         | Outil et chaîne de production des diagrammes UML et des modèles de données : sources, exports, conventions de nommage, intégration au dépôt. Ne couvre pas les maquettes d'écran, qui restent dans Figma (B5, C-02) |
| Tickets liés                   | #4 (C-03) · C-02, T-01, L-03, L-04                                                                                                                                                                                  |

## Contexte

Le dossier de projet (livrable L1, §17) doit contenir des diagrammes de cas d'utilisation, de classes, de séquence (dont l'achat concurrent de la dernière clé), d'états, le MCD/MLD/MPD et un diagramme de déploiement. La compétence 5 du REAC attend un dossier de conception structuré et un enchaînement formalisé par un schéma ; la compétence 6 attend une architecture multicouche explicitée ; la compétence 7 attend un MCD en 3FN.

Hugo (P7) veut un historique lisible et une documentation à jour (BX-24). Or des diagrammes vivent mal hors du dépôt : une image exportée sur un poste devient vite une version que personne ne sait plus régénérer, et la revue de PR ne peut pas la comparer. Le cahier des charges retient donc PlantUML (B5) et le justifie au §8.3 : « Diagrammes en texte, versionnés avec le code, relus en PR », avec une alternative écartée explicite — « Outils graphiques : fichiers binaires non comparables ». Le §16.2 précise le périmètre : cas d'utilisation, classes, séquences, états, MCD/ERD, déploiement.

Une nuance doit être notée : les diagrammes du cahier des charges lui-même sont en Mermaid, uniquement parce que GitHub les affiche directement (§4.2). Ce n'est pas un choix d'outil de modélisation, et cela ne remet pas en cause B5. Les conventions du dossier sont déjà écrites : sources dans `docs/uml/src/`, exports dans `docs/uml/exports/`, régénération par `make uml`, et « un export ne se modifie jamais à la main ».

Reste une contrainte de planning : C-03 est estimé à une journée pour l'ensemble de la modélisation et des ADR. L'outil doit donc s'apprendre vite et produire plusieurs diagrammes par jour, ce qui pèse dans la comparaison autant que la qualité du rendu.

Le critère qui tranche est la **revue** : un diagramme doit pouvoir être comparé dans une PR et régénéré par n'importe qui, sans outil propriétaire. Le second critère est la couverture des diagrammes exigés au §16.2 — un outil qui ne sait pas dessiner un cas d'utilisation ou une séquence ne rend pas service, quel que soit son rendu.

## Options étudiées

| Option                                          | Avantages                                                                                                                            | Inconvénients                                                                                                                                    | Verdict                                       |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------- |
| PlantUML                                        | Texte donc versionné et comparable en revue ; couvre tous les diagrammes exigés ; rendu reproductible par Docker ; export PNG et SVG | Syntaxe à apprendre ; mise en page automatique ; rendu moins soigné qu'un outil graphique                                                        | **Retenue** — couvre B5, BX-24, REAC C5/C6/C7 |
| Mermaid                                         | Rendu direct dans GitHub et dans les commentaires de PR                                                                              | Couverture plus faible des diagrammes exigés (cas d'utilisation, classes détaillées, séquences) ; thème et mise en page peu adaptés à un dossier | Écartée — B5, §16.2                           |
| Outil graphique (draw.io, Visio, Lucidchart)    | Rendu soigné, prise en main immédiate                                                                                                | Fichiers XML ou binaires illisibles en revue ; exports non reproductibles ; dépendance à un compte et à une version d'outil                      | Écartée — §8.3, revue de PR                   |
| Diagrammes générés par introspection de la base | Toujours synchronisés avec le schéma physique                                                                                        | Ne produit ni cas d'utilisation, ni séquence, ni MCD conceptuel en 3FN : seulement une photo du MPD                                              | Écartée — REAC C7 (MCD), §9.2                 |
| PlantUML via un service en ligne                | Rien à installer, rendu immédiat                                                                                                     | Les sources du projet transitent par un tiers, hors du dépôt ; indisponible hors ligne et en CI ; inadapté à un dossier de sécurité              | Écartée — sécurité et reproductibilité        |
| Diagrammes dessinés puis capturés en image      | Aucun outil à apprendre                                                                                                              | Aucun versionnement utile, aucune revue possible, incohérence garantie avec le code                                                              | Écartée — BX-24                               |

## Décision

Tous les diagrammes UML et les modèles de données du projet sont écrits en **PlantUML**. Les sources `.puml` vivent dans `docs/uml/src/` et constituent le contenu de référence ; les exports **PNG** (dossier de projet, diaporama) et **SVG** (consultation) vivent dans `docs/uml/exports/` et sont **régénérés par `make uml`**, sans installation locale, via l'image Docker `plantuml/plantuml`. Le seul prérequis reste Docker, ce qui prolonge l'installation en une commande attendue par Hugo (BX-21).

Un export n'est jamais modifié à la main : on modifie le `.puml`, on régénère, et les deux fichiers sont versionnés dans la **même PR**. Le nommage suit `type-sujet.puml` (`uc-*`, `classes-*`, `seq-*`, `etats-*`, `mcd`, `mld`, `mpd`, `archi-*`, `deploiement`). Les libellés sont en français, les fichiers en UTF-8 sans BOM, le style et la palette sont ceux décrits dans les [conventions UML](../uml/README.md), et les sources ne contiennent **aucune donnée réelle** : ni clé de licence, ni secret, ni donnée personnelle.

Les diagrammes Mermaid du cahier des charges restent en place comme illustration dans ce document ; ils ne sont pas repris comme sources de modélisation.

## Conséquences

### Positives

- Les diagrammes sont revus comme du code : une PR qui change une règle de gestion et son diagramme se lit dans le même diff (BX-24).
- La régénération est reproductible : la même source produit le même rendu sur les deux postes et dans l'image Docker, donc plus de « dernière version » introuvable avant la soutenance.
- Un seul outil couvre tous les besoins du §16.2, du cas d'utilisation au déploiement, ce qui évite d'apprendre trois syntaxes en trois semaines.
- Les exports PNG se glissent directement dans le dossier de projet et dans le diaporama, sans retouche : ce qui est montré au jury est exactement ce qui est versionné.
- Le dossier de projet (L-03) et le diaporama (L-04) réutilisent directement les exports PNG, sans retouche manuelle.
- Les sources étant du texte, elles passent la revue croisée et l'analyse de forme sans outil supplémentaire ; un `.puml` qui ne compile pas fait échouer l'export, ce qui sert de première vérification.

### Négatives et risques

- **Temps d'apprentissage** : la syntaxe PlantUML, en particulier les diagrammes de séquence et les cardinalités du MCD, se paie au J2 sur un ticket C-03 estimé à une journée.
- **Rendu moins soigné** qu'un outil graphique : la mise en page est automatique (Graphviz). Pour le diaporama, certaines figures demanderont des essais de disposition, voire une simplification.
- **Exports binaires** : les PNG alourdissent le dépôt et produisent des différences illisibles à chaque régénération. Les SVG limitent le problème mais restent volumineux.
- **Désynchronisation possible** : si l'on oublie de régénérer après une modification du modèle, le dossier de projet montre un schéma faux. La vérification `git status docs/uml/exports` avant revue est la seule parade, et elle repose sur la discipline.
- **Dépendance à Docker ou à Java** : `make uml` suppose Docker disponible. C'est déjà le cas pour le reste du projet, mais un poste sans Docker ne peut pas produire de diagramme.
- **Format non universel** : un lecteur du dossier sans PlantUML ne voit que les exports. C'est justement pourquoi les deux formats sont versionnés, au prix d'un dépôt plus lourd.
- **Deux fichiers pour un diagramme** : source et export doivent rester cohérents. Une PR qui ne versionne que le `.puml` rend le dossier faux ; une PR qui ne versionne que le PNG rend le diagramme immodifiable.

### Actions induites

- C-03 : produire `uc-global`, `classes-domaine`, `mcd`, `mld`, `mpd`, `seq-achat-concurrent` et `archi-couches`, avec leurs exports.
- C-02 : conserver Figma pour les maquettes ; ne pas mélanger maquettes et diagrammes UML dans `docs/uml/`.
- T-01 : aligner `mpd` sur les migrations réellement appliquées, et régénérer dans la même PR.
- `make uml` et conventions documentées dans `docs/uml/README.md` ; vérification `git status docs/uml/exports` avant chaque revue.
- L-03 et L-04 : réutiliser les exports PNG sans les retoucher ; citer la source `.puml` dans la légende.
- Aucun diagramme d'architecture n'est produit hors de ce circuit : un schéma collé dans un document ou un ticket n'a pas valeur de référence.
- C-03 : vérifier que chaque diagramme annoncé dans l'index des [conventions UML](../uml/README.md) existe en source **et** en export avant de clore le ticket.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §2 (compétences 5 à 7), §4.2, §8.3, §9.2, §16.2, §17 (L1).
- [Conventions des diagrammes UML](../uml/README.md) — arborescence, régénération par `make uml`, nommage, palette, index des diagrammes.
- [Index des ADR](README.md) — ADR-0006 (architecture en couches), ADR-0002 (modèle de données).

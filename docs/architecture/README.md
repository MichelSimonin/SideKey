# Architecture — SideKey

| Document | Contenu |
|---|---|
| [Architecture en couches](architecture-couches.md) | Responsabilités et règles de dépendance des couches (SPA, présentation, métier, données), cycle de vie d'une requête, contrats d'erreur, correspondance REAC |
| [Modèle de données](modele-donnees.md) | Commentaire des MCD, MLD et MPD : transformations, contraintes, types PostgreSQL, normalisation et écarts assumés, cohérence avec le code |

Diagrammes correspondants :

- [`archi-couches.puml`](../uml/src/archi-couches.puml) — vue composants
- [`deploiement.puml`](../uml/src/deploiement.puml) — environnements et CI/CD
- [`mcd.puml`](../uml/src/mcd.puml), [`mld.puml`](../uml/src/mld.puml), [`mpd.puml`](../uml/src/mpd.puml) — les trois niveaux du modèle

Décisions associées : [ADR-0006 (architecture en couches)](../adr/0006-architecture-en-couches.md),
[ADR-0001 (Symfony + React)](../adr/0001-symfony-react.md),
[ADR-0002 (PostgreSQL + Redis)](../adr/0002-postgresql-redis.md).

# SideKey

Boutique en ligne de clés de jeux vidéo : projet en binôme du titre CDA (activité 2, application sécurisée organisée en couches).

- **API** : Symfony 7.4 LTS (`api/`)
- **Front** : React + TypeScript avec Vite (`front/`)
- **Données** : PostgreSQL (relationnel) et Redis (NoSQL clé/valeur)

## Structure

```text
SideKey/
├── api/                        Symfony — API REST en couches
│   ├── config/jwt/             clés JWT générées en local (non versionnées)
│   ├── migrations/             migrations Doctrine
│   ├── sql/
│   │   ├── roles/              comptes PostgreSQL sk_owner, sk_app, sk_reporting, sk_backup (GRANT/REVOKE)
│   │   ├── functions/          procédures stockées et fonctions défensives
│   │   ├── triggers/           triggers (historique des statuts, garde-fous)
│   │   ├── seed/               jeu d'essai de la base de test
│   │   └── backup/             scripts pg_dump / restauration
│   ├── src/
│   │   ├── Controller/Api/     couche présentation : routes publiques et client
│   │   ├── Controller/Admin/   couche présentation : back-office
│   │   ├── Dto/Request|Response/  contrats d'entrée validés / de sortie
│   │   ├── Service/            couche métier : règles de gestion, transactions
│   │   ├── Repository/         couche d'accès aux données SQL (Doctrine, requêtes paramétrées)
│   │   ├── Entity/  Enum/      modèle de domaine
│   │   ├── Infrastructure/
│   │   │   ├── Redis/          accès NoSQL : panier, réservations, cache, révocation des jetons
│   │   │   ├── Crypto/         chiffrement AES-GCM et empreinte HMAC des clés
│   │   │   └── Payment/        faux prestataire de paiement
│   │   ├── Security/Voter/     autorisations (RBAC)
│   │   ├── EventSubscriber/    gestion des erreurs, en-têtes de sécurité
│   │   └── Exception/          exceptions métier
│   ├── tests/Unit|Integration|Security/
│   └── translations/           messages FR/EN de l'API
├── front/                      React + TypeScript (Vite)
│   ├── src/
│   │   ├── api/                client HTTP (jeton d'accès en mémoire)
│   │   ├── app/                providers, configuration globale
│   │   ├── components/ui|layout/  composants réutilisables
│   │   ├── features/           une feature = un dossier (auth, catalogue, panier, commande, compte, admin)
│   │   ├── i18n/locales/fr|en/ traductions
│   │   ├── pages/  routes/     écrans et routage
│   │   ├── hooks/  types/  utils/  styles/
│   └── tests/unit|e2e/
├── docker/                     images et configuration (php, nginx, postgres)
├── docs/                       cahier des charges, backlog, UML, ADR
└── scripts/                    outillage (import du backlog dans GitHub)
```

## Environnement de développement (Docker)

Seul Docker est nécessaire : PHP, Composer et Node tournent dans les conteneurs.

```bash
make init      # première fois : copie les .env.example, construit, démarre, composer install
make up        # les fois suivantes
make help      # toutes les commandes
```

| Service | Image | Accès depuis le Mac |
|---|---|---|
| `front` | node:24-alpine (Vite, rechargement à chaud) | http://localhost:5173 |
| `nginx` | nginx:1.28-alpine | http://localhost:8000 (API) |
| `php` | PHP 8.4-FPM + pdo_pgsql, redis, intl, sodium, apcu, xdebug | via Nginx |
| `database` | postgres:17-alpine — bases `sidekey` et `sidekey_test` | 127.0.0.1:5432 |
| `redis` | redis:8-alpine, mot de passe, persistance AOF | 127.0.0.1:6379 |
| `worker` | même image que `php` : tâches planifiées (`messenger:consume scheduler_default`), dont la libération des réservations expirées chaque minute | — |

- Le front appelle `/api` sur sa propre origine et Vite relaie vers Nginx : pas de CORS en dev, et le cookie du refresh token peut rester `SameSite=Strict`.
- Les ports ne sont ouverts que sur `127.0.0.1`, pas sur le réseau local.
- Sous Docker, `DATABASE_URL` et `REDIS_URL` viennent du `.env` racine via `compose.yaml` et l'emportent sur `api/.env`.
- Xdebug est installé mais coupé : `XDEBUG_MODE=debug` dans `.env`, puis `make restart`.
- `make clean` supprime les volumes (bases et Redis) : le script `docker/postgres/init/` ne rejoue qu'à la création du volume.

Base de dev : `make db-migrate` applique le schéma (tables, fonctions, triggers, droits) sur la base `sidekey` vide ; `make db-seed` recrée la base `sidekey_test` avec le jeu d'essai, utilisée par PHPUnit.

Exemples : `make console c="debug:router"`, `make composer c="require symfony/orm-pack"`, `make psql`, `make redis-cli`, `make logs s=php`.

## Variables d'environnement

Aucun fichier `.env` n'est versionné : seuls les `.env.example` le sont, sans aucun secret.

## Tests et CI

```bash
make test        # PHPUnit (api/) + Vitest et oxlint (front/)
```

- Tests API : `api/tests/Unit` (sans Symfony), `api/tests/Integration` (noyau Symfony, PostgreSQL `sidekey_test` et Redis : lancer `make db-seed` une fois avant), `api/tests/Security`.
- Tests front : à côté du code, `*.test.ts(x)`, avec Vitest + Testing Library (jsdom).
- CI GitHub Actions (`.github/workflows/`) à chaque PR et push sur `main` :
  - `ci.yml` : jobs `api` (composer validate/audit, lint config, PHPUnit), `front` (npm audit, lint, Vitest, build typé), `docker` (validation de `compose.yaml`), et `ci-success` qui les regroupe ;
  - `pr-title.yml` : le titre de PR doit suivre Conventional Commits (il devient le message du squash merge).
- Seuls `ci-success` et `pr-title` sont exigés par la protection de `main`.

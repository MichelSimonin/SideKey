# Raccourcis pour l'environnement Docker de développement
DC = docker compose
PHP = $(DC) exec php

.DEFAULT_GOAL := help
.PHONY: help init up down restart build logs ps sh composer console front-sh psql redis-cli db-migrate db-reset db-test db-seed db-backup db-restore test test-api test-front uml clean

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

init: ## Première installation : copie les .env.example, construit et démarre
	@test -f .env || cp .env.example .env
	@test -f api/.env || cp api/.env.example api/.env
	@test -f front/.env.local || cp front/.env.example front/.env.local
	$(DC) build
	$(DC) up -d
	$(PHP) composer install
	@echo "API : http://localhost:8000   Front : http://localhost:5173"

up: ## Démarre la stack
	$(DC) up -d

down: ## Arrête la stack (les données sont gardées)
	$(DC) down

restart: down up ## Redémarre la stack

build: ## Reconstruit l'image PHP
	$(DC) build --pull php

logs: ## Suit les journaux (make logs s=php pour un seul service)
	$(DC) logs -f $(s)

ps: ## État des conteneurs
	$(DC) ps

sh: ## Shell dans le conteneur PHP
	$(PHP) sh

composer: ## Composer dans le conteneur (make composer c="require symfony/orm-pack")
	$(PHP) composer $(c)

console: ## Console Symfony (make console c="debug:router")
	$(PHP) php bin/console $(c)

front-sh: ## Shell dans le conteneur Node
	$(DC) exec front sh

psql: ## Client PostgreSQL sur la base de dev
	$(DC) exec database sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

redis-cli: ## Client Redis
	$(DC) exec redis sh -c 'redis-cli -a "$$REDIS_PASSWORD" --no-auth-warning'

clean: ## Arrête et SUPPRIME les données (bases, Redis, node_modules du conteneur)
	$(DC) down -v

db-migrate: ## Applique le schéma SQL sur la base de dev (base vide)
	$(PHP) sh sql/bin/db-apply.sh

db-reset: ## Vide la base de dev puis réapplique le schéma (SUPPRIME les données)
	$(DC) exec database sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -v ON_ERROR_STOP=1 -c "DROP SCHEMA public CASCADE" -c "CREATE SCHEMA public"'
	$(MAKE) db-migrate

db-test: ## Tests de la base : migrations sur base vide + tests SQL
	$(PHP) sh tests/Database/run.sh

db-seed: ## Recrée la base sidekey_test (schéma + jeu d'essai). SUPPRIME ses données
	$(PHP) env PGDATABASE=sidekey_test sh sql/bin/db-seed.sh --reset

db-backup: ## Sauvegarde la base de dev (pg_dump -Fc) dans api/sauvegardes/
	$(PHP) env PGUSER=sk_backup PGPASSWORD=$${SK_BACKUP_PASSWORD:-change_me_dev} sh sql/backup/backup.sh

db-restore: ## Restaure une sauvegarde : make db-restore FILE=sauvegardes/x.dump DB=sidekey_test (VIDE la base cible)
	$(PHP) sh sql/backup/restore.sh $(FILE) $(DB)

test: test-api test-front ## Lance tous les tests (API + front)

test-api: ## Tests PHPUnit de l'API
	$(PHP) php bin/phpunit --testdox

test-front: ## Tests Vitest et lint du front
	$(DC) exec front npm test
	$(DC) exec front npm run lint

uml: ## Exporte les diagrammes PlantUML (docs/uml/exports, PNG + SVG)
	@bash scripts/export-uml.sh

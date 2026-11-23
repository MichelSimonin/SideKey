#!/bin/sh
# Exécuté une seule fois, à la création du volume database_data.
# Crée la base de test (jeu d'essai, tests d'intégration) à côté de la base de dev.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE DATABASE sidekey_test OWNER "$POSTGRES_USER";
REVOKE ALL ON DATABASE "$POSTGRES_DB" FROM PUBLIC;
REVOKE ALL ON DATABASE sidekey_test FROM PUBLIC;
SQL

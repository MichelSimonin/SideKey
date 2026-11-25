#!/bin/sh
# Applique le schéma SideKey sur une base VIDE, dans l'ordre :
#   1. roles/01_roles.sql        comptes PostgreSQL (si présent)
#   2. schema/NNN_*.sql          tables, contraintes, vues (hors *.down.sql), exécutés avec le rôle sk_owner
#   3. functions/NNN_*.sql       fonctions métier fn_* (T-02), avec le rôle sk_owner
#   4. triggers/NNN_*.sql        triggers défensifs trg_* (T-02), avec le rôle sk_owner
#   5. roles/02_privileges.sql   GRANT / REVOKE (si présent)
#
# Connexion : variables PG* standard (PGHOST, PGUSER, PGPASSWORD, PGDATABASE). Le compte doit pouvoir
# créer des rôles (super-utilisateur de la base de dev / de CI).
# Mots de passe des comptes applicatifs : SK_OWNER_PASSWORD, SK_APP_PASSWORD, SK_REPORTING_PASSWORD,
# SK_BACKUP_PASSWORD. Les valeurs par défaut ne servent qu'en développement et en CI.
#
# En recette et en production, le schéma est appliqué par `doctrine:migrations:migrate` (compte sk_owner) :
# les migrations Doctrine lisent les mêmes fichiers SQL (api/migrations/Version*.php).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
SQL_DIR=$(dirname "$HERE")
DEV_PASSWORD="${SK_DEV_PASSWORD:-change_me_dev}"

psql_run() {
    psql -X -q -v ON_ERROR_STOP=1 \
        -v owner_pw="${SK_OWNER_PASSWORD:-$DEV_PASSWORD}" \
        -v app_pw="${SK_APP_PASSWORD:-$DEV_PASSWORD}" \
        -v reporting_pw="${SK_REPORTING_PASSWORD:-$DEV_PASSWORD}" \
        -v backup_pw="${SK_BACKUP_PASSWORD:-$DEV_PASSWORD}" \
        "$@"
}

if [ -f "$SQL_DIR/roles/01_roles.sql" ]; then
    echo "-> rôles PostgreSQL"
    psql_run -f "$SQL_DIR/roles/01_roles.sql"
    SCHEMA_OPTIONS="-c role=sk_owner"
else
    SCHEMA_OPTIONS=""
fi

for f in "$SQL_DIR"/schema/[0-9]*.sql; do
    case "$f" in *.down.sql) continue ;; esac
    echo "-> $(basename "$f")"
    ( export PGOPTIONS="$SCHEMA_OPTIONS"; psql_run --single-transaction -f "$f" )
done

for dir in functions triggers; do
    for f in "$SQL_DIR"/$dir/[0-9]*.sql; do
        [ -e "$f" ] || continue
        case "$f" in *.down.sql) continue ;; esac
        echo "-> $dir/$(basename "$f")"
        ( export PGOPTIONS="$SCHEMA_OPTIONS"; psql_run --single-transaction -f "$f" )
    done
done

if [ -f "$SQL_DIR/roles/02_privileges.sql" ]; then
    echo "-> droits (GRANT / REVOKE)"
    ( export PGOPTIONS="$SCHEMA_OPTIONS"; psql_run -f "$SQL_DIR/roles/02_privileges.sql" )
fi
echo "Schéma appliqué sur $(psql -X -Atc 'SELECT current_database()')."

#!/bin/sh
# Tests de la base de données SideKey : migrations sur base vide, puis tests SQL.
#
# Déroulé :
#   1. recrée une base jetable (SK_TEST_DB, par défaut sidekey_dbtest)
#   2. applique le schéma (sql/bin/db-apply.sh) : c'est le test « migrations sans erreur sur base vide »
#   3. lance les tests NN_*_test.sql avec NN de 01 à 39 (base vide), puis charge le jeu d'essai
#      (sql/seed/*.sql), puis lance les tests NN de 40 à 99 (base chargée)
#   4. rejoue les fichiers *.down.sql en sens inverse : le schéma doit disparaître entièrement
#
# Connexion : variables PG* standard, compte super-utilisateur (création de base et de rôles).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
SQL_DIR=$(cd "$HERE/../../sql" && pwd)
DB="${SK_TEST_DB:-sidekey_dbtest}"

echo "== Base jetable $DB"
psql -X -q -v ON_ERROR_STOP=1 -d postgres \
    -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" -c "CREATE DATABASE $DB"
export PGDATABASE="$DB"

echo "== Migrations sur base vide"
sh "$SQL_DIR/bin/db-apply.sh"

run_tests() {
    for f in "$@"; do
        [ -e "$f" ] || continue
        echo "== $(basename "$f")"
        psql -X -q -v ON_ERROR_STOP=1 -f "$f"
    done
}

if [ -f "$SQL_DIR/roles/01_roles.sql" ]; then
    echo "== Connexion réelle des comptes applicatifs (mot de passe, droits)"
    DEV_PW="${SK_DEV_PASSWORD:-change_me_dev}"
    connecte() { PGUSER="$1" PGPASSWORD="$2" psql -X -q -At -c "$3" 2>/dev/null; }
    for compte in "sk_owner:${SK_OWNER_PASSWORD:-$DEV_PW}" "sk_app:${SK_APP_PASSWORD:-$DEV_PW}" \
                  "sk_reporting:${SK_REPORTING_PASSWORD:-$DEV_PW}" "sk_backup:${SK_BACKUP_PASSWORD:-$DEV_PW}"; do
        nom="${compte%%:*}"
        if [ "$(connecte "$nom" "${compte#*:}" 'SELECT current_user')" != "$nom" ]; then
            echo "ECHEC : connexion impossible avec $nom" >&2
            exit 1
        fi
        echo "ok - connexion de $nom"
    done
    if connecte sk_app "${SK_APP_PASSWORD:-$DEV_PW}" 'DELETE FROM cle_licence' >/dev/null; then
        echo "ECHEC : sk_app a pu supprimer des clés de licence" >&2
        exit 1
    fi
    echo "ok - sk_app ne peut pas supprimer de clé de licence (connexion réelle)"
fi

run_tests "$HERE"/0[1-9]_*_test.sql "$HERE"/[1-3][0-9]_*_test.sql

if ls "$SQL_DIR"/seed/*.sql >/dev/null 2>&1; then
    echo "== Jeu d'essai"
    for f in "$SQL_DIR"/seed/[0-9]*.sql; do
        echo "-> $(basename "$f")"
        psql -X -q -v ON_ERROR_STOP=1 --single-transaction -f "$f"
    done
fi

run_tests "$HERE"/[4-9][0-9]_*_test.sql

echo "== Annulation des migrations (fichiers .down.sql)"
for f in $(ls "$SQL_DIR"/schema/*.down.sql | sort -r); do
    echo "-> $(basename "$f")"
    psql -X -q -v ON_ERROR_STOP=1 --single-transaction -f "$f"
done
remaining=$(psql -X -Atc "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")
if [ "$remaining" != "0" ]; then
    echo "ECHEC : $remaining table(s) restent après l'annulation des migrations" >&2
    exit 1
fi
echo "ok - schéma entièrement annulé"

unset PGDATABASE
psql -X -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)"
echo "== Tous les tests de la base de données sont passés"

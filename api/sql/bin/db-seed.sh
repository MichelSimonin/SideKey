#!/bin/sh
# Charge le jeu d'essai (sql/seed/*.sql) dans la base désignée par PGDATABASE (par défaut sidekey_test).
# Option --reset : recrée d'abord la base (SUPPRIME toutes ses données), applique le schéma, puis charge le jeu d'essai.
# Refuse de s'exécuter sur une base dont le nom ne contient pas « test » ou « dev » (protection contre la production).
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
SQL_DIR=$(dirname "$HERE")
export PGDATABASE="${PGDATABASE:-sidekey_test}"

case "$PGDATABASE" in
    *test*|*dev*) ;;
    *) echo "Refus : le jeu d'essai ne se charge que dans une base de test ou de développement (PGDATABASE=$PGDATABASE)." >&2; exit 1 ;;
esac

if [ "${1:-}" = "--reset" ]; then
    DB="$PGDATABASE"
    unset PGDATABASE
    echo "-> recréation de la base $DB"
    psql -X -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS \"$DB\" WITH (FORCE)" -c "CREATE DATABASE \"$DB\""
    export PGDATABASE="$DB"
    sh "$HERE/db-apply.sh"
fi

for f in "$SQL_DIR"/seed/[0-9]*.sql; do
    echo "-> $(basename "$f")"
    psql -X -q -v ON_ERROR_STOP=1 --single-transaction -f "$f"
done
echo "Jeu d'essai chargé dans $PGDATABASE."

#!/bin/sh
# Test de sauvegarde / restauration : sauvegarde la base chargée (jeu d'essai) avec le compte sk_backup,
# la restaure dans une base vierge, puis compare le nombre de lignes de CHAQUE table et les droits.
# Appelé par run.sh (base jetable déjà chargée, PGDATABASE définie).
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
SQL_DIR=$(dirname "$(dirname "$HERE")")/sql
SRC="$PGDATABASE"
DST="${SRC}_restore"
OUT=$(mktemp -d)
trap 'rm -rf "$OUT"; ( unset PGDATABASE; psql -X -q -d postgres -c "DROP DATABASE IF EXISTS \"$DST\" WITH (FORCE)" ) >/dev/null 2>&1 || true' EXIT

echo "== Sauvegarde et restauration"
FILE=$(PGUSER=sk_backup PGPASSWORD="${SK_BACKUP_PASSWORD:-${SK_DEV_PASSWORD:-change_me_dev}}" sh "$SQL_DIR/backup/backup.sh" "$OUT")
[ -s "$FILE" ] || { echo "ECHEC : sauvegarde vide ou absente" >&2; exit 1; }
[ "$(stat -c %a "$FILE" 2>/dev/null || echo 600)" = "600" ] || { echo "ECHEC : la sauvegarde doit être en 0600" >&2; exit 1; }
echo "ok - sauvegarde créée par sk_backup ($(basename "$FILE"))"

psql -X -q -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS \"$DST\" WITH (FORCE)" -c "CREATE DATABASE \"$DST\""
sh "$SQL_DIR/backup/restore.sh" "$FILE" "$DST" >/dev/null
echo "ok - restauration dans une base vierge"

# Nombre de lignes exact de chaque table, base source puis base restaurée
compte() {
    psql -X -At -d "$1" -c "SELECT string_agg(table_name || '=' || (xpath('/row/n/text()', query_to_xml('SELECT count(*) AS n FROM public.' || quote_ident(table_name), false, true, '')))[1]::text, ',' ORDER BY table_name)
                              FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
}
A=$(compte "$SRC"); B=$(compte "$DST")
if [ "$A" != "$B" ] || [ -z "$A" ]; then
    echo "ECHEC : les nombres de lignes diffèrent entre la source et la base restaurée" >&2
    echo "source   : $A" >&2; echo "restauré : $B" >&2
    exit 1
fi
echo "ok - même nombre de lignes dans les $(echo "$A" | tr ',' '\n' | wc -l) tables"

# Contenu identique (empreinte des clés) et droits conservés
H1=$(psql -X -At -d "$SRC" -c "SELECT md5(string_agg(encode(empreinte,'hex') || statut, ',' ORDER BY empreinte)) FROM cle_licence")
H2=$(psql -X -At -d "$DST" -c "SELECT md5(string_agg(encode(empreinte,'hex') || statut, ',' ORDER BY empreinte)) FROM cle_licence")
[ "$H1" = "$H2" ] || { echo "ECHEC : le contenu de cle_licence diffère après restauration" >&2; exit 1; }
echo "ok - contenu des clés identique"
P=$(psql -X -At -d "$DST" -c "SELECT has_table_privilege('sk_app','cle_licence','UPDATE') AND NOT has_table_privilege('sk_app','cle_licence','DELETE') AND has_table_privilege('sk_reporting','v_ventes_jour','SELECT') AND (SELECT tableowner = 'sk_owner' FROM pg_tables WHERE tablename = 'cle_licence')")
[ "$P" = "t" ] || { echo "ECHEC : propriétaire ou droits non restaurés" >&2; exit 1; }
echo "ok - propriétaire et droits restaurés"

# Le compte de reporting (vues seulement) ne peut pas produire une sauvegarde
if PGUSER=sk_reporting PGPASSWORD="${SK_REPORTING_PASSWORD:-${SK_DEV_PASSWORD:-change_me_dev}}" pg_dump -d "$SRC" -f /dev/null 2>/dev/null; then
    echo "ECHEC : sk_reporting a pu réaliser une sauvegarde complète" >&2; exit 1
fi
echo "ok - sk_reporting ne peut pas sauvegarder la base"

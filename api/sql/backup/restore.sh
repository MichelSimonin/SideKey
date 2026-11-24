#!/bin/sh
# Restaure une sauvegarde (.dump produit par backup.sh) dans une base EXISTANTE.
#   sh restore.sh fichier.dump [nom_base_cible]
# La base cible (par défaut : PGDATABASE) est d'abord VIDÉE de ses objets (--clean) : à n'utiliser
# qu'après confirmation. Le compte de connexion doit pouvoir créer des objets (sk_owner ou administrateur).
# Les objets appartiennent à sk_owner, les droits (GRANT) sont restaurés.
# Procédure complète de reprise : voir docs/architecture/modele-donnees.md (section Sauvegarde et restauration).
set -eu

[ $# -ge 1 ] || { echo "Usage : $0 fichier.dump [base_cible]" >&2; exit 2; }
FILE="$1"
DB="${2:-${PGDATABASE:?base cible non précisée}}"
[ -f "$FILE" ] || { echo "Fichier introuvable : $FILE" >&2; exit 1; }

# Le schéma public de la base cible doit appartenir à sk_owner (comme en production) avant de restaurer
psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -c "ALTER SCHEMA public OWNER TO sk_owner"
pg_restore --clean --if-exists --exit-on-error --single-transaction \
           --no-owner --role=sk_owner --dbname="$DB" "$FILE"
echo "Restauration terminée dans $DB."

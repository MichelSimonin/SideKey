#!/bin/sh
# Sauvegarde logique de la base SideKey : pg_dump au format custom (-Fc, compressé), horodaté.
#   sh backup.sh [dossier]      (par défaut : ./sauvegardes, variable SK_BACKUP_DIR)
# Connexion : PGHOST, PGPORT, PGDATABASE ; compte sk_backup (lecture seule) recommandé :
#   PGUSER=sk_backup PGPASSWORD=...  sh backup.sh
# Le fichier est créé en 0600 ; seul son chemin est affiché sur la sortie standard.
# Les clés de licence restent CHIFFRÉES dans la sauvegarde (AES-256-GCM) : la clé de chiffrement
# n'en fait jamais partie, elle se sauvegarde séparément (gestionnaire de secrets).
# Rotation : SK_BACKUP_KEEP=N conserve les N sauvegardes les plus récentes (par défaut : aucune suppression).
set -eu

DIR="${1:-${SK_BACKUP_DIR:-./sauvegardes}}"
DB="${PGDATABASE:-sidekey}"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
FILE="$DIR/${DB}_${STAMP}.dump"

mkdir -p "$DIR"
umask 077
pg_dump --format=custom --compress=6 --no-owner --dbname="$DB" --file="$FILE.part"
mv "$FILE.part" "$FILE"
# Vérifie que l'archive est lisible avant de la déclarer bonne
pg_restore --list "$FILE" >/dev/null

if [ -n "${SK_BACKUP_KEEP:-}" ]; then
    # shellcheck disable=SC2012
    ls -1t "$DIR/${DB}_"*.dump | tail -n +"$((SK_BACKUP_KEEP + 1))" | while read -r old; do rm -f -- "$old"; done
fi
echo "$FILE"

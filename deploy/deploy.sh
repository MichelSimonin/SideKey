#!/usr/bin/env bash
# Déploiement de SideKey sur le PC d'hébergement (WSL2), en recette ou en production (T-04, ADR 0009).
#
# Lancé par la CD (.github/workflows/_deploy.yml) via Tailscale SSH, ou à la main pour un retour arrière.
# Le script vit dans le dossier de l'environnement, avec compose.yaml, roles/01_roles.sql et .env :
#   ~/sidekey/staging/      ou      ~/sidekey/production/
#
# Commandes :
#   deploy.sh deploy   <version>   sauvegarde, migrations, nouvelle version, test de fumée ;
#                                  en cas d'échec, revient seul à la version précédente
#   deploy.sh rollback [version]   remet la version précédente (ou celle indiquée), sans toucher à la base
#   deploy.sh backup               sauvegarde la base (pg_dump -Fc) dans backups/
#   deploy.sh restore  <fichier> --oui
#                                  restaure une sauvegarde (VIDE la base actuelle) : retour arrière d'une migration
#   deploy.sh status               conteneurs et versions déployées
#
# <version> : tag d'image GHCR, « sha-<commit> » (recette) ou « vX.Y.Z » (production).
set -euo pipefail

APPELANT=$PWD
ICI=$(cd "$(dirname "$0")" && pwd)
cd "$ICI"

ETAT="$ICI/.etat"
SAUVEGARDES="$ICI/backups"
GARDER_SAUVEGARDES="${GARDER_SAUVEGARDES:-14}"
FUMEE_ESSAIS="${FUMEE_ESSAIS:-30}"

journal() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
erreur() { printf '[%s] ERREUR : %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
arret() { erreur "$*"; exit 1; }

[ -f "$ICI/.env" ] || arret "fichier .env absent dans $ICI (il est écrit par la CD)"
[ -f "$ICI/compose.yaml" ] || arret "compose.yaml absent dans $ICI"

# Variables de l'environnement (secrets compris) : lues ici, jamais affichées
set -a
# shellcheck disable=SC1091
. "$ICI/.env"
set +a

case "${SIDEKEY_ENV:-}" in
    staging | production) ;;
    *) arret "SIDEKEY_ENV doit valoir staging ou production (lu : '${SIDEKEY_ENV:-}')" ;;
esac

PROJET="sidekey-$SIDEKEY_ENV"
mkdir -p "$ETAT"

dc() { docker compose --project-name "$PROJET" --file "$ICI/compose.yaml" --env-file "$ICI/.env" "$@"; }

version_valide() {
    [[ "$1" =~ ^(sha-[0-9a-f]{7,40}|v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?)$ ]]
}

version_courante() { cat "$ETAT/version-courante" 2>/dev/null || true; }
version_precedente() { cat "$ETAT/version-precedente" 2>/dev/null || true; }

# Rôles PostgreSQL sk_* (api/sql/roles/01_roles.sql) : idempotent, rejoué à chaque déploiement.
# Les mots de passe passent par l'entrée standard de psql, jamais par la ligne de commande.
appliquer_roles() {
    journal "Rôles PostgreSQL (sk_owner, sk_app, sk_reporting, sk_backup)"
    {
        printf "\\set owner_pw '%s'\n" "$SK_OWNER_PASSWORD"
        printf "\\set app_pw '%s'\n" "$SK_APP_PASSWORD"
        printf "\\set reporting_pw '%s'\n" "$SK_REPORTING_PASSWORD"
        printf "\\set backup_pw '%s'\n" "$SK_BACKUP_PASSWORD"
        cat "$ICI/roles/01_roles.sql"
    } | dc exec -T database psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null
}

# Sauvegarde logique horodatée (compte sk_backup, lecture seule), vérifiée avant d'être gardée
sauvegarder() {
    local etiquette="${1:-manuelle}" horodatage fichier
    horodatage=$(date -u +%Y%m%dT%H%M%SZ)
    fichier="$SAUVEGARDES/${POSTGRES_DB}_${SIDEKEY_ENV}_${horodatage}_${etiquette}.dump"
    mkdir -p "$SAUVEGARDES"
    (
        umask 077
        dc exec -T database pg_dump --format=custom --compress=6 --no-owner \
            -U sk_backup -d "$POSTGRES_DB" >"$fichier.part"
    )
    dc exec -T database pg_restore --list <"$fichier.part" >/dev/null || arret "sauvegarde illisible : $fichier.part"
    mv "$fichier.part" "$fichier"
    # Rotation : on garde les N sauvegardes les plus récentes
    # shellcheck disable=SC2012  # noms maîtrisés (horodatage), sans espace
    ls -1t "$SAUVEGARDES"/*.dump | tail -n +"$((GARDER_SAUVEGARDES + 1))" |
        while read -r ancienne; do rm -f -- "$ancienne"; done
    journal "Sauvegarde : $fichier"
    printf '%s\n' "$fichier" >"$ETAT/derniere-sauvegarde"
}

# Test de fumée : la sonde /api/health/ready vérifie PostgreSQL et Redis à travers Caddy et php-fpm
test_de_fumee() {
    local url="http://127.0.0.1:$HTTP_PORT/api/health/ready" essai
    for essai in $(seq 1 "$FUMEE_ESSAIS"); do
        if curl --silent --fail --max-time 5 "$url" >/dev/null; then
            journal "Test de fumée réussi ($url, essai $essai)"
            return 0
        fi
        sleep 2
    done
    erreur "test de fumée en échec après $FUMEE_ESSAIS essais : $url"
    return 1
}

demarrer_version() {
    local version="$1"
    export IMAGE_TAG="$version"
    dc pull --quiet web api
    dc up --detach --remove-orphans web api worker
}

commande_deploy() {
    local nouvelle="${1:-}" ancienne
    version_valide "$nouvelle" || arret "version invalide : '$nouvelle' (attendu sha-<commit> ou vX.Y.Z)"
    ancienne=$(version_courante)
    journal "Déploiement $SIDEKEY_ENV : ${ancienne:-aucune} -> $nouvelle"

    export IMAGE_TAG="$nouvelle"
    journal "Téléchargement des images"
    dc pull --quiet web api

    journal "Base de données et Redis"
    dc up --detach --wait database redis
    appliquer_roles

    # Sauvegarde AVANT migration (CDC §9.7) : c'est elle qu'on restaure si la migration casse les données
    sauvegarder "avant-$nouvelle"

    journal "Migrations Doctrine (compte sk_owner)"
    if ! dc run --rm --no-deps migrate; then
        erreur "migrations en échec : la version $nouvelle n'est pas démarrée"
        erreur "la transaction de la migration fautive est annulée ; sinon : deploy.sh restore $(cat "$ETAT/derniere-sauvegarde") --oui"
        exit 1
    fi

    journal "Démarrage de la version $nouvelle"
    demarrer_version "$nouvelle"

    if test_de_fumee; then
        if [ -n "$ancienne" ] && [ "$ancienne" != "$nouvelle" ]; then
            printf '%s\n' "$ancienne" >"$ETAT/version-precedente"
        fi
        printf '%s\n' "$nouvelle" >"$ETAT/version-courante"
        printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$nouvelle" >>"$ETAT/historique"
        docker image prune --force --filter "until=168h" >/dev/null || true
        journal "Version $nouvelle en ligne ($PUBLIC_URL)"
        return 0
    fi

    dc logs --tail 50 api web >&2 || true
    if [ -n "$ancienne" ]; then
        erreur "retour automatique à la version $ancienne (les migrations déjà passées restent appliquées)"
        demarrer_version "$ancienne"
        test_de_fumee || erreur "la version $ancienne ne répond pas non plus : intervention manuelle nécessaire"
    fi
    exit 1
}

commande_rollback() {
    local cible="${1:-$(version_precedente)}" courante
    [ -n "$cible" ] || arret "aucune version précédente connue : préciser la version (deploy.sh rollback sha-…)"
    version_valide "$cible" || arret "version invalide : '$cible'"
    courante=$(version_courante)
    journal "Retour arrière $SIDEKEY_ENV : ${courante:-aucune} -> $cible (la base n'est pas modifiée)"
    demarrer_version "$cible"
    test_de_fumee || arret "la version $cible ne répond pas"
    [ -n "$courante" ] && printf '%s\n' "$courante" >"$ETAT/version-precedente"
    printf '%s\n' "$cible" >"$ETAT/version-courante"
    printf '%s %s (retour arrière)\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$cible" >>"$ETAT/historique"
    journal "Version $cible en ligne"
}

commande_restore() {
    local fichier="${1:-}" confirmation="${2:-}"
    # Chemin absolu, relatif au dossier d'appel, ou simple nom d'un fichier de backups/
    case "$fichier" in
        "") ;;
        /*) ;;
        *) if [ -f "$APPELANT/$fichier" ]; then fichier="$APPELANT/$fichier"; else fichier="$SAUVEGARDES/$fichier"; fi ;;
    esac
    [ -f "$fichier" ] || arret "sauvegarde introuvable : '$fichier' (voir $SAUVEGARDES)"
    [ "$confirmation" = "--oui" ] || arret "la restauration VIDE la base $POSTGRES_DB ($SIDEKEY_ENV) : relancer avec --oui"
    export IMAGE_TAG
    IMAGE_TAG=$(version_courante)
    [ -n "$IMAGE_TAG" ] || arret "aucune version déployée"

    journal "Arrêt de l'API, du worker et de Caddy"
    dc stop web worker api
    sauvegarder "avant-restauration"
    journal "Restauration de $(basename "$fichier")"
    dc exec -T database psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
        -c "ALTER SCHEMA public OWNER TO sk_owner"
    dc exec -T database pg_restore --clean --if-exists --exit-on-error --single-transaction \
        --no-owner --role=sk_owner -U "$POSTGRES_USER" -d "$POSTGRES_DB" <"$fichier"
    dc up --detach web api worker
    test_de_fumee || arret "l'application ne répond pas après la restauration"
    journal "Restauration terminée"
}

commande_status() {
    echo "Environnement : $SIDEKEY_ENV ($PUBLIC_URL)"
    echo "Version courante : $(version_courante)"
    echo "Version précédente : $(version_precedente)"
    IMAGE_TAG="$(version_courante)"
    export IMAGE_TAG="${IMAGE_TAG:-aucune}"
    dc ps
}

case "${1:-}" in
    deploy) commande_deploy "${2:-}" ;;
    rollback) commande_rollback "${2:-}" ;;
    backup)
        dc up --detach --wait database
        sauvegarder "manuelle"
        ;;
    restore) commande_restore "${2:-}" "${3:-}" ;;
    status) commande_status ;;
    *)
        sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
        exit 2
        ;;
esac

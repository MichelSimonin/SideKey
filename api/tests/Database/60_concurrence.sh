#!/bin/sh
# Scénario de référence (CDC §10) : deux clients achètent la DERNIÈRE clé d'une offre au même moment.
# Deux sessions PostgreSQL réelles :
#   A réserve la clé et garde sa transaction ouverte 3 s (paiement en cours) ;
#   B tente la même réservation pendant ce temps.
# Attendu : B ne prend pas la clé verrouillée par A (FOR UPDATE SKIP LOCKED), échoue tout de suite
# avec SK001 « stock insuffisant » sans attendre A, et A obtient la clé.
# Lancé par run.sh sur la base chargée avec le jeu d'essai (offre avec une seule clé disponible).
set -eu

echo "== 60_concurrence.sh : dernière clé achetée par deux clients en même temps"
q() { psql -X -q -At -v ON_ERROR_STOP=1 -c "$1"; }

offre=$(q "SELECT offre_id FROM cle_licence WHERE statut = 'DISPONIBLE' GROUP BY offre_id HAVING count(*) = 1 LIMIT 1")
[ -n "$offre" ] || { echo "ECHEC : aucune offre avec une seule clé disponible dans le jeu d'essai" >&2; exit 1; }

nouvelle_ligne() {
    q "WITH u AS (SELECT id FROM utilisateur WHERE actif ORDER BY email LIMIT 1),
            c AS (INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code)
                  SELECT 'EN_ATTENTE_PAIEMENT', o.prix_ttc, now() + interval '15 minutes', u.id, 'fr' FROM u, offre o WHERE o.id = '$offre'
                  RETURNING id, montant_total)
       INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id)
       SELECT 1, montant_total, id, '$offre' FROM c RETURNING id" | head -1
}
ligne_a=$(nouvelle_ligne)
ligne_b=$(nouvelle_ligne)

sortie_a=$(mktemp)
( psql -X -q -At -v ON_ERROR_STOP=1 \
    -c "BEGIN" \
    -c "SELECT 'A:' || fn_reserver_cles('$offre', 1, '$ligne_a')" \
    -c "SELECT pg_sleep(3)" \
    -c "COMMIT" > "$sortie_a" 2>&1 ) &
pid_a=$!
sleep 1

debut=$(date +%s)
if sortie_b=$(psql -X -q -At -v ON_ERROR_STOP=1 -v VERBOSITY=verbose \
        -c "SELECT fn_reserver_cles('$offre', 1, '$ligne_b')" 2>&1); then
    echo "ECHEC : le client B a obtenu une clé alors que A détenait la dernière" >&2
    exit 1
fi
duree=$(( $(date +%s) - debut ))
wait "$pid_a"

echo "$sortie_b" | grep -q "SK001" || { echo "ECHEC : B devait échouer avec SK001, obtenu : $sortie_b" >&2; exit 1; }
echo "ok - client B refusé avec SK001 (stock insuffisant)"
[ "$duree" -lt 2 ] || { echo "ECHEC : B a attendu A ($duree s) au lieu de sauter la clé verrouillée" >&2; exit 1; }
echo "ok - client B n'a pas attendu la transaction de A (SKIP LOCKED, ${duree} s)"
grep -q "^A:" "$sortie_a" || { echo "ECHEC : le client A n'a pas obtenu la clé : $(cat "$sortie_a")" >&2; exit 1; }
echo "ok - client A a obtenu la dernière clé"
[ "$(q "SELECT count(*) FROM cle_licence WHERE offre_id = '$offre' AND statut = 'RESERVEE' AND ligne_commande_id = '$ligne_a'")" = "1" ] \
    || { echo "ECHEC : la clé n'est pas réservée pour A" >&2; exit 1; }
echo "ok - une seule clé réservée, pour A : aucune vente en double"
rm -f "$sortie_a"

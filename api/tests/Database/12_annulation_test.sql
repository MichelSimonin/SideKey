-- Tests de fn_annuler_commande (F-06) : cas nominal ET cas d'erreur, sur base vide.
-- SQLSTATE : 22004 paramètre NULL · P0002 introuvable · SK005 commande non annulable · 42501 droit refusé
BEGIN;
\ir _helpers.sql

DO $$
DECLARE
    o uuid; c uuid; l uuid; k1 uuid; k2 uuid; autre uuid;
    n integer;
BEGIN
    -- ===== Cas nominal : clés remises en stock, commande ANNULEE, historique ===========
    o := pg_temp.mk_offre();
    c := pg_temp.mk_commande();
    l := pg_temp.mk_ligne(c, o);
    k1 := pg_temp.mk_cle(o);
    PERFORM fn_reserver_cles(o, 1, l);
    autre := pg_temp.mk_cle(o, 'RESERVEE', pg_temp.mk_ligne(pg_temp.mk_commande(), o));   -- clé d'une autre commande

    n := fn_annuler_commande(c);
    PERFORM pg_temp.ok(n = 1, 'annulation : une clé libérée');
    PERFORM pg_temp.ok((SELECT statut FROM commande WHERE id = c) = 'ANNULEE', 'annulation : commande ANNULEE');
    PERFORM pg_temp.ok((SELECT statut = 'DISPONIBLE' AND ligne_commande_id IS NULL AND reservee_le IS NULL FROM cle_licence WHERE id = k1),
                       'annulation : clé remise en stock et détachée de la ligne');
    PERFORM pg_temp.ok((SELECT statut FROM cle_licence WHERE id = autre) = 'RESERVEE', 'annulation : les clés des autres commandes ne bougent pas');
    PERFORM pg_temp.ok(EXISTS (SELECT 1 FROM commande_historique WHERE commande_id = c AND ancien_statut = 'EN_ATTENTE_PAIEMENT' AND nouveau_statut = 'ANNULEE'),
                       'annulation : transition historisée (RG-21)');

    -- ===== Cas d'erreur ==============================================================
    PERFORM pg_temp.rejette('SELECT fn_annuler_commande(NULL)', '22004', 'annulation : commande NULL refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_annuler_commande(%L)', gen_random_uuid()), 'P0002', 'annulation : commande inconnue');
    PERFORM pg_temp.rejette(format('SELECT fn_annuler_commande(%L)', c), 'SK005', 'annulation : déjà annulée');
    PERFORM pg_temp.rejette(format('SELECT fn_annuler_commande(%L)', pg_temp.mk_commande(NULL, 'PAYEE')), 'SK005', 'annulation : commande payée refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_annuler_commande(%L)', pg_temp.mk_commande(NULL, 'A_VERIFIER')), 'SK005', 'annulation : commande à vérifier refusée');

    -- Commande dont le délai est dépassé, pas encore passée en EXPIREE par la tâche planifiée
    INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code, cree_le)
    VALUES ('EN_ATTENTE_PAIEMENT', 29.99, now() - interval '1 minute', pg_temp.mk_user(), 'fr', now() - interval '16 minutes')
    RETURNING id INTO c;
    PERFORM pg_temp.rejette(format('SELECT fn_annuler_commande(%L)', c), 'SK005', 'annulation : commande au délai dépassé refusée');

    -- ===== Droits ====================================================================
    PERFORM pg_temp.ok(has_function_privilege('sk_app', 'fn_annuler_commande(uuid)', 'EXECUTE'), 'sk_app peut exécuter fn_annuler_commande');
    PERFORM pg_temp.ok(NOT has_function_privilege('sk_reporting', 'fn_annuler_commande(uuid)', 'EXECUTE'), 'sk_reporting ne peut pas annuler');
    c := pg_temp.mk_commande();
    PERFORM pg_temp.rejette_en_tant_que('sk_reporting', format('SELECT fn_annuler_commande(%L)', c), '42501', 'sk_reporting : annulation refusée');
    PERFORM pg_temp.accepte_en_tant_que('sk_app', format('SELECT fn_annuler_commande(%L)', c), 'sk_app : annulation exécutée');
END
$$;

ROLLBACK;

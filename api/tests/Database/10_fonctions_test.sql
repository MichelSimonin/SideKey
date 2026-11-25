-- Tests des fonctions métier (T-02) : cas nominal ET cas d'erreur, sur base vide.
-- SQLSTATE : 22004 paramètre NULL · 22023 paramètre invalide · P0002 introuvable
--            SK001 stock insuffisant · SK005 commande non payable · 42501 droit refusé
BEGIN;
\ir _helpers.sql

-- Commande dont la réservation a expiré (créée il y a 20 min, délai de 15 min)
CREATE FUNCTION pg_temp.mk_commande_expiree() RETURNS uuid LANGUAGE sql AS $$
    INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code, cree_le)
    VALUES ('EN_ATTENTE_PAIEMENT', 29.99, now() - interval '5 minutes', pg_temp.mk_user(), 'fr', now() - interval '20 minutes')
    RETURNING id
$$;

DO $$
DECLARE
    o  uuid; o2 uuid;
    c  uuid; c2 uuid;
    l  uuid; l2 uuid;
    k1 uuid; k2 uuid; k3 uuid;
    n  integer;
    s  text;
BEGIN
    -- ===== fn_reserver_cles : paramètres =====================================
    o := pg_temp.mk_offre();
    c := pg_temp.mk_commande();
    l := pg_temp.mk_ligne(c, o);
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(NULL, 1, %L)', l), '22004', 'réservation : offre NULL refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, NULL, %L)', o, l), '22004', 'réservation : quantité NULL refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, NULL)', o), '22004', 'réservation : ligne NULL refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 0, %L)', o, l), '22023', 'réservation : quantité 0 refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 11, %L)', o, l), '22023', 'réservation : quantité 11 refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', o, gen_random_uuid()), 'P0002', 'réservation : ligne inexistante refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', pg_temp.mk_offre(), l), '22023', 'réservation : offre différente de celle de la ligne refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 2, %L)', o, l), '22023', 'réservation : quantité différente de la ligne refusée');

    -- ===== fn_reserver_cles : état de la commande et de l'offre ==============
    c2 := pg_temp.mk_commande_expiree();
    l2 := pg_temp.mk_ligne(c2, o);
    PERFORM pg_temp.mk_cle(o);
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', o, l2), 'SK005', 'réservation : commande expirée refusée (RG-12)');
    c2 := pg_temp.mk_commande(NULL, 'ANNULEE');
    l2 := pg_temp.mk_ligne(c2, o);
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', o, l2), 'SK005', 'réservation : commande annulée refusée');

    o2 := pg_temp.mk_offre();
    UPDATE offre SET actif = false WHERE id = o2;
    PERFORM pg_temp.mk_cle(o2);
    l2 := pg_temp.mk_ligne(pg_temp.mk_commande(), o2);
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', o2, l2), 'P0002', 'réservation : offre inactive refusée');

    -- ===== fn_reserver_cles : stock ==========================================
    o2 := pg_temp.mk_offre();
    PERFORM pg_temp.mk_cle(o2);
    c2 := pg_temp.mk_commande();
    INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (2, 29.99, c2, o2) RETURNING id INTO l2;
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 2, %L)', o2, l2), 'SK001', 'réservation : stock insuffisant (1 clé pour 2) → SK001');
    PERFORM pg_temp.ok((SELECT count(*) FROM cle_licence WHERE offre_id = o2 AND statut = 'DISPONIBLE') = 1,
                       'réservation : tout ou rien, la clé restante n''a pas été réservée');
    PERFORM pg_temp.ok((SELECT count(*) FROM fn_reserver_cles(o, 1, l)) = 1, 'réservation : cas nominal, une clé réservée');
    PERFORM pg_temp.ok((SELECT count(*) FROM cle_licence WHERE ligne_commande_id = l AND statut = 'RESERVEE' AND reservee_le IS NOT NULL) = 1,
                       'réservation : clé RESERVEE, liée à la ligne, datée');
    PERFORM pg_temp.rejette(format('SELECT fn_reserver_cles(%L, 1, %L)', o, l), '22023', 'réservation : deuxième réservation pour la même ligne refusée');

    -- FIFO : la clé importée la première est servie la première
    o2 := pg_temp.mk_offre();
    k1 := pg_temp.mk_cle(o2); k2 := pg_temp.mk_cle(o2);
    UPDATE cle_licence SET importee_le = now() - interval '2 days' WHERE id = k2;
    l2 := pg_temp.mk_ligne(pg_temp.mk_commande(), o2);
    PERFORM pg_temp.ok((SELECT fn_reserver_cles(o2, 1, l2) LIMIT 1) = k2, 'réservation : la clé la plus ancienne est servie d''abord');

    -- ===== fn_valider_commande ===============================================
    PERFORM pg_temp.rejette('SELECT fn_valider_commande(NULL)', '22004', 'validation : commande NULL refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_valider_commande(%L)', gen_random_uuid()), 'P0002', 'validation : commande inexistante refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_valider_commande(%L)', pg_temp.mk_commande_expiree()), 'SK005', 'validation : commande expirée refusée');
    PERFORM pg_temp.rejette(format('SELECT fn_valider_commande(%L)', pg_temp.mk_commande(NULL, 'ANNULEE')), 'SK005', 'validation : commande annulée refusée');
    c2 := pg_temp.mk_commande();
    PERFORM pg_temp.mk_ligne(c2, pg_temp.mk_offre());
    PERFORM pg_temp.rejette(format('SELECT fn_valider_commande(%L)', c2), 'SK005', 'validation : commande sans clé réservée refusée');

    s := fn_valider_commande(c);
    PERFORM pg_temp.ok(s = 'PAYEE' AND (SELECT statut FROM commande WHERE id = c) = 'PAYEE'
                       AND (SELECT payee_le FROM commande WHERE id = c) IS NOT NULL, 'validation : commande PAYEE et datée');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM cle_licence WHERE ligne_commande_id = l AND statut <> 'VENDUE'), 'validation : clés passées en VENDUE');
    PERFORM pg_temp.ok(fn_valider_commande(c) = 'PAYEE', 'validation : idempotente (deuxième appel sans erreur)');

    -- validation d'une commande A_VERIFIER (décision admin)
    o2 := pg_temp.mk_offre(); PERFORM pg_temp.mk_cle(o2);
    c2 := pg_temp.mk_commande(); l2 := pg_temp.mk_ligne(c2, o2);
    PERFORM fn_reserver_cles(o2, 1, l2);
    UPDATE commande SET statut = 'A_VERIFIER' WHERE id = c2;
    PERFORM pg_temp.ok(fn_valider_commande(c2) = 'PAYEE', 'validation : commande A_VERIFIER validée par un admin');

    -- ===== fn_liberer_reservations_expirees ==================================
    o2 := pg_temp.mk_offre();
    k1 := pg_temp.mk_cle(o2); k2 := pg_temp.mk_cle(o2);
    c2 := pg_temp.mk_commande_expiree(); l2 := pg_temp.mk_ligne(c2, o2);
    UPDATE cle_licence SET statut = 'RESERVEE', reservee_le = now(), ligne_commande_id = l2 WHERE id = k1;
    c := pg_temp.mk_commande(); l := pg_temp.mk_ligne(c, o2);
    PERFORM fn_reserver_cles(o2, 1, l);   -- réservation encore valide : ne doit pas bouger
    n := fn_liberer_reservations_expirees();
    PERFORM pg_temp.ok(n >= 1, 'libération : au moins une commande expirée traitée');
    PERFORM pg_temp.ok((SELECT statut FROM commande WHERE id = c2) = 'EXPIREE', 'libération : commande passée en EXPIREE');
    PERFORM pg_temp.ok((SELECT statut = 'DISPONIBLE' AND ligne_commande_id IS NULL AND reservee_le IS NULL FROM cle_licence WHERE id = k1),
                       'libération : clé remise en stock, détachée de la ligne');
    PERFORM pg_temp.ok((SELECT statut FROM commande WHERE id = c) = 'EN_ATTENTE_PAIEMENT'
                       AND EXISTS (SELECT 1 FROM cle_licence WHERE ligne_commande_id = l AND statut = 'RESERVEE'),
                       'libération : une réservation non expirée est conservée');
    PERFORM pg_temp.ok(fn_liberer_reservations_expirees() = 0, 'libération : deuxième passage sans effet');

    -- ===== Droits EXECUTE (CDC §9.5) =========================================
    FOR s IN SELECT unnest(ARRAY['fn_reserver_cles(uuid,integer,uuid)', 'fn_valider_commande(uuid)', 'fn_liberer_reservations_expirees()']) LOOP
        PERFORM pg_temp.ok(has_function_privilege('sk_app', s, 'EXECUTE'), format('sk_app peut exécuter %s', s));
        PERFORM pg_temp.ok(NOT has_function_privilege('sk_reporting', s, 'EXECUTE'), format('sk_reporting ne peut pas exécuter %s', s));
        PERFORM pg_temp.ok(NOT has_function_privilege('sk_backup', s, 'EXECUTE'), format('sk_backup ne peut pas exécuter %s', s));
        PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM aclexplode((SELECT proacl FROM pg_proc WHERE oid = s::regprocedure)) WHERE grantee = 0),
                           format('PUBLIC ne peut pas exécuter %s', s));
        PERFORM pg_temp.ok((SELECT NOT prosecdef AND proconfig @> ARRAY['search_path=public, pg_temp'] FROM pg_proc WHERE oid = s::regprocedure),
                           format('%s : SECURITY INVOKER et search_path figé', s));
    END LOOP;
    PERFORM pg_temp.rejette_en_tant_que('sk_reporting', 'SELECT fn_liberer_reservations_expirees()', '42501',
                                        'sk_reporting : appel de fonction refusé');

    -- Parcours complet exécuté avec le compte applicatif (droits réels de sk_app)
    o2 := pg_temp.mk_offre(); PERFORM pg_temp.mk_cle(o2);
    c2 := pg_temp.mk_commande(); l2 := pg_temp.mk_ligne(c2, o2);
    PERFORM pg_temp.accepte_en_tant_que('sk_app', format('SELECT fn_reserver_cles(%L, 1, %L)', o2, l2), 'sk_app : réservation exécutée');
    PERFORM pg_temp.accepte_en_tant_que('sk_app', format('SELECT fn_valider_commande(%L)', c2), 'sk_app : validation exécutée');
    PERFORM pg_temp.accepte_en_tant_que('sk_app', 'SELECT fn_liberer_reservations_expirees()', 'sk_app : libération exécutée');
END
$$;

ROLLBACK;

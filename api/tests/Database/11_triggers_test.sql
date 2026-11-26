-- Tests des triggers défensifs (T-02), sur base vide.
-- SQLSTATE : SK002 transition interdite · SK003 clé vendue protégée · SK004 trace immuable
BEGIN;
\ir _helpers.sql

DO $$
DECLARE
    o uuid; c uuid; l uuid; k uuid; u uuid; a uuid;
    depart text; arrivee text;
    n integer;
BEGIN
    -- ===== trg_commande_transition : diagramme §6.4 ==========================
    -- Transitions autorisées
    FOR depart, arrivee IN VALUES
        ('EN_ATTENTE_PAIEMENT', 'PAYEE'), ('EN_ATTENTE_PAIEMENT', 'A_VERIFIER'), ('EN_ATTENTE_PAIEMENT', 'EXPIREE'),
        ('EN_ATTENTE_PAIEMENT', 'ANNULEE'), ('A_VERIFIER', 'PAYEE'), ('A_VERIFIER', 'REMBOURSEE'), ('PAYEE', 'REMBOURSEE')
    LOOP
        c := pg_temp.mk_commande(NULL, depart);
        UPDATE commande SET statut = arrivee WHERE id = c;
        PERFORM pg_temp.ok((SELECT statut FROM commande WHERE id = c) = arrivee, format('transition %s → %s acceptée', depart, arrivee));
    END LOOP;

    -- Quelques transitions interdites, dont les retours en arrière
    FOR depart, arrivee IN VALUES
        ('PAYEE', 'EN_ATTENTE_PAIEMENT'), ('PAYEE', 'ANNULEE'), ('EXPIREE', 'PAYEE'), ('EXPIREE', 'EN_ATTENTE_PAIEMENT'),
        ('ANNULEE', 'PAYEE'), ('REMBOURSEE', 'PAYEE'), ('A_VERIFIER', 'EXPIREE'), ('EN_ATTENTE_PAIEMENT', 'REMBOURSEE')
    LOOP
        c := pg_temp.mk_commande(NULL, depart);
        PERFORM pg_temp.rejette(format('UPDATE commande SET statut = %L WHERE id = %L', arrivee, c), 'SK002',
                                format('transition %s → %s refusée', depart, arrivee));
    END LOOP;

    c := pg_temp.mk_commande();
    UPDATE commande SET montant_total = 10 WHERE id = c;
    PERFORM pg_temp.ok(true, 'mise à jour sans changement de statut acceptée');

    -- ===== trg_commande_historique (RG-21) ===================================
    u := pg_temp.mk_user();
    c := pg_temp.mk_commande(u);
    PERFORM pg_temp.ok((SELECT count(*) FROM commande_historique WHERE commande_id = c AND ancien_statut IS NULL
                         AND nouveau_statut = 'EN_ATTENTE_PAIEMENT') = 1, 'historique : création historisée');
    PERFORM set_config('sidekey.auteur_id', u::text, true);
    UPDATE commande SET statut = 'ANNULEE' WHERE id = c;
    PERFORM pg_temp.ok((SELECT count(*) FROM commande_historique WHERE commande_id = c AND ancien_statut = 'EN_ATTENTE_PAIEMENT'
                         AND nouveau_statut = 'ANNULEE' AND auteur_id = u) = 1, 'historique : changement historisé avec son auteur');
    PERFORM set_config('sidekey.auteur_id', '', true);
    n := (SELECT count(*) FROM commande_historique WHERE commande_id = c);
    UPDATE commande SET montant_total = 5 WHERE id = c;
    PERFORM pg_temp.ok((SELECT count(*) FROM commande_historique WHERE commande_id = c) = n, 'historique : rien si le statut ne change pas');
    PERFORM pg_temp.rejette(format('UPDATE commande SET statut = %L WHERE id = %L', 'PAYEE', c), 'SK002', 'historique : transition refusée');
    PERFORM pg_temp.ok((SELECT count(*) FROM commande_historique WHERE commande_id = c) = n, 'historique : rien pour une transition refusée');

    -- ===== trg_cle_licence_protection (RG-04) ================================
    o := pg_temp.mk_offre();
    c := pg_temp.mk_commande(NULL, 'PAYEE');
    l := pg_temp.mk_ligne(c, o);
    k := pg_temp.mk_cle(o, 'VENDUE', l);
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET statut = %L WHERE id = %L', 'DISPONIBLE', k), 'SK003', 'clé vendue : remise en stock refusée');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET cle_chiffree = %L WHERE id = %L', '\x01', k), 'SK003', 'clé vendue : chiffré non modifiable');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET offre_id = %L WHERE id = %L', pg_temp.mk_offre(), k), 'SK003', 'clé vendue : changement d''offre refusé');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET ligne_commande_id = NULL, statut = %L WHERE id = %L', 'RESERVEE', k), 'SK003', 'clé vendue : détachement de la commande refusé');
    PERFORM pg_temp.rejette(format('DELETE FROM cle_licence WHERE id = %L', k), 'SK003', 'clé vendue : suppression refusée, même par le propriétaire');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET suffixe = %L WHERE id = %L', 'ZZZZ', k), 'SK003', 'clé vendue : suffixe non modifiable (F-08)');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET revelee_le = now(), suffixe = %L WHERE id = %L', 'ZZZZ', k), 'SK003', 'clé vendue : suffixe non modifiable, même avec la première révélation (F-08)');
    UPDATE cle_licence SET revelee_le = now(), modifie_le = now() WHERE id = k;
    PERFORM pg_temp.ok((SELECT revelee_le IS NOT NULL FROM cle_licence WHERE id = k), 'clé vendue : première révélation acceptée');
    PERFORM pg_temp.rejette(format('UPDATE cle_licence SET revelee_le = now() + interval ''1 hour'' WHERE id = %L', k), 'SK003', 'clé vendue : date de révélation non réécrite');

    k := pg_temp.mk_cle(o);
    UPDATE cle_licence SET statut = 'RESERVEE', reservee_le = now() WHERE id = k;
    PERFORM pg_temp.ok((SELECT statut FROM cle_licence WHERE id = k) = 'RESERVEE', 'clé non vendue : modification acceptée');
    DELETE FROM cle_licence WHERE id = pg_temp.mk_cle(o);
    PERFORM pg_temp.ok(true, 'clé non vendue : suppression acceptée (propriétaire)');

    -- ===== Traces immuables (BX-16, ENF-12) ==================================
    INSERT INTO journal_audit (action, ressource) VALUES ('TEST', 'commande') RETURNING id INTO a;
    PERFORM pg_temp.rejette(format('UPDATE journal_audit SET action = %L WHERE id = %L', 'FALSIFIE', a), 'SK004', 'journal d''audit : UPDATE refusé, même par le propriétaire');
    PERFORM pg_temp.rejette(format('DELETE FROM journal_audit WHERE id = %L', a), 'SK004', 'journal d''audit : DELETE refusé');
    PERFORM pg_temp.rejette('TRUNCATE journal_audit', 'SK004', 'journal d''audit : TRUNCATE refusé');
    PERFORM pg_temp.rejette('UPDATE commande_historique SET nouveau_statut = ''PAYEE''', 'SK004', 'historique : UPDATE refusé');
    PERFORM pg_temp.rejette('DELETE FROM commande_historique', 'SK004', 'historique : DELETE refusé');
    PERFORM pg_temp.rejette('TRUNCATE commande_historique', 'SK004', 'historique : TRUNCATE refusé');

    -- ===== Fonctions de trigger non appelables =================================
    PERFORM pg_temp.ok(NOT has_function_privilege('sk_app', 'trg_trace_immuable_fn()', 'EXECUTE'), 'fonctions de trigger : sk_app sans EXECUTE');
    PERFORM pg_temp.ok((SELECT count(*) FROM pg_trigger WHERE tgname ~ '^trg_' AND NOT tgisinternal) = 7, '7 triggers trg_* installés');
END
$$;

ROLLBACK;

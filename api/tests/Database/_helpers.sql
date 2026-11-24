-- Aides communes aux tests SQL (incluses avec \ir _helpers.sql, dans une transaction annulée à la fin).
-- Les fonctions vivent dans pg_temp : elles disparaissent avec la session.

CREATE FUNCTION pg_temp.ok(condition boolean, message text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    IF condition IS NOT TRUE THEN
        RAISE EXCEPTION 'ECHEC : %', message;
    END IF;
    RAISE NOTICE 'ok - %', message;
END
$$;

-- Vérifie qu'une instruction est REJETÉE avec le SQLSTATE attendu
-- (23502 not null, 23503 clé étrangère, 23505 unicité, 23514 CHECK, 42501 droit refusé).
CREATE FUNCTION pg_temp.rejette(instruction text, etat_attendu text, message text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE instruction;
    EXCEPTION WHEN OTHERS THEN
        IF SQLSTATE = etat_attendu THEN
            RAISE NOTICE 'ok - %', message;
            RETURN;
        END IF;
        RAISE EXCEPTION 'ECHEC : % (SQLSTATE obtenu %, attendu %) : %', message, SQLSTATE, etat_attendu, SQLERRM;
    END;
    RAISE EXCEPTION 'ECHEC : % (aucune erreur levée)', message;
END
$$;

-- Jeux de données minimaux et valides, pour fabriquer des cas de test.
CREATE FUNCTION pg_temp.mk_editeur() RETURNS uuid LANGUAGE sql AS $$
    INSERT INTO editeur (nom) VALUES ('Éditeur ' || gen_random_uuid()) RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_jeu() RETURNS uuid LANGUAGE sql AS $$
    INSERT INTO jeu (titre, slug, pegi, editeur_id)
    VALUES ('Jeu de test', 'jeu-' || gen_random_uuid(), 12, pg_temp.mk_editeur()) RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_offre(p_jeu uuid DEFAULT NULL, p_plateforme text DEFAULT 'STEAM') RETURNS uuid
LANGUAGE sql AS $$
    INSERT INTO offre (prix_ttc, jeu_id, plateforme_id)
    VALUES (29.99, COALESCE(p_jeu, pg_temp.mk_jeu()), (SELECT id FROM plateforme WHERE code = p_plateforme))
    RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_user() RETURNS uuid LANGUAGE sql AS $$
    INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom)
    VALUES (gen_random_uuid() || '@test.sidekey.local', 'hash-de-test', 'Test', 'SideKey') RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_commande(p_user uuid DEFAULT NULL, p_statut text DEFAULT 'EN_ATTENTE_PAIEMENT') RETURNS uuid
LANGUAGE sql AS $$
    INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code)
    VALUES (p_statut, 29.99, now() + interval '15 minutes', COALESCE(p_user, pg_temp.mk_user()), 'fr')
    RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_ligne(p_commande uuid, p_offre uuid) RETURNS uuid LANGUAGE sql AS $$
    INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id)
    VALUES (1, 29.99, p_commande, p_offre) RETURNING id
$$;

CREATE FUNCTION pg_temp.mk_cle(p_offre uuid, p_statut text DEFAULT 'DISPONIBLE', p_ligne uuid DEFAULT NULL) RETURNS uuid
LANGUAGE sql AS $$
    INSERT INTO cle_licence (cle_chiffree, empreinte, statut, offre_id, ligne_commande_id)
    VALUES ('\x00'::bytea, sha256(convert_to(gen_random_uuid()::text, 'UTF8')), p_statut, p_offre, p_ligne)
    RETURNING id
$$;

-- Test du schéma (T-01, point 1) : structure, clés primaires et étrangères, types, nommage, référentiels.
-- Lancé par run.sh sur une base fraîchement migrée.
BEGIN;
\ir _helpers.sql

DO $$
DECLARE n integer; v uuid;
BEGIN
    -- Tables --------------------------------------------------------------
    SELECT count(*) INTO n FROM information_schema.tables
     WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
    PERFORM pg_temp.ok(n = 21, '21 tables dans le schéma public (obtenu ' || n || ')');

    SELECT count(*) INTO n FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace
     WHERE s.nspname = 'public' AND c.relkind = 'r' AND c.relname !~ '^[a-z]+(_[a-z]+)*$';
    PERFORM pg_temp.ok(n = 0, 'noms de tables en snake_case');

    -- Clés primaires -------------------------------------------------------
    SELECT count(*) INTO n FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace
     WHERE s.nspname = 'public' AND c.relkind = 'r'
       AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = c.oid AND k.contype = 'p');
    PERFORM pg_temp.ok(n = 0, 'chaque table a une clé primaire');

    -- Clés étrangères : 28 au MPD, toutes en ON DELETE RESTRICT ---------------
    SELECT count(*) INTO n FROM pg_constraint k JOIN pg_namespace s ON s.oid = k.connamespace
     WHERE s.nspname = 'public' AND k.contype = 'f';
    PERFORM pg_temp.ok(n = 28, '28 clés étrangères (obtenu ' || n || ')');

    SELECT count(*) INTO n FROM pg_constraint k JOIN pg_namespace s ON s.oid = k.connamespace
     WHERE s.nspname = 'public' AND k.contype = 'f' AND k.confdeltype <> 'r';
    PERFORM pg_temp.ok(n = 0, 'aucune suppression en cascade (ON DELETE RESTRICT partout)');

    -- Nommage des contraintes (CDC §9.1) -------------------------------------
    SELECT count(*) INTO n FROM pg_constraint k JOIN pg_namespace s ON s.oid = k.connamespace
     WHERE s.nspname = 'public' AND k.conname !~ '^(pk|fk|uq|ck)_';
    PERFORM pg_temp.ok(n = 0, 'contraintes préfixées pk_ / fk_ / uq_ / ck_');

    -- Types ------------------------------------------------------------------
    SELECT count(*) INTO n FROM information_schema.columns
     WHERE table_schema = 'public' AND data_type IN ('real', 'double precision');
    PERFORM pg_temp.ok(n = 0, 'aucun float : les montants sont en numeric');

    SELECT count(*) INTO n FROM information_schema.columns
     WHERE table_schema = 'public'
       AND column_name IN ('prix_ttc', 'prix_unitaire', 'montant', 'montant_total')
       AND NOT (data_type = 'numeric' AND numeric_precision = 10 AND numeric_scale = 2);
    PERFORM pg_temp.ok(n = 0, 'montants en numeric(10,2)');

    SELECT count(*) INTO n FROM information_schema.columns
     WHERE table_schema = 'public' AND column_name LIKE '%\_le' AND data_type <> 'timestamp with time zone';
    PERFORM pg_temp.ok(n = 0, 'horodatages (*_le) en timestamptz');

    SELECT count(*) INTO n FROM information_schema.columns
     WHERE table_schema = 'public' AND column_name = 'id' AND data_type <> 'uuid';
    PERFORM pg_temp.ok(n = 0, 'identifiants en uuid (non énumérables)');

    -- Données de référence -----------------------------------------------------
    PERFORM pg_temp.ok((SELECT count(*) FROM langue) = 2, 'langues : fr et en');
    PERFORM pg_temp.ok((SELECT count(*) FROM role) = 4, 'rôles : CLIENT, GESTIONNAIRE, MODERATEUR, ADMIN');
    PERFORM pg_temp.ok((SELECT count(*) FROM plateforme) = 4, 'plateformes : STEAM, PSN, XBOX, ESHOP');

    -- Valeurs par défaut -------------------------------------------------------
    -- (l'identifiant passe par une variable : une ligne insérée par une fonction n'est pas
    --  visible dans la requête qui l'appelle)
    PERFORM pg_temp.ok(pg_temp.mk_editeur() IS NOT NULL, 'identifiant généré par gen_random_uuid()');
    v := pg_temp.mk_commande();
    PERFORM pg_temp.ok((SELECT reference <> id FROM commande WHERE id = v),
                       'commande.reference (exposée) différente de commande.id (interne)');
    v := pg_temp.mk_offre();
    PERFORM pg_temp.ok((SELECT version = 1 AND actif AND seuil_alerte = 5 FROM offre WHERE id = v),
                       'offre : version 1, active, seuil d''alerte 5 par défaut');
    v := pg_temp.mk_user();
    PERFORM pg_temp.ok((SELECT langue_code = 'fr' AND NOT a2f_active AND actif AND NOT email_verifie
                          FROM utilisateur WHERE id = v),
                       'utilisateur : français, A2F inactive, actif, e-mail non vérifié par défaut');
    v := pg_temp.mk_cle(pg_temp.mk_offre());
    PERFORM pg_temp.ok((SELECT statut = 'DISPONIBLE' FROM cle_licence WHERE id = v),
                       'clé : DISPONIBLE par défaut');

    -- Intégrité référentielle ----------------------------------------------------
    PERFORM pg_temp.rejette(
        $q$INSERT INTO jeu (titre, slug, pegi, editeur_id) VALUES ('X', 'x', 12, gen_random_uuid())$q$,
        '23503', 'jeu : éditeur inexistant refusé');
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, langue_code)
                  VALUES ('a@b.c', 'h', 'A', 'B', 'xx')$q$),
        '23503', 'utilisateur : langue inexistante refusée');
    v := pg_temp.mk_jeu();
    PERFORM pg_temp.rejette(
        format('DELETE FROM editeur WHERE id = %L', (SELECT editeur_id FROM jeu WHERE id = v)),
        '23503', 'suppression d''un éditeur référencé refusée (RESTRICT)');
    PERFORM pg_temp.rejette(
        $q$INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom) VALUES (NULL, 'h', 'A', 'B')$q$,
        '23502', 'utilisateur : e-mail obligatoire (NOT NULL)');
END
$$;

ROLLBACK;

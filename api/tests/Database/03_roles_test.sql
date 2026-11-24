-- Test des comptes PostgreSQL et de leurs droits (T-01, point 3) : droits minimaux, DELETE refusé,
-- DDL refusé, reporting limité aux vues. Les refus sont attendus avec le SQLSTATE 42501 (droit insuffisant).
BEGIN;
\ir _helpers.sql

DO $$
DECLARE
    n      integer;
    o      uuid;
    j      uuid;
    u      uuid;
    c      uuid;
    l      uuid;
    k      uuid;
    t      text;
    ventes integer;
    ca     numeric;
    dispo  integer;
    bas    boolean;
BEGIN
    -- ===== Comptes ===============================================================
    SELECT count(*) INTO n FROM pg_roles
     WHERE rolname IN ('sk_owner', 'sk_app', 'sk_reporting', 'sk_backup')
       AND rolcanlogin AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls;
    PERFORM pg_temp.ok(n = 4, '4 comptes sk_* : connexion possible, aucun privilège d''administration');

    SELECT count(*) INTO n FROM pg_authid
     WHERE rolname IN ('sk_owner', 'sk_app', 'sk_reporting', 'sk_backup') AND rolpassword LIKE 'SCRAM-SHA-256$%';
    PERFORM pg_temp.ok(n = 4, 'mots de passe stockés en SCRAM-SHA-256');

    SELECT count(*) INTO n FROM pg_tables WHERE schemaname = 'public' AND tableowner <> 'sk_owner';
    PERFORM pg_temp.ok(n = 0, 'toutes les tables appartiennent à sk_owner (DDL réservé aux migrations)');
    SELECT count(*) INTO n FROM pg_views WHERE schemaname = 'public' AND viewowner <> 'sk_owner';
    PERFORM pg_temp.ok(n = 0, 'toutes les vues appartiennent à sk_owner');
    PERFORM pg_temp.ok((SELECT nspowner::regrole::text FROM pg_namespace WHERE nspname = 'public') = 'sk_owner',
                       'le schéma public appartient à sk_owner');

    -- Un compte quelconque n'a rien : ni connexion, ni schéma, ni table
    CREATE ROLE sk_intrus NOLOGIN;
    PERFORM pg_temp.ok(NOT has_database_privilege('sk_intrus', current_database(), 'CONNECT'), 'PUBLIC n''a pas CONNECT sur la base');
    PERFORM pg_temp.ok(NOT has_schema_privilege('sk_intrus', 'public', 'USAGE'), 'PUBLIC n''a pas USAGE sur le schéma');
    PERFORM pg_temp.ok(NOT has_table_privilege('sk_intrus', 'cle_licence', 'SELECT'), 'PUBLIC ne lit pas les clés de licence');

    -- Fonctions futures (fn_* de T-02) : exécutables par sk_app seulement
    SET LOCAL ROLE sk_owner;
    CREATE FUNCTION public.fn_test_droits() RETURNS integer LANGUAGE sql AS 'SELECT 1';
    RESET ROLE;
    PERFORM pg_temp.ok(has_function_privilege('sk_app', 'public.fn_test_droits()', 'EXECUTE'), 'sk_app peut exécuter les fonctions fn_*');
    PERFORM pg_temp.ok(NOT has_function_privilege('sk_reporting', 'public.fn_test_droits()', 'EXECUTE'), 'sk_reporting ne peut pas exécuter les fonctions fn_*');
    PERFORM pg_temp.ok(NOT has_function_privilege('sk_intrus', 'public.fn_test_droits()', 'EXECUTE'), 'PUBLIC ne peut pas exécuter les fonctions fn_*');

    -- ===== sk_app : DML limité ===================================================
    o := pg_temp.mk_offre();
    k := pg_temp.mk_cle(o);
    c := pg_temp.mk_commande();
    INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'ACCEPTE', 'droits-1', c);
    INSERT INTO journal_audit (action, ressource) VALUES ('TEST', 'droits');
    INSERT INTO commande_historique (nouveau_statut, commande_id) VALUES ('EN_ATTENTE_PAIEMENT', c);

    PERFORM pg_temp.accepte_en_tant_que('sk_app',
        format('UPDATE cle_licence SET statut = %L, reservee_le = now() WHERE id = %L', 'RESERVEE', k),
        'sk_app peut réserver une clé (UPDATE cle_licence)');
    PERFORM pg_temp.accepte_en_tant_que('sk_app',
        format($q$INSERT INTO journal_audit (action, ressource, ressource_id) VALUES ('REVELATION', 'cle_licence', %L)$q$, k),
        'sk_app peut écrire dans le journal d''audit');
    PERFORM pg_temp.accepte_en_tant_que('sk_app', 'SELECT count(*) FROM langue', 'sk_app lit les référentiels');
    PERFORM pg_temp.accepte_en_tant_que('sk_app', format('DELETE FROM code_secours WHERE utilisateur_id = %L', pg_temp.mk_user()),
        'sk_app peut supprimer des codes de secours (DELETE autorisé hors tables sensibles)');

    -- DELETE interdit sur les tables sensibles
    PERFORM pg_temp.rejette_en_tant_que('sk_app', format('DELETE FROM cle_licence WHERE id = %L', k), '42501', 'sk_app : DELETE refusé sur cle_licence');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', format('DELETE FROM commande WHERE id = %L', c), '42501', 'sk_app : DELETE refusé sur commande');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'DELETE FROM paiement', '42501', 'sk_app : DELETE refusé sur paiement');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'DELETE FROM journal_audit', '42501', 'sk_app : DELETE refusé sur journal_audit');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'UPDATE journal_audit SET action = ''X''', '42501', 'sk_app : UPDATE refusé sur journal_audit (ajout seul)');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'DELETE FROM commande_historique', '42501', 'sk_app : DELETE refusé sur commande_historique');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'UPDATE commande_historique SET nouveau_statut = ''PAYEE''', '42501', 'sk_app : UPDATE refusé sur commande_historique');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'INSERT INTO langue (code, libelle) VALUES (''de'', ''Deutsch'')', '42501', 'sk_app : les référentiels sont en lecture seule');

    -- Aucun DDL
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'CREATE TABLE intrus (id int)', '42501', 'sk_app : CREATE TABLE refusé');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'DROP TABLE cle_licence', '42501', 'sk_app : DROP TABLE refusé');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'ALTER TABLE offre ADD COLUMN x int', '42501', 'sk_app : ALTER TABLE refusé');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'TRUNCATE jeu_genre', '42501', 'sk_app : TRUNCATE refusé');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'CREATE ROLE pirate', '42501', 'sk_app : CREATE ROLE refusé');
    PERFORM pg_temp.rejette_en_tant_que('sk_app', 'SELECT rolpassword FROM pg_authid', '42501', 'sk_app ne lit pas les mots de passe des comptes (pg_authid)');

    -- Matrice des droits (has_table_privilege)
    FOREACH t IN ARRAY ARRAY['editeur','genre','genre_traduction','jeu','jeu_traduction','jeu_genre','offre','utilisateur',
                             'utilisateur_role','code_secours','ligne_commande','avis','import_cles'] LOOP
        PERFORM pg_temp.ok(has_table_privilege('sk_app', t, 'SELECT') AND has_table_privilege('sk_app', t, 'INSERT')
                       AND has_table_privilege('sk_app', t, 'UPDATE') AND has_table_privilege('sk_app', t, 'DELETE'),
                       'sk_app : SELECT, INSERT, UPDATE, DELETE sur ' || t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['cle_licence', 'commande', 'paiement'] LOOP
        PERFORM pg_temp.ok(has_table_privilege('sk_app', t, 'SELECT') AND has_table_privilege('sk_app', t, 'INSERT')
                       AND has_table_privilege('sk_app', t, 'UPDATE') AND NOT has_table_privilege('sk_app', t, 'DELETE'),
                       'sk_app : SELECT, INSERT, UPDATE sans DELETE sur ' || t);
    END LOOP;

    -- ===== sk_reporting : vues seulement ============================================
    FOREACH t IN ARRAY ARRAY['utilisateur','cle_licence','commande','paiement','offre','jeu','journal_audit','avis'] LOOP
        PERFORM pg_temp.rejette_en_tant_que('sk_reporting', 'SELECT * FROM ' || t, '42501', 'sk_reporting : lecture refusée sur la table ' || t);
    END LOOP;
    PERFORM pg_temp.rejette_en_tant_que('sk_reporting', 'INSERT INTO langue (code, libelle) VALUES (''de'', ''x'')', '42501', 'sk_reporting : écriture refusée');

    SELECT count(*) INTO n FROM information_schema.columns
     WHERE table_schema = 'public' AND table_name IN ('v_ventes_jour', 'v_stock_disponible')
       AND column_name ~* '(email|mot_de_passe|hash|prenom|nom$|ip_|cle_chiffree|empreinte|totp)';
    PERFORM pg_temp.ok(n = 0, 'les vues de reporting n''exposent aucune donnée personnelle ni clé');
    PERFORM pg_temp.ok((SELECT count(*) FROM pg_views WHERE viewname IN ('v_ventes_jour','v_stock_disponible')
                         AND definition ~* '(utilisateur|empreinte|cle_chiffree)') = 0,
                       'les vues ne lisent ni utilisateur ni le contenu des clés');

    -- Les vues donnent les bons chiffres, lues avec sk_reporting
    j := pg_temp.mk_jeu();
    o := pg_temp.mk_offre(j);
    u := pg_temp.mk_user();
    INSERT INTO commande (statut, montant_total, payee_le, expire_le, utilisateur_id, langue_code)
    VALUES ('PAYEE', 59.98, now(), now() + interval '15 minutes', u, 'fr') RETURNING id INTO c;
    INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (2, 29.99, c, o) RETURNING id INTO l;
    PERFORM pg_temp.mk_cle(o, 'VENDUE', l);
    PERFORM pg_temp.mk_cle(o, 'VENDUE', l);
    PERFORM pg_temp.mk_cle(o);
    SET LOCAL ROLE sk_reporting;
    SELECT cles_vendues, chiffre_affaires_ttc INTO ventes, ca FROM v_ventes_jour WHERE jeu_id = j;
    SELECT cles_disponibles, stock_bas INTO dispo, bas FROM v_stock_disponible WHERE offre_id = o;
    RESET ROLE;
    PERFORM pg_temp.ok(ventes = 2 AND ca = 59.98, 'v_ventes_jour : 2 clés vendues pour 59,98 € TTC');
    PERFORM pg_temp.ok(dispo = 1 AND bas, 'v_stock_disponible : 1 clé disponible, alerte de stock bas (seuil 5)');
    -- une commande non payée ne compte pas dans les ventes
    PERFORM pg_temp.mk_ligne(pg_temp.mk_commande(), o);
    PERFORM pg_temp.ok((SELECT sum(cles_vendues) FROM v_ventes_jour WHERE jeu_id = j) = 2, 'les commandes non payées ne comptent pas dans les ventes');

    -- ===== sk_backup : lecture seule sur tout ========================================
    PERFORM pg_temp.ok(pg_has_role('sk_backup', 'pg_read_all_data', 'MEMBER'), 'sk_backup est membre de pg_read_all_data');
    PERFORM pg_temp.ok(has_table_privilege('sk_backup', 'cle_licence', 'SELECT'), 'sk_backup lit toutes les tables');
    PERFORM pg_temp.rejette_en_tant_que('sk_backup', 'INSERT INTO langue (code, libelle) VALUES (''de'', ''x'')', '42501', 'sk_backup : écriture refusée');
    PERFORM pg_temp.rejette_en_tant_que('sk_backup', 'DELETE FROM cle_licence', '42501', 'sk_backup : suppression refusée');
END
$$;

ROLLBACK;

-- Test des contraintes (T-01, point 2) : chaque règle d'intégrité refuse la donnée invalide ET accepte la valide.
-- SQLSTATE : 23505 unicité · 23514 CHECK.
BEGIN;
\ir _helpers.sql

DO $$
DECLARE
    v      uuid;
    o      uuid;
    u      uuid;
    c      uuid;
    j      uuid;
    n      integer;
BEGIN
    -- ===== Unicités ==========================================================
    -- RG-03 : une clé ne peut pas entrer deux fois (empreinte unique)
    o := pg_temp.mk_offre();
    v := pg_temp.mk_cle(o);
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO cle_licence (cle_chiffree, empreinte, offre_id)
                  SELECT '\x01'::bytea, empreinte, offre_id FROM cle_licence WHERE id = %L$q$, v),
        '23505', 'RG-03 : empreinte de clé en double refusée (uq_cle_empreinte)');
    PERFORM pg_temp.ok(pg_temp.mk_cle(o) IS NOT NULL, 'RG-03 : une autre clé de la même offre est acceptée');

    -- e-mail unique, insensible à la casse
    INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom)
    VALUES ('lea@exemple.fr', 'h', 'Léa', 'Martin');
    PERFORM pg_temp.rejette(
        $q$INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom) VALUES ('LEA@Exemple.FR', 'h', 'L', 'M')$q$,
        '23505', 'e-mail en double refusé, quelle que soit la casse (uq_utilisateur_email)');

    -- RG-01 : une seule offre ACTIVE par couple jeu / plateforme, l'historique reste possible
    j := pg_temp.mk_jeu();
    INSERT INTO offre (prix_ttc, jeu_id, plateforme_id) SELECT 10, j, id FROM plateforme WHERE code = 'STEAM';
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO offre (prix_ttc, jeu_id, plateforme_id)
                  SELECT 12, %L, id FROM plateforme WHERE code = 'STEAM'$q$, j),
        '23505', 'RG-01 : deuxième offre active du même couple refusée (uq_offre_active)');
    INSERT INTO offre (prix_ttc, actif, jeu_id, plateforme_id) SELECT 8, false, j, id FROM plateforme WHERE code = 'STEAM';
    PERFORM pg_temp.ok(true, 'RG-01 : une offre désactivée du même couple est acceptée (historique)');

    PERFORM pg_temp.rejette($q$INSERT INTO plateforme (code, libelle) VALUES ('STEAM', 'Doublon')$q$, '23505', 'plateforme : code unique');
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO editeur (nom) SELECT nom FROM editeur WHERE id = %L$q$, (SELECT editeur_id FROM jeu WHERE id = j)),
        '23505', 'éditeur : nom unique');
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO jeu (titre, slug, pegi, editeur_id) SELECT 'Autre', slug, 12, editeur_id FROM jeu WHERE id = %L$q$, j),
        '23505', 'jeu : slug unique');

    -- une seule ligne par offre dans une commande ; un seul débit par clé d'idempotence ; un avis par client et jeu
    c := pg_temp.mk_commande();
    PERFORM pg_temp.mk_ligne(c, o);
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (1, 10, %L, %L)$q$, c, o),
        '23505', 'même offre deux fois dans une commande refusée (uq_ligne_offre)');

    INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (29.99, 'ACCEPTE', 'idem-1', c);
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (29.99, 'ACCEPTE', 'idem-1', %L)$q$, c),
        '23505', 'US-32 : clé d''idempotence réutilisée refusée (uq_paiement_idempotence)');

    -- F-07 : un seul paiement en cours ou accepté par commande (le prestataire est appelé hors transaction) ;
    -- un paiement refusé ou en erreur ne bloque pas un nouvel essai (US-36)
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (29.99, 'EN_ATTENTE', 'idem-2', %L)$q$, c),
        '23505', 'F-07 : second paiement actif sur la même commande refusé (uq_paiement_commande_actif)');
    c := pg_temp.mk_commande();
    INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'REFUSE', 'idem-r1', c);
    INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'ERREUR', 'idem-r2', c);
    INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'EN_ATTENTE', 'idem-r3', c);
    PERFORM pg_temp.ok(true, 'F-07 : des essais refusés puis un paiement en cours sont acceptés sur la même commande');
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'ACCEPTE', 'idem-r4', %L)$q$, c),
        '23505', 'F-07 : un paiement ACCEPTE ne peut pas coexister avec un paiement EN_ATTENTE de la même commande');
    c := pg_temp.mk_commande();
    PERFORM pg_temp.mk_ligne(c, o);

    u := pg_temp.mk_user();
    INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code) VALUES (5, 'Très bon jeu, clé reçue tout de suite.', u, j, 'fr');
    PERFORM pg_temp.rejette(
        format($q$INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code)
                  VALUES (4, 'Un deuxième avis du même client.', %L, %L, 'fr')$q$, u, j),
        '23505', 'RG-31 : un seul avis par client et par jeu');

    -- ===== CHECK : clés de licence ===============================================
    PERFORM pg_temp.rejette(format($q$INSERT INTO cle_licence (cle_chiffree, empreinte, statut, offre_id)
            VALUES ('\x01', sha256('a'), 'PERDUE', %L)$q$, o), '23514', 'RG-04 : statut de clé inconnu refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO cle_licence (cle_chiffree, empreinte, statut, offre_id)
            VALUES ('\x01', sha256('b'), 'VENDUE', %L)$q$, o), '23514', 'une clé VENDUE sans ligne de commande est refusée (ck_cle_vendue_ligne)');
    PERFORM pg_temp.ok(pg_temp.mk_cle(o, 'VENDUE', (SELECT id FROM ligne_commande WHERE commande_id = c)) IS NOT NULL,
                       'une clé VENDUE rattachée à une ligne est acceptée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO cle_licence (cle_chiffree, empreinte, offre_id)
            VALUES ('\x01', '\x00', %L)$q$, o), '23514', 'empreinte qui n''est pas un HMAC-SHA256 (32 octets) refusée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO cle_licence (cle_chiffree, empreinte, offre_id)
            VALUES ('\x', sha256('c'), %L)$q$, o), '23514', 'clé chiffrée vide refusée');

    -- ===== CHECK : catalogue ======================================================
    PERFORM pg_temp.rejette(format($q$INSERT INTO jeu (titre, slug, pegi, editeur_id) VALUES ('X', 'x-pegi', 5, %L)$q$,
            (SELECT editeur_id FROM jeu WHERE id = j)), '23514', 'PEGI 5 refusé (3, 7, 12, 16 ou 18 seulement)');
    PERFORM pg_temp.rejette($q$INSERT INTO plateforme (code, libelle) VALUES ('steam 2', 'X')$q$, '23514', 'code de plateforme en majuscules sans espace');
    PERFORM pg_temp.rejette($q$INSERT INTO role (code, libelle) VALUES ('SUPERUSER', 'X')$q$, '23514', 'rôle inconnu refusé');
    PERFORM pg_temp.rejette($q$INSERT INTO langue (code, libelle) VALUES ('FR', 'X')$q$, '23514', 'code de langue en minuscules sur 2 lettres');
    PERFORM pg_temp.rejette(format($q$INSERT INTO jeu_traduction (jeu_id, langue_code, description) VALUES (%L, 'fr', 'court')$q$, j),
            '23514', 'description de moins de 10 caractères refusée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO jeu_traduction (jeu_id, langue_code, description) VALUES (%L, 'fr', repeat('x', 4001))$q$, j),
            '23514', 'description de plus de 4 000 caractères refusée');
    FOREACH n IN ARRAY ARRAY[3, 7, 16, 18] LOOP
        INSERT INTO jeu (titre, slug, pegi, editeur_id)
        VALUES ('PEGI ' || n, 'pegi-' || n || '-' || gen_random_uuid(), n, (SELECT editeur_id FROM jeu WHERE id = j));
    END LOOP;
    PERFORM pg_temp.ok(true, 'PEGI 3, 7, 12, 16 et 18 acceptés');

    PERFORM pg_temp.rejette(format($q$INSERT INTO offre (prix_ttc, jeu_id, plateforme_id)
            SELECT 0, %L, id FROM plateforme WHERE code = 'PSN'$q$, j), '23514', 'prix nul refusé (ck_offre_prix)');
    PERFORM pg_temp.rejette(format($q$INSERT INTO offre (prix_ttc, jeu_id, plateforme_id)
            SELECT -5, %L, id FROM plateforme WHERE code = 'PSN'$q$, j), '23514', 'prix négatif refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO offre (prix_ttc, seuil_alerte, jeu_id, plateforme_id)
            SELECT 5, -1, %L, id FROM plateforme WHERE code = 'PSN'$q$, j), '23514', 'seuil d''alerte négatif refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO offre (prix_ttc, version, jeu_id, plateforme_id)
            SELECT 5, 0, %L, id FROM plateforme WHERE code = 'PSN'$q$, j), '23514', 'version d''offre inférieure à 1 refusée');

    -- ===== CHECK : comptes ==========================================================
    PERFORM pg_temp.rejette($q$INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, a2f_active)
            VALUES ('a2f@exemple.fr', 'h', 'A', 'B', true)$q$, '23514', 'A2F activée sans secret TOTP refusée (ck_utilisateur_a2f)');
    INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, a2f_active, totp_secret_chiffre)
    VALUES ('a2f-ok@exemple.fr', 'h', 'A', 'B', true, '\x0102');
    PERFORM pg_temp.ok(true, 'A2F activée avec secret TOTP acceptée');
    PERFORM pg_temp.rejette($q$INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom)
            VALUES ('pas-un-email', 'h', 'A', 'B')$q$, '23514', 'e-mail sans @ refusé');

    -- ===== CHECK : commandes ==========================================================
    u := pg_temp.mk_user();
    PERFORM pg_temp.rejette(format($q$INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code)
            VALUES ('BRICOLEE', 10, now() + interval '15 minutes', %L, 'fr')$q$, u), '23514', 'statut de commande inconnu refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code)
            VALUES ('PAYEE', -1, now() + interval '15 minutes', %L, 'fr')$q$, u), '23514', 'montant négatif refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code)
            VALUES ('PAYEE', 10, now() - interval '1 minute', %L, 'fr')$q$, u), '23514', 'RG-12 : expiration antérieure à la création refusée');
    PERFORM pg_temp.mk_commande(u, 'A_VERIFIER');
    PERFORM pg_temp.mk_commande(u, 'ANNULEE');
    PERFORM pg_temp.mk_commande(u, 'EXPIREE');
    PERFORM pg_temp.mk_commande(u, 'REMBOURSEE');
    PERFORM pg_temp.mk_commande(u, 'PAYEE');
    PERFORM pg_temp.ok(true, 'les 6 statuts de commande du §6.4 sont acceptés');

    c := pg_temp.mk_commande();
    PERFORM pg_temp.rejette(format($q$INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (0, 10, %L, %L)$q$, c, pg_temp.mk_offre()),
            '23514', 'quantité 0 refusée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (11, 10, %L, %L)$q$, c, pg_temp.mk_offre()),
            '23514', 'quantité 11 refusée (maximum 10)');
    PERFORM pg_temp.rejette(format($q$INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (1, 0, %L, %L)$q$, c, pg_temp.mk_offre()),
            '23514', 'prix unitaire nul refusé');
    INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (10, 10, c, pg_temp.mk_offre());
    PERFORM pg_temp.ok(true, 'quantité 10 acceptée (borne haute)');

    PERFORM pg_temp.rejette(format($q$INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (0, 'ACCEPTE', 'idem-zero', %L)$q$, c),
            '23514', 'paiement de 0 € refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO paiement (montant, statut, cle_idempotence, commande_id) VALUES (10, 'REUSSI', 'idem-statut', %L)$q$, c),
            '23514', 'statut de paiement inconnu refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO paiement (montant, statut, cle_idempotence, derniers_chiffres, commande_id)
            VALUES (10, 'ACCEPTE', 'idem-carte', '4242 4242 4242 4242', %L)$q$, c),
            '22001', 'numéro de carte complet refusé (char(4) : 4 chiffres seulement)');
    PERFORM pg_temp.rejette(format($q$INSERT INTO paiement (montant, statut, cle_idempotence, derniers_chiffres, commande_id)
            VALUES (10, 'ACCEPTE', 'idem-lettres', 'ab12', %L)$q$, c), '23514', 'derniers chiffres non numériques refusés (ck_paiement_carte)');
    INSERT INTO paiement (montant, statut, cle_idempotence, derniers_chiffres, commande_id) VALUES (10, 'ACCEPTE', 'idem-ok', '4242', c);
    PERFORM pg_temp.ok(true, 'paiement avec 4 derniers chiffres accepté');

    PERFORM pg_temp.rejette(format($q$INSERT INTO commande_historique (nouveau_statut, commande_id) VALUES ('INCONNU', %L)$q$, c),
            '23514', 'historique : statut inconnu refusé');
    INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id) VALUES (NULL, 'EN_ATTENTE_PAIEMENT', c);
    INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id) VALUES ('EN_ATTENTE_PAIEMENT', 'PAYEE', c);
    PERFORM pg_temp.ok(true, 'historique : création (ancien statut NULL) puis transition acceptées');

    -- ===== CHECK : avis ===============================================================
    u := pg_temp.mk_user();
    j := pg_temp.mk_jeu();
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code)
            VALUES (6, 'Une note hors limites.', %L, %L, 'fr')$q$, u, j), '23514', 'RG-30 : note 6 refusée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code)
            VALUES (0, 'Une note hors limites.', %L, %L, 'fr')$q$, u, j), '23514', 'RG-30 : note 0 refusée');
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code)
            VALUES (3, 'Trop cour', %L, %L, 'fr')$q$, u, j), '23514', 'RG-30 : commentaire de 9 caractères refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, utilisateur_id, jeu_id, langue_code)
            VALUES (3, repeat('x', 1001), %L, %L, 'fr')$q$, u, j), '23514', 'RG-30 : commentaire de 1 001 caractères refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, statut_moderation, utilisateur_id, jeu_id, langue_code)
            VALUES (3, 'Un commentaire correct.', 'RENVOYE', %L, %L, 'fr')$q$, u, j), '23514', 'statut de modération inconnu refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO avis (note, commentaire, statut_moderation, utilisateur_id, jeu_id, langue_code)
            VALUES (1, 'Un commentaire correct.', 'REFUSE', %L, %L, 'fr')$q$, u, j), '23514', 'US-42 : refus sans motif refusé (ck_avis_motif)');
    INSERT INTO avis (note, commentaire, statut_moderation, motif_refus, utilisateur_id, jeu_id, langue_code)
    VALUES (1, repeat('é', 1000), 'REFUSE', 'Propos injurieux', u, j, 'fr');
    PERFORM pg_temp.ok(true, 'avis refusé avec motif et commentaire de 1 000 caractères accentués acceptés');

    -- ===== CHECK : import et journal ====================================================
    u := pg_temp.mk_user();
    PERFORM pg_temp.rejette(format($q$INSERT INTO import_cles (fichier_nom, statut, utilisateur_id) VALUES ('a.csv', 'PERDU', %L)$q$, u),
            '23514', 'statut d''import inconnu refusé');
    PERFORM pg_temp.rejette(format($q$INSERT INTO import_cles (fichier_nom, nb_lignes, nb_acceptees, nb_doublons, statut, utilisateur_id)
            VALUES ('a.csv', 10, 8, 5, 'TERMINE', %L)$q$, u), '23514', 'rapport d''import incohérent (13 traitées sur 10 lignes) refusé');
    INSERT INTO import_cles (fichier_nom, nb_lignes, nb_acceptees, nb_doublons, nb_erreurs, statut, utilisateur_id)
    VALUES ('a.csv', 10, 8, 1, 1, 'TERMINE', u);
    PERFORM pg_temp.ok(true, 'rapport d''import cohérent accepté');
    PERFORM pg_temp.rejette($q$INSERT INTO journal_audit (action, ressource, ip_hachee) VALUES ('LOGIN_ECHEC', 'utilisateur', '\x7f000001')$q$,
            '23514', 'adresse IP en clair (4 octets) refusée : seul un HMAC de 32 octets est accepté');
    INSERT INTO journal_audit (action, ressource, ip_hachee) VALUES ('LOGIN_ECHEC', 'utilisateur', sha256('127.0.0.1'));
    PERFORM pg_temp.ok(true, 'journal : action anonyme avec IP hachée acceptée');

    -- ===== Index =========================================================================
    SELECT count(*) INTO n FROM pg_indexes
     WHERE schemaname = 'public' AND indexname IN ('ix_cle_stock', 'ix_commande_client_statut', 'ix_audit_ressource', 'uq_offre_active', 'uq_utilisateur_email');
    PERFORM pg_temp.ok(n = 5, 'les 5 index documentés existent');
    PERFORM pg_temp.ok((SELECT indexdef FROM pg_indexes WHERE indexname = 'ix_cle_stock') LIKE '%WHERE%DISPONIBLE%',
                       'ix_cle_stock est partiel (clés DISPONIBLES, index du FOR UPDATE SKIP LOCKED)');
    SELECT count(*) INTO n FROM pg_indexes WHERE schemaname = 'public' AND indexname !~ '^(pk|uq|ix)_';
    PERFORM pg_temp.ok(n = 0, 'index préfixés pk_ / uq_ / ix_');
END
$$;

ROLLBACK;

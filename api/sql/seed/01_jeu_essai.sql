-- Jeu d'essai SideKey (base sidekey_test, développement, CI). NE JAMAIS charger en production.
-- Données fictives : titres, personnes, clés et empreintes sont inventés (les « clés » sont de faux octets,
-- pas du chiffrement réel). Mot de passe de TOUS les comptes : SideKey-Dev-2026!  (hash bcrypt coût 12).
-- Contenu : 8 éditeurs, 8 genres, 40 jeux FR/EN, 60 offres, 500 clés, 20 utilisateurs, 18 commandes
-- (les 6 statuts), 8 codes de secours, 25 avis (3 états), journal d'audit, historique des commandes, imports.
-- Cas limites : dernière clé disponible, offre en rupture, compte désactivé, e-mail non vérifié, compte de
-- moins de 24 h, A2F active, commande expirée / annulée / remboursée, accents, apostrophes, avis de 1000 car.
DO $seed$
DECLARE
    mdp        constant text := '$2y$12$d0LeBSaWsGdBWYDKahQfae9phpVRYJ8Kn23e22XEjF/ffpJeyD/tG';
    langues    constant text[] := ARRAY['fr', 'en'];
    editeurs   uuid[] := '{}';
    genres     uuid[] := '{}';
    plateformes uuid[];
    jeux       uuid[] := '{}';
    offres     uuid[] := '{}';
    prix       numeric[] := '{}';
    users      uuid[] := '{}';
    imp        uuid;
    imp2       uuid;
    id_       uuid;
    cmd        uuid;
    ligne      uuid;
    i          integer;
    j          integer;
    seq        integer := 0;
    nb_cles    integer;
    statut_c   text;
    statut_k   text;
    nb_lignes  integer;
    off_rank   integer;
    qte        integer;
    total      numeric;
    cree       timestamptz;
    noms_genre_fr constant text[] := ARRAY['Action', 'Aventure', 'RPG', 'Stratégie', 'Simulation', 'Course', 'Plateforme', 'Réflexion'];
    noms_genre_en constant text[] := ARRAY['Action', 'Adventure', 'RPG', 'Strategy', 'Simulation', 'Racing', 'Platformer', 'Puzzle'];
    slugs_genre   constant text[] := ARRAY['action', 'aventure', 'rpg', 'strategie', 'simulation', 'course', 'plateforme', 'reflexion'];
    titres constant text[] := ARRAY[
        'Aube d''Écarlate', 'L''Odyssée d''Aëlys', 'Cœur de Forge', 'Les Sentiers d''Orée', 'Néon Mirage',
        'Citadelle des Brumes', 'Rêves d''Acier', 'Le Dernier Phare', 'Zéphyr & Cendres', 'Hôtel Étoilé',
        'Quête du Héron Bleu', 'Tempête d''Ambre', 'Mécanique Céleste', 'Vallée des Échos', 'Pixel Samouraï',
        'Ombres de l''Atelier', 'Grand Prix Solaire', 'Archipel Perdu', 'Le Cartographe Distrait', 'Fureur Tellurique',
        'Étincelle', 'Marées Noires', 'Légendes d''Argile', 'Le Jardin Suspendu', 'Cap sur l''Inconnu',
        'Forge-Monde', 'Chasseurs d''Éclipses', 'Ludovic et la Lune', 'Brigade des Étoiles', 'Dédale',
        'Rivages d''Opale', 'Poussière d''Empire', 'Allô Maman Bobo', 'Le Bal des Ombres', 'Turbo Nénuphar',
        'Cités Sous-Marines', 'Haute Tension', 'Mots Croisés Galactiques', 'L''Alchimiste Pressé', 'Super Cabane Heroes'];
    desc_fr constant text := 'Une aventure fictive pour le jeu d''essai SideKey : exploration, énigmes et rencontres inattendues.';
    desc_en constant text := 'A fictional adventure for the SideKey test data set: exploration, puzzles and unexpected encounters.';
    -- avis
    textes_fr constant text[] := ARRAY['Un jeu superbe, j''y ai passé des heures.', 'Correct mais un peu répétitif à la longue.',
        'Livraison de la clé instantanée, très satisfait !', 'Les graphismes sont magnifiques, l''histoire me laisse perplexe.',
        'À éviter : trop de bugs au lancement.', 'Excellent rapport qualité-prix, je recommande.'];
    long_avis text;
BEGIN
    SELECT array_agg(id ORDER BY code) INTO plateformes FROM plateforme;  -- ESHOP, PSN, STEAM, XBOX

    -- ===== Éditeurs, genres ======================================================
    FOR i IN 1..8 LOOP
        INSERT INTO editeur (nom) VALUES ((ARRAY['Studio Lumière', 'Arc-en-Ciel Games', 'Maison Éclair', 'Nordvik Interactive',
            'Les Ateliers du Delta', 'Pixelforge', 'Chat Noir Productions', 'Kodama & Fils'])[i]) RETURNING id INTO id_;
        editeurs := editeurs || id_;
        INSERT INTO genre (slug) VALUES (slugs_genre[i]) RETURNING id INTO id_;
        genres := genres || id_;
        INSERT INTO genre_traduction VALUES (id_, 'fr', noms_genre_fr[i]), (id_, 'en', noms_genre_en[i]);
    END LOOP;

    -- ===== Jeux (40, FR/EN), offres (60) =========================================
    FOR i IN 1..40 LOOP
        INSERT INTO jeu (titre, slug, date_sortie, pegi, jaquette, actif, editeur_id)
        VALUES (titres[i], 'jeu-essai-' || lpad(i::text, 2, '0'), date '2020-01-15' + (i * 37),
                (ARRAY[3, 7, 12, 16, 18])[1 + (i % 5)], 'jaquettes/jeu-' || lpad(i::text, 2, '0') || '.webp',
                i <> 40,                              -- le jeu n° 40 est masqué du catalogue
                editeurs[1 + (i % 8)])
        RETURNING id INTO id_;
        jeux := jeux || id_;
        INSERT INTO jeu_traduction VALUES (id_, 'fr', desc_fr || ' (n° ' || i || ')'), (id_, 'en', desc_en || ' (no. ' || i || ')');
        INSERT INTO jeu_genre VALUES (id_, genres[1 + (i % 8)]), (id_, genres[1 + ((i + 3) % 8)]);
    END LOOP;
    -- offres 1..40 : Steam ; 41..55 : PlayStation ; 56..60 : Xbox. Le rang d'une offre sert aux commandes et aux clés.
    FOR i IN 1..60 LOOP
        IF i <= 40 THEN j := i; ELSIF i <= 55 THEN j := i - 40; ELSE j := i - 55; END IF;
        INSERT INTO offre (prix_ttc, seuil_alerte, jeu_id, plateforme_id)
        VALUES (round((9.99 + (i % 10) * 5)::numeric, 2), 5,
                jeux[j], CASE WHEN i <= 40 THEN plateformes[3] WHEN i <= 55 THEN plateformes[2] ELSE plateformes[4] END)
        RETURNING id INTO id_;
        offres := offres || id_;
        prix := prix || round((9.99 + (i % 10) * 5)::numeric, 2);
    END LOOP;

    -- ===== Utilisateurs (20) =========================================================
    -- 1 admin (A2F), 2 gestionnaire, 3 modérateur, 4..16 clients, 17 e-mail non vérifié,
    -- 18 compte désactivé, 19 créé il y a 2 h, 20 client avec A2F.
    FOR i IN 1..20 LOOP
        INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, email_verifie, totp_secret_chiffre, a2f_active, actif,
                                 derniere_connexion, langue_code, cree_le)
        VALUES (
            CASE i WHEN 1 THEN 'admin@sidekey.test' WHEN 2 THEN 'gestionnaire@sidekey.test' WHEN 3 THEN 'moderateur@sidekey.test'
                   ELSE 'client' || lpad((i - 3)::text, 2, '0') || '@sidekey.test' END,
            mdp,
            (ARRAY['Camille', 'Noémie', 'Lucas', 'Inès', 'Hugo', 'Zoé', 'Étienne', 'Léa', 'Mathis', 'Chloé',
                   'Yann', 'Éloïse', 'Raphaël', 'Manon', 'Théo', 'Océane', 'Jean-Pierre', 'Amélie', 'Nolan', 'Sofia'])[i],
            (ARRAY['Martin', 'Dubois', 'O''Brien', 'Lefèvre', 'Garcia', 'Müller', 'Roux', 'Nguyen', 'Bernard', 'Petit',
                   'Durand', 'Moreau', 'Simon', 'Laurent', 'Michel', 'Girard', 'Colin', 'Marchand', 'Perrin', 'Rossi'])[i],
            i NOT IN (17, 19),
            CASE WHEN i IN (1, 20) THEN '\xAABBCCDDEEFF00112233445566778899'::bytea END,   -- faux secret TOTP chiffré
            i IN (1, 20),
            i <> 18,
            CASE WHEN i <> 19 THEN now() - (i || ' hours')::interval END,
            CASE WHEN i % 4 = 0 THEN 'en' ELSE 'fr' END,
            CASE WHEN i = 19 THEN now() - interval '2 hours' ELSE now() - ((60 - i) || ' days')::interval END)
        RETURNING id INTO id_;
        users := users || id_;
        INSERT INTO utilisateur_role (utilisateur_id, role_code)
        VALUES (id_, CASE i WHEN 1 THEN 'ADMIN' WHEN 2 THEN 'GESTIONNAIRE' WHEN 3 THEN 'MODERATEUR' ELSE 'CLIENT' END);
    END LOOP;
    FOREACH i IN ARRAY ARRAY[1, 20] LOOP
        FOR j IN 1..4 LOOP
            INSERT INTO code_secours (code_hash, utilise_le, utilisateur_id)
            VALUES (mdp, CASE WHEN j = 1 THEN now() - interval '1 day' END, users[i]);
        END LOOP;
    END LOOP;

    -- ===== Imports de clés (le gestionnaire) ========================================
    INSERT INTO import_cles (fichier_nom, nb_lignes, nb_acceptees, nb_doublons, nb_erreurs, statut, utilisateur_id, cree_le)
    VALUES ('cles-lot-1.csv', 520, 500, 12, 8, 'TERMINE', users[2], now() - interval '30 days') RETURNING id INTO imp;
    INSERT INTO import_cles (fichier_nom, nb_lignes, nb_acceptees, nb_doublons, nb_erreurs, statut, utilisateur_id, cree_le)
    VALUES ('cles-lot-2-éàç.csv', 0, 0, 0, 0, 'ECHEC', users[2], now() - interval '29 days') RETURNING id INTO imp2;

    -- ===== Commandes (18, les 6 statuts) =============================================
    -- n 1..8 PAYEE, 9..11 EN_ATTENTE_PAIEMENT, 12..13 A_VERIFIER, 14..15 EXPIREE, 16..17 ANNULEE, 18 REMBOURSEE
    FOR i IN 1..18 LOOP
        statut_c := CASE WHEN i <= 8 THEN 'PAYEE' WHEN i <= 11 THEN 'EN_ATTENTE_PAIEMENT' WHEN i <= 13 THEN 'A_VERIFIER'
                         WHEN i <= 15 THEN 'EXPIREE' WHEN i <= 17 THEN 'ANNULEE' ELSE 'REMBOURSEE' END;
        cree := CASE WHEN statut_c IN ('EN_ATTENTE_PAIEMENT', 'A_VERIFIER') THEN now() - interval '5 minutes'
                     ELSE now() - ((i % 9 + 1) || ' days')::interval END;
        nb_lignes := CASE WHEN i IN (2, 5) THEN 2 ELSE 1 END;
        INSERT INTO commande (statut, montant_total, confirme_majeur, payee_le, expire_le, utilisateur_id, langue_code, cree_le)
        VALUES (statut_c, 0, true,
                CASE WHEN statut_c IN ('PAYEE', 'REMBOURSEE') THEN cree + interval '3 minutes' END,
                cree + interval '15 minutes', users[4 + ((i - 1) % 13)], CASE WHEN i % 4 = 0 THEN 'en' ELSE 'fr' END, cree)
        RETURNING id INTO cmd;
        total := 0;
        FOR j IN 1..nb_lignes LOOP
            off_rank := ((i - 1) % 20) + j;                       -- 1..21 : offres Steam, toujours distinctes dans la commande
            qte := 1 + CASE WHEN i % 3 = 0 AND j = 1 THEN 1 ELSE 0 END;
            INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id)
            VALUES (qte, prix[off_rank], cmd, offres[off_rank]) RETURNING id INTO ligne;
            total := total + qte * prix[off_rank];
            -- clés : vendues (commande payée ou remboursée), réservées (en attente / à vérifier), sinon libérées (non créées ici)
            statut_k := CASE WHEN statut_c IN ('PAYEE', 'REMBOURSEE') THEN 'VENDUE'
                             WHEN statut_c IN ('EN_ATTENTE_PAIEMENT', 'A_VERIFIER') THEN 'RESERVEE' END;
            IF statut_k IS NOT NULL THEN
                FOR nb_cles IN 1..qte LOOP
                    seq := seq + 1;
                    INSERT INTO cle_licence (cle_chiffree, empreinte, statut, importee_le, reservee_le, revelee_le, offre_id, ligne_commande_id, import_cles_id)
                    VALUES ('\xFA4E00'::bytea || sha256(convert_to('faux-chiffre-' || seq, 'UTF8')),
                            sha256(convert_to('sidekey-essai-cle-' || seq, 'UTF8')), statut_k, now() - interval '30 days',
                            cree, CASE WHEN statut_k = 'VENDUE' THEN cree + interval '4 minutes' END,
                            offres[off_rank], ligne, imp);
                END LOOP;
            END IF;
        END LOOP;
        UPDATE commande SET montant_total = total WHERE id = cmd;

        -- paiement
        IF statut_c IN ('PAYEE', 'REMBOURSEE') THEN
            INSERT INTO paiement (montant, statut, cle_idempotence, code_retour, derniers_chiffres, commande_id, cree_le)
            VALUES (total, 'ACCEPTE', 'essai-pay-' || i, '00', '4242', cmd, cree + interval '3 minutes');
        ELSIF statut_c = 'A_VERIFIER' THEN
            INSERT INTO paiement (montant, statut, cle_idempotence, code_retour, derniers_chiffres, commande_id, cree_le)
            VALUES (total, 'ERREUR', 'essai-pay-' || i, 'TIMEOUT', '1881', cmd, cree + interval '3 minutes');
        ELSIF i = 9 THEN   -- une commande en attente avec un premier paiement refusé
            INSERT INTO paiement (montant, statut, cle_idempotence, code_retour, derniers_chiffres, commande_id, cree_le)
            VALUES (total, 'REFUSE', 'essai-pay-' || i, '51', '0002', cmd, cree + interval '2 minutes');
        END IF;

        -- historique des changements de statut
        INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id, auteur_id, cree_le)
        VALUES (NULL, 'EN_ATTENTE_PAIEMENT', cmd, users[4 + ((i - 1) % 13)], cree);
        IF statut_c <> 'EN_ATTENTE_PAIEMENT' THEN
            IF statut_c = 'REMBOURSEE' THEN
                INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id, cree_le)
                VALUES ('EN_ATTENTE_PAIEMENT', 'PAYEE', cmd, cree + interval '3 minutes'),
                       ('PAYEE', 'REMBOURSEE', cmd, cree + interval '1 day');
                UPDATE commande SET modifie_le = cree + interval '1 day' WHERE id = cmd;
            ELSE
                INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id, auteur_id, cree_le)
                VALUES ('EN_ATTENTE_PAIEMENT', statut_c, cmd, CASE WHEN statut_c = 'ANNULEE' THEN users[4 + ((i - 1) % 13)] END,
                        cree + CASE WHEN statut_c = 'EXPIREE' THEN interval '15 minutes' ELSE interval '3 minutes' END);
            END IF;
        END IF;
    END LOOP;

    -- ===== Clés disponibles : compléter jusqu'à 500 ====================================
    -- Offre 59 : exactement UNE clé (dernière clé) ; offre 60 : aucune (rupture) ; offres 1..58 : le reste, réparti.
    seq := seq + 1;
    INSERT INTO cle_licence (cle_chiffree, empreinte, importee_le, offre_id, import_cles_id)
    VALUES ('\xFA4E00'::bytea || sha256(convert_to('faux-chiffre-' || seq, 'UTF8')),
            sha256(convert_to('sidekey-essai-cle-' || seq, 'UTF8')), now() - interval '29 days', offres[59], imp);
    SELECT count(*) INTO nb_cles FROM cle_licence;
    WHILE nb_cles < 500 LOOP
        seq := seq + 1;
        nb_cles := nb_cles + 1;
        INSERT INTO cle_licence (cle_chiffree, empreinte, importee_le, offre_id, import_cles_id)
        VALUES ('\xFA4E00'::bytea || sha256(convert_to('faux-chiffre-' || seq, 'UTF8')),
                sha256(convert_to('sidekey-essai-cle-' || seq, 'UTF8')),
                now() - interval '29 days' + (seq || ' minutes')::interval, offres[1 + (seq % 58)], imp);
    END LOOP;

    -- ===== Avis (25) : 15 publiés, 6 en attente, 4 refusés =============================
    long_avis := repeat('Très bon jeu, l''histoire est prenante et la bande-son éblouissante. ', 20);
    long_avis := left(long_avis, 1000);                     -- longueur maximale autorisée
    FOR i IN 1..25 LOOP
        INSERT INTO avis (note, commentaire, statut_moderation, motif_refus, utilisateur_id, jeu_id, langue_code, cree_le)
        VALUES (1 + (i % 5),
                CASE WHEN i = 1 THEN long_avis ELSE textes_fr[1 + (i % 6)] END,
                CASE WHEN i <= 15 THEN 'PUBLIE' WHEN i <= 21 THEN 'EN_ATTENTE' ELSE 'REFUSE' END,
                CASE WHEN i > 21 THEN 'Propos hors sujet ou injurieux' END,
                users[4 + ((i - 1) % 13)], jeux[1 + ((i - 1) % 12) + (CASE WHEN i > 13 THEN 13 ELSE 0 END)],
                CASE WHEN i % 4 = 0 THEN 'en' ELSE 'fr' END, now() - (i || ' days')::interval);
    END LOOP;

    -- ===== Journal d'audit ===========================================================
    FOR i IN 1..30 LOOP
        INSERT INTO journal_audit (action, ressource, ressource_id, ip_hachee, utilisateur_id, cree_le)
        VALUES ((ARRAY['CONNEXION_REUSSIE', 'CONNEXION_ECHEC', 'REVELATION_CLE', 'MODERATION_AVIS', 'IMPORT_CLES'])[1 + (i % 5)],
                (ARRAY['utilisateur', 'utilisateur', 'cle_licence', 'avis', 'import_cles'])[1 + (i % 5)],
                CASE i % 5 WHEN 2 THEN NULL WHEN 4 THEN imp ELSE users[1 + (i % 20)] END,
                sha256(convert_to('ip-essai-' || (i % 7), 'UTF8')),
                CASE WHEN i % 5 = 1 THEN NULL ELSE users[1 + (i % 20)] END,   -- échecs de connexion : action anonyme
                now() - (i || ' hours')::interval);
    END LOOP;
END
$seed$;

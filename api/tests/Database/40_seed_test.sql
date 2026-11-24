-- Vérifie le jeu d'essai (sql/seed/01_jeu_essai.sql) : volumes, cas limites, cohérence entre tables.
-- Lecture seule : aucune donnée n'est modifiée.
BEGIN;
\ir _helpers.sql

DO $$
DECLARE
    n integer;
BEGIN
    PERFORM pg_temp.ok((SELECT count(*) FROM jeu) = 40 AND (SELECT count(*) FROM jeu_traduction) = 80, '40 jeux, chacun traduit en FR et EN');
    PERFORM pg_temp.ok((SELECT count(*) FROM offre) = 60, '60 offres');
    PERFORM pg_temp.ok((SELECT count(*) FROM cle_licence) = 500, '500 clés de licence');
    PERFORM pg_temp.ok((SELECT count(*) FROM utilisateur) = 20, '20 utilisateurs');
    PERFORM pg_temp.ok((SELECT count(*) FROM genre_traduction) = 16, '8 genres traduits FR/EN');
    PERFORM pg_temp.ok((SELECT count(DISTINCT statut) FROM commande) = 6, 'les 6 statuts de commande sont représentés');
    PERFORM pg_temp.ok((SELECT count(DISTINCT statut_moderation) FROM avis) = 3 AND (SELECT count(*) FROM avis) = 25, '25 avis dans les 3 états de modération');
    PERFORM pg_temp.ok((SELECT count(DISTINCT statut) FROM cle_licence) = 3, 'clés DISPONIBLE, RESERVEE et VENDUE présentes');
    PERFORM pg_temp.ok((SELECT count(DISTINCT role_code) FROM utilisateur_role) = 4, 'les 4 rôles sont attribués');

    -- cas limites
    PERFORM pg_temp.ok((SELECT count(*) FROM cle_licence WHERE offre_id = (SELECT o.id FROM offre o JOIN plateforme p ON p.id = o.plateforme_id
                          JOIN jeu j ON j.id = o.jeu_id WHERE j.slug = 'jeu-essai-04' AND p.code = 'XBOX') AND statut = 'DISPONIBLE') = 1,
                       'offre avec une seule clé disponible (dernière clé)');
    PERFORM pg_temp.ok((SELECT count(*) FROM offre o WHERE NOT EXISTS (SELECT 1 FROM cle_licence k WHERE k.offre_id = o.id)) = 1, 'une offre en rupture totale');
    PERFORM pg_temp.ok((SELECT count(*) FROM v_stock_disponible WHERE stock_bas) >= 2, 'des offres sont en alerte de stock bas');
    PERFORM pg_temp.ok((SELECT count(*) FROM utilisateur WHERE NOT actif) = 1, 'un compte désactivé');
    PERFORM pg_temp.ok((SELECT count(*) FROM utilisateur WHERE NOT email_verifie) = 2, 'deux comptes à e-mail non vérifié');
    PERFORM pg_temp.ok((SELECT count(*) FROM utilisateur WHERE cree_le > now() - interval '24 hours') = 1, 'un compte créé il y a moins de 24 h');
    PERFORM pg_temp.ok((SELECT count(*) FROM utilisateur WHERE a2f_active) = 2 AND (SELECT count(*) FROM code_secours) = 8, '2 comptes avec A2F et leurs codes de secours');
    PERFORM pg_temp.ok((SELECT count(*) FROM jeu WHERE NOT actif) = 1, 'un jeu masqué du catalogue');
    PERFORM pg_temp.ok((SELECT count(*) FROM jeu WHERE titre ~ '''') > 5 AND (SELECT count(*) FROM jeu WHERE titre ~ '[éèêëàçœ]') > 5, 'titres avec apostrophes et accents');
    PERFORM pg_temp.ok((SELECT max(char_length(commentaire)) FROM avis) = 1000, 'un avis de 1000 caractères (limite)');
    PERFORM pg_temp.ok((SELECT count(*) FROM paiement WHERE statut = 'REFUSE') >= 1 AND (SELECT count(*) FROM paiement WHERE statut = 'ERREUR') >= 1, 'paiements refusé et en erreur');
    PERFORM pg_temp.ok((SELECT count(*) FROM journal_audit WHERE utilisateur_id IS NULL) > 0, 'des actions anonymes dans le journal d''audit');

    -- cohérence
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM commande c WHERE c.montant_total <> (SELECT sum(quantite * prix_unitaire) FROM ligne_commande WHERE commande_id = c.id)),
                       'montant de chaque commande = somme de ses lignes');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM ligne_commande l WHERE l.quantite <>
                         (SELECT count(*) FROM cle_licence k WHERE k.ligne_commande_id = l.id)
                         AND l.commande_id IN (SELECT id FROM commande WHERE statut IN ('PAYEE','REMBOURSEE','EN_ATTENTE_PAIEMENT','A_VERIFIER'))),
                       'une clé par unité commandée pour les commandes payées, remboursées ou réservées');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM cle_licence WHERE statut = 'VENDUE' AND revelee_le IS NULL), 'toute clé vendue a été révélée');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM commande c WHERE statut IN ('PAYEE','REMBOURSEE') AND NOT EXISTS
                         (SELECT 1 FROM paiement p WHERE p.commande_id = c.id AND p.statut = 'ACCEPTE')), 'toute commande payée a un paiement accepté');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM commande c WHERE NOT EXISTS (SELECT 1 FROM commande_historique h WHERE h.commande_id = c.id)), 'toute commande a un historique');
    PERFORM pg_temp.ok(NOT EXISTS (SELECT 1 FROM utilisateur WHERE mot_de_passe_hash NOT LIKE '$2y$12$%'), 'mots de passe stockés en bcrypt (jamais en clair)');
    PERFORM pg_temp.ok((SELECT count(*) FROM v_ventes_jour) > 0, 'la vue de ventes renvoie des lignes');
END
$$;

ROLLBACK;

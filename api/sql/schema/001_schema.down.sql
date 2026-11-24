-- Annulation de 001_schema.sql : supprime toutes les tables, dans l'ordre inverse des dépendances.
DROP TABLE IF EXISTS
    cle_licence, import_cles, journal_audit, avis, commande_historique, paiement,
    ligne_commande, commande, code_secours, utilisateur_role, utilisateur, offre,
    jeu_genre, jeu_traduction, jeu, role, genre_traduction, genre, plateforme,
    editeur, langue
CASCADE;

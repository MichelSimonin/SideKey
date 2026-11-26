-- ============================================================================
-- SideKey — GRANT / REVOKE sur les tables et les vues (CDC §9.5)
--
-- Exécuté APRÈS les migrations, avec le rôle sk_owner (propriétaire des objets).
-- Principe : tout est refusé, on accorde ligne à ligne. Jamais de GRANT ... ALL.
-- Tests : api/tests/Database/03_roles_test.sql
-- ============================================================================

-- Rien par défaut, y compris pour les objets créés plus tard par sk_owner
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC, sk_app, sk_reporting;
ALTER DEFAULT PRIVILEGES FOR ROLE sk_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- ---------------------------------------------------------------------------
-- sk_app : l'API et le worker
-- ---------------------------------------------------------------------------
-- Référentiels : lecture seule (modifiés par migration uniquement). Les plateformes sont gérées par le back-office (F-09) :
-- elles figurent dans la liste « lecture et écriture » ci-dessous ; une plateforme utilisée par une offre ne se supprime pas (clé étrangère RESTRICT).
GRANT SELECT ON langue, role TO sk_app;

-- Données métier : lecture et écriture complètes
GRANT SELECT, INSERT, UPDATE, DELETE ON
    editeur, plateforme, genre, genre_traduction, jeu, jeu_traduction, jeu_genre, offre,
    utilisateur, utilisateur_role, code_secours, ligne_commande, avis, import_cles
TO sk_app;

-- Données sensibles : jamais de DELETE (une clé vendue, une commande, un paiement ne disparaissent pas)
GRANT SELECT, INSERT, UPDATE ON cle_licence, commande, paiement TO sk_app;

-- Traces : ajout seul (le journal et l'historique ne se corrigent pas)
GRANT SELECT, INSERT ON journal_audit, commande_historique TO sk_app;

-- Les fonctions fn_* (T-02) seront exécutables par sk_app et par personne d'autre
ALTER DEFAULT PRIVILEGES FOR ROLE sk_owner GRANT EXECUTE ON FUNCTIONS TO sk_app;

-- ---------------------------------------------------------------------------
-- sk_reporting : uniquement des vues, sans donnée personnelle ni clé
-- ---------------------------------------------------------------------------
GRANT SELECT ON v_ventes_jour, v_stock_disponible TO sk_reporting;

-- sk_backup : pg_read_all_data (accordé dans 01_roles.sql) ; aucun droit d'écriture.
-- sk_owner : propriétaire des objets, donc tous les droits sur eux ; il ne sert qu'aux migrations.

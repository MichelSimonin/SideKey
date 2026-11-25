-- Annulation de 001_triggers.sql (T-02). Les triggers disparaissent avec leur table ;
-- IF EXISTS permet d'annuler avant ou après les tables.
DO $$
BEGIN
    IF to_regclass('public.commande') IS NOT NULL THEN
        DROP TRIGGER IF EXISTS trg_commande_transition ON commande;
        DROP TRIGGER IF EXISTS trg_commande_historique ON commande;
    END IF;
    IF to_regclass('public.cle_licence') IS NOT NULL THEN
        DROP TRIGGER IF EXISTS trg_cle_licence_protection ON cle_licence;
    END IF;
    IF to_regclass('public.journal_audit') IS NOT NULL THEN
        DROP TRIGGER IF EXISTS trg_journal_audit_immuable ON journal_audit;
        DROP TRIGGER IF EXISTS trg_journal_audit_immuable_truncate ON journal_audit;
    END IF;
    IF to_regclass('public.commande_historique') IS NOT NULL THEN
        DROP TRIGGER IF EXISTS trg_commande_historique_immuable ON commande_historique;
        DROP TRIGGER IF EXISTS trg_commande_historique_immuable_truncate ON commande_historique;
    END IF;
END
$$;
DROP FUNCTION IF EXISTS trg_trace_immuable_fn();
DROP FUNCTION IF EXISTS trg_cle_licence_protection_fn();
DROP FUNCTION IF EXISTS trg_commande_historique_fn();
DROP FUNCTION IF EXISTS trg_commande_transition_fn();

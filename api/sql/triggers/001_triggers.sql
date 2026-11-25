-- ============================================================================
-- SideKey — triggers défensifs (T-02, CDC §9.6) : la base refuse elle-même ce que le
-- métier interdit, même si l'API a un bug ou si quelqu'un écrit en SQL direct.
--
-- Les fonctions de trigger ne sont exécutables par personne (REVOKE ... FROM PUBLIC) :
-- PostgreSQL les appelle lors du déclenchement, sans contrôle EXECUTE.
--
-- Codes d'erreur (SQLSTATE) :
--   SK002 transition de statut interdite (§6.4) · SK003 clé vendue protégée (RG-04)
--   SK004 trace immuable (journal d'audit, historique)
-- Tests : api/tests/Database/11_triggers_test.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- trg_commande_transition : seules les transitions du diagramme §6.4 sont acceptées.
-- (À l'INSERT, le statut initial est contrôlé par ck_commande_statut.)
-- ---------------------------------------------------------------------------
CREATE FUNCTION trg_commande_transition_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    IF (OLD.statut, NEW.statut) IN (
        ('EN_ATTENTE_PAIEMENT', 'PAYEE'),
        ('EN_ATTENTE_PAIEMENT', 'A_VERIFIER'),
        ('EN_ATTENTE_PAIEMENT', 'EXPIREE'),
        ('EN_ATTENTE_PAIEMENT', 'ANNULEE'),
        ('A_VERIFIER', 'PAYEE'),
        ('A_VERIFIER', 'REMBOURSEE'),
        ('PAYEE', 'REMBOURSEE')
    ) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Transition de statut interdite pour la commande % : % → %', OLD.id, OLD.statut, NEW.statut
        USING ERRCODE = 'SK002';
END
$$;

CREATE TRIGGER trg_commande_transition
    BEFORE UPDATE OF statut ON commande
    FOR EACH ROW
    WHEN (OLD.statut IS DISTINCT FROM NEW.statut)
    EXECUTE FUNCTION trg_commande_transition_fn();

-- ---------------------------------------------------------------------------
-- trg_commande_historique : chaque création et chaque changement de statut est historisé (RG-21).
-- Auteur : identifiant posé par l'API dans la transaction (SET LOCAL sidekey.auteur_id = '<uuid>'),
-- NULL pour une action du système (expiration automatique).
-- ---------------------------------------------------------------------------
CREATE FUNCTION trg_commande_historique_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
DECLARE
    v_auteur uuid := nullif(current_setting('sidekey.auteur_id', true), '')::uuid;
BEGIN
    INSERT INTO commande_historique (ancien_statut, nouveau_statut, commande_id, auteur_id)
    VALUES (CASE WHEN TG_OP = 'UPDATE' THEN OLD.statut END, NEW.statut, NEW.id, v_auteur);
    RETURN NULL;   -- trigger AFTER : valeur de retour ignorée
END
$$;

CREATE TRIGGER trg_commande_historique
    AFTER INSERT OR UPDATE OF statut ON commande
    FOR EACH ROW
    EXECUTE FUNCTION trg_commande_historique_fn();

-- ---------------------------------------------------------------------------
-- trg_cle_licence_protection : une clé VENDUE ne se modifie plus et ne se supprime jamais (RG-04).
-- Seule exception : la première révélation par l'acheteur (revelee_le passe de NULL à une date).
-- ---------------------------------------------------------------------------
CREATE FUNCTION trg_cle_licence_protection_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    IF OLD.statut <> 'VENDUE' THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.revelee_le IS NULL AND NEW.revelee_le IS NOT NULL
       AND (NEW.id, NEW.cle_chiffree, NEW.empreinte, NEW.statut, NEW.importee_le, NEW.reservee_le,
            NEW.offre_id, NEW.ligne_commande_id, NEW.import_cles_id, NEW.cree_le)
           IS NOT DISTINCT FROM
           (OLD.id, OLD.cle_chiffree, OLD.empreinte, OLD.statut, OLD.importee_le, OLD.reservee_le,
            OLD.offre_id, OLD.ligne_commande_id, OLD.import_cles_id, OLD.cree_le) THEN
        RETURN NEW;   -- première révélation : seuls revelee_le et modifie_le changent
    END IF;

    RAISE EXCEPTION 'La clé % est vendue : % interdit', OLD.id, TG_OP USING ERRCODE = 'SK003';
END
$$;

CREATE TRIGGER trg_cle_licence_protection
    BEFORE UPDATE OR DELETE ON cle_licence
    FOR EACH ROW
    EXECUTE FUNCTION trg_cle_licence_protection_fn();

-- ---------------------------------------------------------------------------
-- trg_journal_audit_immuable / trg_commande_historique_immuable : les traces ne se corrigent pas
-- (BX-16, ENF-12). Complète l'absence de droit UPDATE / DELETE pour sk_app : même le
-- propriétaire des tables est bloqué, TRUNCATE compris.
-- ---------------------------------------------------------------------------
CREATE FUNCTION trg_trace_immuable_fn()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, pg_temp
AS $$
BEGIN
    RAISE EXCEPTION 'La table % est en ajout seul : % interdit', TG_TABLE_NAME, TG_OP USING ERRCODE = 'SK004';
END
$$;

CREATE TRIGGER trg_journal_audit_immuable
    BEFORE UPDATE OR DELETE ON journal_audit
    FOR EACH ROW EXECUTE FUNCTION trg_trace_immuable_fn();
CREATE TRIGGER trg_journal_audit_immuable_truncate
    BEFORE TRUNCATE ON journal_audit
    FOR EACH STATEMENT EXECUTE FUNCTION trg_trace_immuable_fn();

CREATE TRIGGER trg_commande_historique_immuable
    BEFORE UPDATE OR DELETE ON commande_historique
    FOR EACH ROW EXECUTE FUNCTION trg_trace_immuable_fn();
CREATE TRIGGER trg_commande_historique_immuable_truncate
    BEFORE TRUNCATE ON commande_historique
    FOR EACH STATEMENT EXECUTE FUNCTION trg_trace_immuable_fn();

REVOKE ALL ON FUNCTION trg_commande_transition_fn(), trg_commande_historique_fn(),
                       trg_cle_licence_protection_fn(), trg_trace_immuable_fn() FROM PUBLIC, sk_app;

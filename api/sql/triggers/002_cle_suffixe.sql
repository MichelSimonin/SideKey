-- ============================================================================
-- SideKey — F-08 : la colonne suffixe (004_cle_suffixe.sql) entre dans la protection des clés vendues.
-- Une clé VENDUE ne change plus, suffixe compris ; la première révélation reste la seule exception (RG-04).
-- La fonction remplacée est celle de 001_triggers.sql ; le trigger lui-même ne change pas.
-- ============================================================================
CREATE OR REPLACE FUNCTION trg_cle_licence_protection_fn()
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
       AND (NEW.id, NEW.cle_chiffree, NEW.empreinte, NEW.suffixe, NEW.statut, NEW.importee_le, NEW.reservee_le,
            NEW.offre_id, NEW.ligne_commande_id, NEW.import_cles_id, NEW.cree_le)
           IS NOT DISTINCT FROM
           (OLD.id, OLD.cle_chiffree, OLD.empreinte, OLD.suffixe, OLD.statut, OLD.importee_le, OLD.reservee_le,
            OLD.offre_id, OLD.ligne_commande_id, OLD.import_cles_id, OLD.cree_le) THEN
        RETURN NEW;   -- première révélation : seuls revelee_le et modifie_le changent
    END IF;

    RAISE EXCEPTION 'La clé % est vendue : % interdit', OLD.id, TG_OP USING ERRCODE = 'SK003';
END
$$;

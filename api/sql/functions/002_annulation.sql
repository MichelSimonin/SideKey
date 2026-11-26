-- ============================================================================
-- SideKey — F-06 : annulation d'une commande par son client (CDC §6.4, US-35).
--
-- EN_ATTENTE_PAIEMENT → ANNULEE, et ses clés RESERVEE reviennent en stock, de façon atomique.
-- Même écriture défensive que les fonctions de T-02 : SECURITY INVOKER (droits de l'appelant, sk_app),
-- search_path figé, paramètres contrôlés, erreurs signalées par SQLSTATE.
--
-- La propriété de la commande est vérifiée par l'appelant (requête de l'API filtrée sur le client) ; la
-- transition est aussi contrôlée par trg_commande_transition et historisée par trg_commande_historique.
-- ============================================================================

CREATE FUNCTION fn_annuler_commande(p_commande uuid)
RETURNS integer
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_commande commande%ROWTYPE;
    v_liberees integer;
BEGIN
    IF p_commande IS NULL THEN
        RAISE EXCEPTION 'fn_annuler_commande : commande obligatoire' USING ERRCODE = '22004';
    END IF;

    -- Verrou de la commande : le paiement (fn_valider_commande) et l'expiration attendent ou renoncent
    SELECT * INTO v_commande FROM commande WHERE id = p_commande FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'fn_annuler_commande : commande % introuvable', p_commande USING ERRCODE = 'P0002';
    END IF;
    -- Une commande expirée attend la tâche planifiée qui la passe en EXPIREE : elle n'est plus annulable
    IF v_commande.statut <> 'EN_ATTENTE_PAIEMENT' OR v_commande.expire_le <= now() THEN
        RAISE EXCEPTION 'fn_annuler_commande : la commande % n''est plus annulable (statut %, expire le %)',
            v_commande.id, v_commande.statut, v_commande.expire_le
            USING ERRCODE = 'SK005';
    END IF;

    UPDATE cle_licence k
       SET statut = 'DISPONIBLE', reservee_le = NULL, ligne_commande_id = NULL, modifie_le = now()
      FROM ligne_commande l
     WHERE l.id = k.ligne_commande_id AND l.commande_id = p_commande AND k.statut = 'RESERVEE';
    GET DIAGNOSTICS v_liberees = ROW_COUNT;

    UPDATE commande SET statut = 'ANNULEE', modifie_le = now() WHERE id = p_commande;

    RETURN v_liberees;
END
$$;

COMMENT ON FUNCTION fn_annuler_commande(uuid) IS
    'Annule une commande en attente de paiement et remet ses clés en stock. SK005 si elle n''est plus annulable.';

REVOKE ALL ON FUNCTION fn_annuler_commande(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_annuler_commande(uuid) TO sk_app;

-- ============================================================================
-- SideKey — fonctions métier PL/pgSQL en style défensif (T-02, CDC §9.6)
--
-- Principes appliqués à chaque fonction :
--   * paramètres vérifiés avant tout accès aux données, erreur explicite sinon ;
--   * SECURITY INVOKER : la fonction s'exécute avec les droits de l'appelant (sk_app),
--     elle n'élève aucun privilège ;
--   * search_path figé : un objet homonyme créé ailleurs ne peut pas être appelé à sa place ;
--   * verrous explicites (FOR UPDATE / SKIP LOCKED) : pas de lecture puis écriture sans verrou ;
--   * EXECUTE accordé à sk_app seulement, retiré à PUBLIC.
--
-- Codes d'erreur (SQLSTATE), traduits en codes HTTP par l'API :
--   22004 paramètre NULL · 22023 paramètre invalide · P0002 donnée introuvable
--   SK001 stock insuffisant (409) · SK005 commande non payable dans son état actuel (409)
-- Tests : api/tests/Database/10_fonctions_test.sql, 60_concurrence.sh
-- ============================================================================

-- ---------------------------------------------------------------------------
-- fn_reserver_cles : réserve p_quantite clés DISPONIBLES de l'offre pour une ligne de commande.
-- FOR UPDATE SKIP LOCKED : deux achats simultanés ne prennent jamais la même clé, et le
-- second n'attend pas le premier (scénario « dernière clé », CDC §10).
-- Renvoie les identifiants des clés réservées. Tout ou rien : en cas d'échec, rien n'est réservé.
-- ---------------------------------------------------------------------------
CREATE FUNCTION fn_reserver_cles(p_offre uuid, p_quantite integer, p_ligne uuid)
RETURNS SETOF uuid
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_ligne    ligne_commande%ROWTYPE;
    v_commande commande%ROWTYPE;
    v_actif    boolean;
    v_cles     uuid[];
BEGIN
    -- 1. Paramètres
    IF p_offre IS NULL OR p_quantite IS NULL OR p_ligne IS NULL THEN
        RAISE EXCEPTION 'fn_reserver_cles : offre, quantité et ligne sont obligatoires'
            USING ERRCODE = '22004';
    END IF;
    IF p_quantite NOT BETWEEN 1 AND 10 THEN
        RAISE EXCEPTION 'fn_reserver_cles : quantité % hors bornes (1 à 10)', p_quantite
            USING ERRCODE = '22023';
    END IF;

    -- 2. Cohérence ligne / commande / offre (la commande est verrouillée jusqu'à la fin)
    SELECT * INTO v_ligne FROM ligne_commande WHERE id = p_ligne;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'fn_reserver_cles : ligne de commande % introuvable', p_ligne USING ERRCODE = 'P0002';
    END IF;
    IF v_ligne.offre_id <> p_offre THEN
        RAISE EXCEPTION 'fn_reserver_cles : la ligne % ne porte pas sur l''offre %', p_ligne, p_offre
            USING ERRCODE = '22023';
    END IF;
    IF v_ligne.quantite <> p_quantite THEN
        RAISE EXCEPTION 'fn_reserver_cles : quantité % différente de celle de la ligne (%)', p_quantite, v_ligne.quantite
            USING ERRCODE = '22023';
    END IF;

    SELECT * INTO v_commande FROM commande WHERE id = v_ligne.commande_id FOR UPDATE;
    IF v_commande.statut <> 'EN_ATTENTE_PAIEMENT' OR v_commande.expire_le <= now() THEN
        RAISE EXCEPTION 'fn_reserver_cles : la commande % n''accepte plus de réservation (statut %, expire le %)',
            v_commande.id, v_commande.statut, v_commande.expire_le
            USING ERRCODE = 'SK005';
    END IF;

    SELECT actif INTO v_actif FROM offre WHERE id = p_offre;
    IF v_actif IS NOT TRUE THEN
        RAISE EXCEPTION 'fn_reserver_cles : offre % inactive ou introuvable', p_offre USING ERRCODE = 'P0002';
    END IF;

    IF EXISTS (SELECT 1 FROM cle_licence WHERE ligne_commande_id = p_ligne) THEN
        RAISE EXCEPTION 'fn_reserver_cles : des clés sont déjà attribuées à la ligne %', p_ligne
            USING ERRCODE = '22023';
    END IF;

    -- 3. Sélection et verrouillage des clés, les plus anciennes d'abord (FIFO)
    SELECT array_agg(id) INTO v_cles FROM (
        SELECT id FROM cle_licence
        WHERE offre_id = p_offre AND statut = 'DISPONIBLE'
        ORDER BY importee_le, id
        LIMIT p_quantite
        FOR UPDATE SKIP LOCKED
    ) AS libres;

    IF coalesce(cardinality(v_cles), 0) < p_quantite THEN
        RAISE EXCEPTION 'Stock insuffisant pour l''offre % : % demandée(s), % disponible(s)',
            p_offre, p_quantite, coalesce(cardinality(v_cles), 0)
            USING ERRCODE = 'SK001';
    END IF;

    -- 4. Réservation
    UPDATE cle_licence
       SET statut = 'RESERVEE', reservee_le = now(), ligne_commande_id = p_ligne, modifie_le = now()
     WHERE id = ANY (v_cles);

    RETURN QUERY SELECT unnest(v_cles);
END
$$;

COMMENT ON FUNCTION fn_reserver_cles(uuid, integer, uuid) IS
    'Réserve des clés DISPONIBLES pour une ligne de commande (FOR UPDATE SKIP LOCKED). SK001 si stock insuffisant.';

-- ---------------------------------------------------------------------------
-- fn_valider_commande : paiement accepté → clés VENDUE et commande PAYEE, de façon atomique.
-- Idempotente : une commande déjà PAYEE est renvoyée telle quelle (rejeu du prestataire).
-- Accepte EN_ATTENTE_PAIEMENT non expirée, ou A_VERIFIER (validation par un admin).
-- ---------------------------------------------------------------------------
CREATE FUNCTION fn_valider_commande(p_commande uuid)
RETURNS varchar
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_commande commande%ROWTYPE;
    v_attendu  integer;
    v_reserve  integer;
BEGIN
    IF p_commande IS NULL THEN
        RAISE EXCEPTION 'fn_valider_commande : commande obligatoire' USING ERRCODE = '22004';
    END IF;

    SELECT * INTO v_commande FROM commande WHERE id = p_commande FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'fn_valider_commande : commande % introuvable', p_commande USING ERRCODE = 'P0002';
    END IF;

    IF v_commande.statut = 'PAYEE' THEN
        RETURN v_commande.statut;   -- idempotence
    END IF;

    IF v_commande.statut = 'EN_ATTENTE_PAIEMENT' AND v_commande.expire_le <= now() THEN
        RAISE EXCEPTION 'fn_valider_commande : la réservation de la commande % a expiré le %', p_commande, v_commande.expire_le
            USING ERRCODE = 'SK005';
    END IF;
    IF v_commande.statut NOT IN ('EN_ATTENTE_PAIEMENT', 'A_VERIFIER') THEN
        RAISE EXCEPTION 'fn_valider_commande : une commande au statut % ne peut pas être payée', v_commande.statut
            USING ERRCODE = 'SK005';
    END IF;

    -- Une clé réservée par unité commandée, ni plus ni moins
    SELECT coalesce(sum(quantite), 0) INTO v_attendu FROM ligne_commande WHERE commande_id = p_commande;
    SELECT count(*) INTO v_reserve
      FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id
     WHERE l.commande_id = p_commande AND k.statut = 'RESERVEE';
    IF v_attendu = 0 OR v_reserve <> v_attendu THEN
        RAISE EXCEPTION 'fn_valider_commande : % clé(s) réservée(s) pour % attendue(s) sur la commande %',
            v_reserve, v_attendu, p_commande
            USING ERRCODE = 'SK005';
    END IF;

    UPDATE cle_licence k
       SET statut = 'VENDUE', modifie_le = now()
      FROM ligne_commande l
     WHERE l.id = k.ligne_commande_id AND l.commande_id = p_commande AND k.statut = 'RESERVEE';

    UPDATE commande SET statut = 'PAYEE', payee_le = now(), modifie_le = now() WHERE id = p_commande;

    RETURN 'PAYEE';
END
$$;

COMMENT ON FUNCTION fn_valider_commande(uuid) IS
    'Passe atomiquement les clés réservées en VENDUE et la commande en PAYEE. Idempotente. SK005 si état incompatible.';

-- ---------------------------------------------------------------------------
-- fn_liberer_reservations_expirees : appelée chaque minute par le Scheduler Symfony.
-- Commandes EN_ATTENTE_PAIEMENT dont le délai de 15 min est dépassé (RG-12) → EXPIREE,
-- leurs clés RESERVEE → DISPONIBLE. SKIP LOCKED : une commande en cours de paiement n'est
-- ni attendue ni touchée. Renvoie le nombre de commandes expirées.
-- ---------------------------------------------------------------------------
CREATE FUNCTION fn_liberer_reservations_expirees()
RETURNS integer
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_ids uuid[];
BEGIN
    SELECT array_agg(id) INTO v_ids FROM (
        SELECT id FROM commande
        WHERE statut = 'EN_ATTENTE_PAIEMENT' AND expire_le <= now()
        FOR UPDATE SKIP LOCKED
    ) AS expirees;

    IF v_ids IS NULL THEN
        RETURN 0;
    END IF;

    UPDATE cle_licence k
       SET statut = 'DISPONIBLE', reservee_le = NULL, ligne_commande_id = NULL, modifie_le = now()
      FROM ligne_commande l
     WHERE l.id = k.ligne_commande_id AND l.commande_id = ANY (v_ids) AND k.statut = 'RESERVEE';

    UPDATE commande SET statut = 'EXPIREE', modifie_le = now() WHERE id = ANY (v_ids);

    RETURN cardinality(v_ids);
END
$$;

COMMENT ON FUNCTION fn_liberer_reservations_expirees() IS
    'Expire les commandes en attente dont le délai est dépassé et remet leurs clés en stock (tâche planifiée).';

-- ---------------------------------------------------------------------------
-- Droits : sk_app uniquement (CDC §9.5)
-- ---------------------------------------------------------------------------
REVOKE ALL ON FUNCTION fn_reserver_cles(uuid, integer, uuid), fn_valider_commande(uuid),
                       fn_liberer_reservations_expirees() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_reserver_cles(uuid, integer, uuid), fn_valider_commande(uuid),
                          fn_liberer_reservations_expirees() TO sk_app;

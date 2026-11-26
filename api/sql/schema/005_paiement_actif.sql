-- ============================================================================
-- SideKey — F-07 : un seul paiement en cours ou accepté par commande (CDC §10.3, ADR-0008).
--
-- Le prestataire de paiement est appelé HORS transaction (aucun verrou n'est gardé pendant un appel externe). Deux
-- requêtes simultanées sur la même commande, avec deux clés d'idempotence différentes, pourraient donc toutes deux
-- atteindre le prestataire : le client serait débité deux fois. Ce n'est pas à l'application de l'empêcher par un
-- « si pas déjà payé » (lecture puis écriture) : l'index unique partiel ci-dessous le garantit à l'insertion, de façon
-- atomique, quel que soit le nombre de processus.
--
-- Les paiements REFUSE et ERREUR n'y figurent pas : un client peut réessayer après un refus (US-36), autant de fois que
-- la réservation de 15 min le permet (et que le blocage anti-fraude l'autorise).
-- ============================================================================
CREATE UNIQUE INDEX uq_paiement_commande_actif
    ON paiement (commande_id)
    WHERE statut IN ('EN_ATTENTE', 'ACCEPTE');

COMMENT ON INDEX uq_paiement_commande_actif IS
    'Une commande a au plus un paiement EN_ATTENTE ou ACCEPTE : pas de double débit, même hors transaction (F-07)';

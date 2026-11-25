-- Annulation de 001_fonctions.sql (T-02)
DROP FUNCTION IF EXISTS fn_liberer_reservations_expirees();
DROP FUNCTION IF EXISTS fn_valider_commande(uuid);
DROP FUNCTION IF EXISTS fn_reserver_cles(uuid, integer, uuid);

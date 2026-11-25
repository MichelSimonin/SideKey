<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Exception\CommandeNonReservableException;
use App\Exception\StockInsuffisantException;

/**
 * Réservation des clés en base (fonctions PL/pgSQL de T-02).
 * Implémentation : Repository/ReservationClesRepository.
 */
interface ReservationCles
{
    /**
     * Réserve $quantite clés de l'offre pour la ligne de commande (FOR UPDATE SKIP LOCKED).
     * À appeler dans la transaction qui crée la commande (F-06).
     *
     * @return list<string> identifiants des clés réservées
     *
     * @throws StockInsuffisantException      stock insuffisant (SQLSTATE SK001)
     * @throws CommandeNonReservableException commande expirée ou close (SQLSTATE SK005)
     */
    public function reserverCles(string $offreId, int $quantite, string $ligneCommandeId): array;

    /** Expire les commandes dont la réservation est dépassée et remet leurs clés en stock. */
    public function libererReservationsExpirees(): int;
}

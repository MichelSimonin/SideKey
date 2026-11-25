<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CommandeNonReservableException;
use App\Exception\StockInsuffisantException;
use App\Service\Reservation\ReservationCles;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Appelle les fonctions PL/pgSQL de T-02 et traduit leurs SQLSTATE en exceptions métier.
 * La logique de verrouillage (FOR UPDATE SKIP LOCKED) reste en base, au plus près des données.
 */
final class ReservationClesRepository implements ReservationCles
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function reserverCles(string $offreId, int $quantite, string $ligneCommandeId): array
    {
        try {
            /** @var list<string> $cles */
            $cles = $this->connexion->fetchFirstColumn(
                'SELECT cle::text FROM fn_reserver_cles(:offre, :quantite, :ligne) AS cle',
                ['offre' => $offreId, 'quantite' => $quantite, 'ligne' => $ligneCommandeId],
            );

            return $cles;
        } catch (DriverException $e) {
            throw match ($e->getSQLState()) {
                'SK001' => new StockInsuffisantException($this->disponibleDepuisMessage($e->getMessage()), $e),
                'SK005' => new CommandeNonReservableException($e),
                default => $e,
            };
        }
    }

    public function libererReservationsExpirees(): int
    {
        return (int) $this->connexion->fetchOne('SELECT fn_liberer_reservations_expirees()');
    }

    /**
     * Après une erreur, la transaction PostgreSQL est annulée : impossible de relire le stock.
     * fn_reserver_cles l'indique dans son message (« …, N disponible(s) »).
     */
    private function disponibleDepuisMessage(string $message): int
    {
        return 1 === preg_match('/(\d+) disponible\(s\)/', $message, $m) ? (int) $m[1] : 0;
    }
}

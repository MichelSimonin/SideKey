<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LibererReservationsExpirees;
use App\Service\Reservation\ReservationCles;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class LibererReservationsExpireesHandler
{
    public function __construct(
        private readonly ReservationCles $reservations,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LibererReservationsExpirees $message): int
    {
        $nombre = $this->reservations->libererReservationsExpirees();
        if ($nombre > 0) {
            $this->logger->info('Réservations expirées libérées', ['commandes' => $nombre]);
        }

        return $nombre;
    }
}

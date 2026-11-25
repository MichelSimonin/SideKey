<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Panier\PanierId;
use App\Service\Reservation\MinuteurReservation;
use Psr\Clock\ClockInterface;

/** Clé resa:{commandeId} (String, CDC §9.8) : valeur = échéance ISO 8601, TTL = temps restant. */
final class RedisMinuteurReservation implements MinuteurReservation
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function demarrer(string $commandeId, \DateTimeImmutable $expireLe): void
    {
        $restant = $expireLe->getTimestamp() - $this->horloge->now()->getTimestamp();
        if ($restant <= 0) {
            throw new \InvalidArgumentException('La réservation est déjà expirée.');
        }
        $this->redis->set(
            $this->cle($commandeId),
            $expireLe->format(\DATE_ATOM),
            ['ex' => min($restant, self::DUREE_SECONDES)],
        );
    }

    public function secondesRestantes(string $commandeId): ?int
    {
        $ttl = $this->redis->ttl($this->cle($commandeId));

        return \is_int($ttl) && $ttl > 0 ? $ttl : null;   // -2 : absente, -1 : sans TTL (jamais posé ainsi)
    }

    public function arreter(string $commandeId): void
    {
        $this->redis->del($this->cle($commandeId));
    }

    private function cle(string $commandeId): string
    {
        if (!PanierId::estUuid($commandeId)) {
            throw new \InvalidArgumentException('Identifiant de commande invalide.');
        }

        return 'resa:'.strtolower($commandeId);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\Commande;

use App\Service\Reservation\MinuteurReservation;

/** Doublure du minuteur Redis ; « enPanne » simule un Redis arrêté. */
final class MinuteurEnMemoire implements MinuteurReservation
{
    /** @var array<string, \DateTimeImmutable> */
    public array $minuteurs = [];
    public bool $enPanne = false;

    public function demarrer(string $commandeId, \DateTimeImmutable $expireLe): void
    {
        $this->panne();
        $this->minuteurs[$commandeId] = $expireLe;
    }

    public function secondesRestantes(string $commandeId): ?int
    {
        $this->panne();

        return isset($this->minuteurs[$commandeId]) ? max(0, $this->minuteurs[$commandeId]->getTimestamp() - time()) : null;
    }

    public function arreter(string $commandeId): void
    {
        $this->panne();
        unset($this->minuteurs[$commandeId]);
    }

    private function panne(): void
    {
        if ($this->enPanne) {
            throw new \RedisException('Connection refused');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Commande;

/** Ligne de l'historique des commandes (US-35). */
final class CommandeResume
{
    public function __construct(
        public readonly string $reference,
        public readonly string $statut,
        public readonly int $montantCentimes,
        public readonly int $nombreArticles,
        public readonly \DateTimeImmutable $creeLe,
    ) {
    }
}

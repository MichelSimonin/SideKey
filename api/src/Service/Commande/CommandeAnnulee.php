<?php

declare(strict_types=1);

namespace App\Service\Commande;

final class CommandeAnnulee
{
    /** @param list<string> $slugs jeux dont le stock vient de remonter (invalidation du cache) */
    public function __construct(
        public readonly string $id,
        public readonly int $clesLiberees,
        public readonly array $slugs,
    ) {
    }
}

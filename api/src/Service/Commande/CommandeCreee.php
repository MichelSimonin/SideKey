<?php

declare(strict_types=1);

namespace App\Service\Commande;

final class CommandeCreee
{
    /** @param list<string> $slugs jeux dont le stock vient de baisser (invalidation du cache) */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly \DateTimeImmutable $expireLe,
        public readonly array $slugs,
    ) {
    }
}

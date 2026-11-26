<?php

declare(strict_types=1);

namespace App\Service\Cles;

/** Clé d'une commande, telle que la voit la révélation : chiffré, propriétaire, statuts de la commande et de la clé. */
final class CleRevelable
{
    public function __construct(
        public readonly string $id,
        public readonly string $offreId,
        public readonly string $chiffre,
        public readonly string $proprietaireId,
        public readonly string $statutCommande,
        public readonly string $statutCle,
        public readonly ?\DateTimeImmutable $reveleeLe,
    ) {
    }

    /** Jamais de chiffré dans une trace : il n'apporte rien au diagnostic. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'statutCommande' => $this->statutCommande];
    }
}

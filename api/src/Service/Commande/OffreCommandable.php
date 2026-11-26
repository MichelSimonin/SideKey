<?php

declare(strict_types=1);

namespace App\Service\Commande;

/** Offre telle que la commande la contrôle : en vente, et PEGI du jeu (RG-13). */
final class OffreCommandable
{
    public const PEGI_MAJEUR = 18;

    public function __construct(
        public readonly string $offreId,
        public readonly string $slug,
        public readonly int $pegi,
        public readonly bool $active,
    ) {
    }

    public function exigeMajorite(): bool
    {
        return $this->pegi >= self::PEGI_MAJEUR;
    }
}

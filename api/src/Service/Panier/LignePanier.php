<?php

declare(strict_types=1);

namespace App\Service\Panier;

final class LignePanier
{
    public function __construct(
        public readonly OffreDisponibilite $offre,
        public readonly int $quantite,
        public readonly int $quantiteMax,
        public readonly bool $quantiteAjustee,
    ) {
    }

    public function sousTotalCentimes(): int
    {
        return $this->offre->prixCentimes * $this->quantite;
    }
}

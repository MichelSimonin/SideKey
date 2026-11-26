<?php

declare(strict_types=1);

namespace App\Service\Commande;

final class LigneCommandeDetail
{
    /** @param list<CleCommande> $cles */
    public function __construct(
        public readonly string $offreId,
        public readonly string $titre,
        public readonly string $plateforme,
        public readonly int $quantite,
        public readonly int $prixUnitaireCentimes,
        public readonly array $cles,
    ) {
    }

    public function sousTotalCentimes(): int
    {
        return $this->prixUnitaireCentimes * $this->quantite;
    }
}

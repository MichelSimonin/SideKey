<?php

declare(strict_types=1);

namespace App\Service\Panier;

/** Vue du panier calculée côté serveur (US-13, RG-11). */
final class Panier
{
    /**
     * @param list<LignePanier> $lignes
     * @param list<string>      $offresRetirees offres retirées car supprimées, inactives ou en rupture
     */
    public function __construct(
        public readonly array $lignes,
        public readonly array $offresRetirees = [],
    ) {
    }

    public function totalCentimes(): int
    {
        return array_sum(array_map(static fn (LignePanier $l): int => $l->sousTotalCentimes(), $this->lignes));
    }

    public function nombreArticles(): int
    {
        return array_sum(array_map(static fn (LignePanier $l): int => $l->quantite, $this->lignes));
    }
}

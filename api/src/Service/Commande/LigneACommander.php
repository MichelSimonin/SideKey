<?php

declare(strict_types=1);

namespace App\Service\Commande;

/** Ligne du panier validé : l'offre et la quantité, jamais le prix (RG-11 : relu en base). */
final class LigneACommander
{
    public function __construct(
        public readonly string $offreId,
        public readonly int $quantite,
    ) {
    }
}

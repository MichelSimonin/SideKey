<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/** Tentative de paiement ouverte en base, en attente de la réponse du prestataire. */
final class PaiementOuvert
{
    public function __construct(
        public readonly string $paiementId,
        public readonly string $commandeId,
    ) {
    }
}

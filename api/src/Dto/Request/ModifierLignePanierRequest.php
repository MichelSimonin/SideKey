<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Panier\PanierService;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/panier/lignes/{offreId} : 0 retire la ligne. */
final class ModifierLignePanierRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: PanierService::QUANTITE_MAX_PAR_OFFRE)]
        public readonly ?int $quantite = null,
    ) {
    }
}

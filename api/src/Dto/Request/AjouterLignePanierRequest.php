<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Panier\PanierService;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/panier/lignes. */
final class AjouterLignePanierRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $offreId = '',

        #[Assert\Range(min: 1, max: PanierService::QUANTITE_MAX_PAR_OFFRE)]
        public readonly int $quantite = 1,
    ) {
    }
}

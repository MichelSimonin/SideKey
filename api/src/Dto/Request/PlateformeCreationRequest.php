<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\GestionPlateformes;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/admin/plateformes : code (STEAM, PSN… majuscules, chiffres, tiret bas) et libellé affiché. */
final class PlateformeCreationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(GestionPlateformes::CODE)]
        public readonly string $code = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: GestionPlateformes::LIBELLE_MAX)]
        public readonly string $libelle = '',
    ) {
    }
}

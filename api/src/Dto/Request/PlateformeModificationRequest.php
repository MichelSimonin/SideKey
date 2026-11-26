<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\GestionPlateformes;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /api/admin/plateformes/{id} : seul le libellé change, le code est immuable (un « code » envoyé est ignoré). */
final class PlateformeModificationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: GestionPlateformes::LIBELLE_MAX)]
        public readonly string $libelle = '',
    ) {
    }
}

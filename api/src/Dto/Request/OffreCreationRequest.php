<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\GestionOffres;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/admin/jeux/{id}/offres : plateforme, prix TTC (chaîne décimale « 14.99 ») et seuil d'alerte de stock. */
final class OffreCreationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $plateformeId = '',

        #[Assert\NotBlank]
        #[Assert\Regex(GestionOffres::PRIX)]
        public readonly string $prix = '',

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: GestionOffres::SEUIL_MAX)]
        public readonly ?int $seuilAlerte = 5,
    ) {
    }
}

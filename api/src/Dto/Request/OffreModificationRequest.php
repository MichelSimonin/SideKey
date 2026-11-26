<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\GestionOffres;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PUT /api/admin/offres/{offreId}. « version » est celle lue avec l'offre : si un collègue l'a modifiée entre-temps,
 * la réponse est 409 et rien n'est écrasé (verrouillage optimiste, US-51).
 */
final class OffreModificationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(GestionOffres::PRIX)]
        public readonly string $prix = '',

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: GestionOffres::SEUIL_MAX)]
        public readonly ?int $seuilAlerte = null,

        #[Assert\NotNull]
        public readonly ?bool $actif = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly ?int $version = null,
    ) {
    }
}

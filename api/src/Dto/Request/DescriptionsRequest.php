<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\SaisieJeu;
use Symfony\Component\Validator\Constraints as Assert;

/** Descriptions d'un jeu dans les deux langues (10 à 4 000 caractères, contrainte ck_jt_description). */
final class DescriptionsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: SaisieJeu::DESCRIPTION_MIN, max: SaisieJeu::DESCRIPTION_MAX)]
        public readonly string $fr = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: SaisieJeu::DESCRIPTION_MIN, max: SaisieJeu::DESCRIPTION_MAX)]
        public readonly string $en = '',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/** Paramètres de GET /api/admin/jeux : recherche par titre, filtre actif/inactif, page. */
final class RechercheJeuxAdminRequest
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public readonly ?string $q = null,

        #[Assert\Choice(choices: ['actifs', 'inactifs', 'tous'])]
        public readonly string $statut = 'tous',

        #[Assert\Range(min: 1, max: 10_000)]
        public readonly int $page = 1,
    ) {
    }

    public function actif(): ?bool
    {
        return match ($this->statut) {
            'actifs' => true,
            'inactifs' => false,
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\Gestion\GestionEditeurs;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST et PUT /api/admin/editeurs : le nom seul (aucun autre champ n'est lu). */
final class EditeurRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: GestionEditeurs::NOM_MAX)]
        public readonly string $nom = '',
    ) {
    }
}

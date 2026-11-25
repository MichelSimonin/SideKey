<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/connexion. Volontairement sans règle de composition : une connexion qui détaillerait la
 * politique de mot de passe renseignerait un attaquant. Seules les bornes qui protègent le serveur (taille
 * de l'e-mail, plafond du mot de passe avant hachage) sont contrôlées.
 */
final class ConnexionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 254)]
        public readonly string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public readonly string $motDePasse = '',
    ) {
    }
}

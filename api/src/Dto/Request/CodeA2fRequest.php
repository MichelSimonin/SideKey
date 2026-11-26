<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps des routes de la seconde étape : un code TOTP (6 chiffres) ou un code de secours (« ABCDE-FGHJK »), espaces tolérés.
 * Le format est contrôlé ici ; l'exactitude du code, seulement par le service. Le message ne dit pas quel format est attendu
 * au-delà de ce que l'application d'authentification affiche déjà.
 */
final class CodeA2fRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        #[Assert\Regex(pattern: '/^(\d{3} ?\d{3}|[A-Za-z2-7]{5}[- ]?[A-Za-z2-7]{5})$/', message: 'a2f.code_format')]
        public readonly string $code = '',
    ) {
    }
}

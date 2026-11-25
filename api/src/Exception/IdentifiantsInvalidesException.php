<?php

declare(strict_types=1);

namespace App\Exception;

/** E-mail ou mot de passe incorrect, compte inconnu ou désactivé : un seul message, pour ne pas révéler lequel (401). */
final class IdentifiantsInvalidesException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.identifiants_invalides');
    }

    public function codeHttp(): int
    {
        return 401;
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

/** Mot de passe hors politique (CDC §11.4), revérifié dans la couche métier (422). */
final class MotDePasseInvalideException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.mot_de_passe_invalide');
    }

    public function codeHttp(): int
    {
        return 422;
    }
}

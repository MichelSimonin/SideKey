<?php

declare(strict_types=1);

namespace App\Exception;

/** Jeton de rafraîchissement absent, malformé, expiré, déjà utilisé ou révoqué (401). */
final class SessionInvalideException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.session_invalide');
    }

    public function codeHttp(): int
    {
        return 401;
    }
}

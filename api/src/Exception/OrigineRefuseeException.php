<?php

declare(strict_types=1);

namespace App\Exception;

/** Requête sensible venue d'une origine non autorisée, ou sans jeton CSRF valide (403). Message générique : on ne dit pas quel contrôle a échoué. */
final class OrigineRefuseeException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.forbidden');
    }

    public function codeHttp(): int
    {
        return 403;
    }
}

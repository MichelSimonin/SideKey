<?php

declare(strict_types=1);

namespace App\Exception;

/** Commander exige une adresse e-mail vérifiée (RG-10) : 403. */
final class CompteNonVerifieException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.compte_non_verifie');
    }

    public function codeHttp(): int
    {
        return 403;
    }
}

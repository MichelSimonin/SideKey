<?php

declare(strict_types=1);

namespace App\Exception;

/** Le panier contient un jeu PEGI 18 et la majorité n'a pas été déclarée (RG-13) : 422. */
final class MajoriteNonConfirmeeException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.majorite_non_confirmee');
    }

    public function codeHttp(): int
    {
        return 422;
    }
}

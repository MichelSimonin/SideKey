<?php

declare(strict_types=1);

namespace App\Exception;

/** Offre inexistante ou retirée de la vente (404). */
final class OffreIndisponibleException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.offre_indisponible');
    }

    public function codeHttp(): int
    {
        return 404;
    }
}

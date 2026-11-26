<?php

declare(strict_types=1);

namespace App\Exception;

/** Le panier a été ajusté au stock ou à la disponibilité des offres : le client doit le revoir avant de commander (409). */
final class PanierModifieException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.panier_modifie');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

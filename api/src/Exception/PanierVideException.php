<?php

declare(strict_types=1);

namespace App\Exception;

/** Commande demandée avec un panier vide (422). */
final class PanierVideException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.panier_vide');
    }

    public function codeHttp(): int
    {
        return 422;
    }
}

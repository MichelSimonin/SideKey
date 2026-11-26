<?php

declare(strict_types=1);

namespace App\Exception;

/** Commande inexistante ou d'un autre client (404) : même réponse dans les deux cas, pas d'énumération (anti-IDOR). */
final class CommandeIntrouvableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.commande_introuvable');
    }

    public function codeHttp(): int
    {
        return 404;
    }
}

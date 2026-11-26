<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Clé de licence inexistante ou appartenant à un autre client (404) : la réponse est la même dans les deux
 * cas, pour ne pas révéler l'existence d'une clé d'autrui (anti-IDOR, pas d'énumération).
 */
final class CleIntrouvableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.cle_introuvable');
    }

    public function codeHttp(): int
    {
        return 404;
    }
}

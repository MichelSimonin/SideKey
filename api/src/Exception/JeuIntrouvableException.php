<?php

declare(strict_types=1);

namespace App\Exception;

/** Jeu inconnu, retiré du catalogue ou slug mal formé (404). Même réponse dans les trois cas. */
final class JeuIntrouvableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.jeu_introuvable');
    }

    public function codeHttp(): int
    {
        return 404;
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

/** Page de résultats au-delà de la dernière (404). */
final class PageIntrouvableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.page_introuvable');
    }

    public function codeHttp(): int
    {
        return 404;
    }
}

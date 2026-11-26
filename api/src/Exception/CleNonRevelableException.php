<?php

declare(strict_types=1);

namespace App\Exception;

/** Clé de son propre achat, mais commande pas encore payée, en vérification ou remboursée (409). */
final class CleNonRevelableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.cle_non_revelable');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

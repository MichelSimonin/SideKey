<?php

declare(strict_types=1);

namespace App\Exception;

/** Une clé d'idempotence déjà utilisée pour une AUTRE commande (409) : un rejeu ne change jamais de cible. */
final class IdempotenceConflitException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.paiement_idempotence_conflit');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

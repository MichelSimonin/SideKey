<?php

declare(strict_types=1);

namespace App\Exception;

/** La commande n'est plus en attente de paiement : payée, expirée, annulée (409). */
final class CommandeNonAnnulableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.commande_non_annulable');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

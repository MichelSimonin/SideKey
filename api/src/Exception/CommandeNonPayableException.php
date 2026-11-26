<?php

declare(strict_types=1);

namespace App\Exception;

/** La commande n'accepte plus de paiement : déjà payée, à vérifier, expirée ou annulée (409). Un second paiement est refusé. */
final class CommandeNonPayableException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.commande_non_payable');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

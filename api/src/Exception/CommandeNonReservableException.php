<?php

declare(strict_types=1);

namespace App\Exception;

/** La commande n'accepte plus de réservation : expirée, close ou déjà servie (SQLSTATE SK005, 409). */
final class CommandeNonReservableException extends ExceptionMetier
{
    public function __construct(?\Throwable $precedente = null)
    {
        parent::__construct('error.commande_non_reservable', [], $precedente);
    }

    public function codeHttp(): int
    {
        return 409;
    }
}

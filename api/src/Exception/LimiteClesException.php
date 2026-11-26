<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Plafond de clés acheté sur 24 h glissantes atteint (RG-14, 422). Le paiement est refusé AVANT d'appeler le prestataire :
 * le client n'est pas débité. La commande reste en attente jusqu'à la fin de sa réservation ; il peut l'annuler.
 */
final class LimiteClesException extends ExceptionMetier
{
    public function __construct(int $maximum)
    {
        parent::__construct('error.paiement_limite_cles', ['%maximum%' => $maximum]);
    }

    public function codeHttp(): int
    {
        return 422;
    }
}

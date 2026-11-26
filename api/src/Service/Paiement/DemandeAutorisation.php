<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Ce que le service demande au prestataire : un montant en centimes calculé par le serveur (RG-11), la carte, et une
 * référence d'idempotence qu'un vrai prestataire utiliserait pour ne pas débiter deux fois en cas de rejeu.
 */
final class DemandeAutorisation
{
    public function __construct(
        public readonly int $montantCentimes,
        public readonly CartePaiement $carte,
        public readonly string $referenceIdempotence,
    ) {
    }
}

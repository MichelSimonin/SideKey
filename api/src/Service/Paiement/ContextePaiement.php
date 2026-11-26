<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Ce que le service sait de la commande à payer et de son client, relu en base (jamais fourni par le client, RG-11) :
 * de quoi décider d'un paiement (montant, nombre de clés, échéance) et de la règle « à vérifier » (ancienneté du compte,
 * première commande).
 */
final class ContextePaiement
{
    public function __construct(
        public readonly string $commandeId,
        public readonly string $reference,
        public readonly string $statut,
        public readonly int $montantCentimes,
        public readonly int $nombreCles,
        public readonly \DateTimeImmutable $expireLe,
        public readonly \DateTimeImmutable $compteCreeLe,
        /** Aucune commande payée, à vérifier ou remboursée avant celle-ci. */
        public readonly bool $premiereCommande,
    ) {
    }
}

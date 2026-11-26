<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use App\Service\Commande\CommandeDetail;

/**
 * Issue d'un paiement accepté. Le statut de la COMMANDE dit s'il est payé (PAYEE) ou en attente de vérification
 * (A_VERIFIER) : un paiement accepté n'est pas toujours une commande payée (ADR-0008), l'écran ne promet donc pas
 * de clé tant que la commande n'est pas PAYEE.
 */
final class PaiementEffectue
{
    public function __construct(
        public readonly CommandeDetail $commande,
        public readonly string $derniersChiffres,
        /** true : réponse rejouée (même clé d'idempotence), aucun nouveau paiement. */
        public readonly bool $rejeu,
    ) {
    }
}

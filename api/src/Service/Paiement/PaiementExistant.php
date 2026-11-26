<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/** Tentative déjà enregistrée sous une clé d'idempotence : de quoi rejouer la même réponse sans rappeler le prestataire. */
final class PaiementExistant
{
    public function __construct(
        public readonly string $paiementId,
        /** EN_ATTENTE, ACCEPTE, REFUSE ou ERREUR. */
        public readonly string $statut,
        public readonly ?string $codeRetour,
        public readonly ?string $derniersChiffres,
        /** Référence de la commande visée par cette tentative. */
        public readonly string $commandeReference,
    ) {
    }
}

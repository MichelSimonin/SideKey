<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Prestataire de paiement : patron Strategy (« PaymentProviderInterface » du CDC §8.5, ADR-0008). En v1, l'implémentation
 * est un prestataire SIMULÉ (Infrastructure/Payment/FournisseurPaiementSimule) ; brancher un vrai prestataire en v2
 * consiste à écrire une autre implémentation, sans toucher à PaiementService ni aux contrôleurs.
 *
 * Contrat : appelé HORS transaction (aucun verrou de base n'est tenu pendant un appel externe, CDC §10.3). Une exception
 * est traitée comme une erreur du prestataire (le paiement n'est pas accepté, jamais supposé accepté).
 */
interface FournisseurPaiement
{
    public function autoriser(DemandeAutorisation $demande): ResultatPaiement;
}

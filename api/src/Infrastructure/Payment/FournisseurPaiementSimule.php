<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Service\Paiement\DemandeAutorisation;
use App\Service\Paiement\FournisseurPaiement;
use App\Service\Paiement\ResultatPaiement;

/**
 * Prestataire de paiement simulé (décision F12, CDC §11.6, ADR-0008). Déterministe : la réponse ne dépend que du numéro de
 * carte, ce qui rend chaque cas testable en CI. Aucun appel réseau, aucune donnée conservée.
 *
 *   4242 4242 4242 4242 → accepté           4000 0000 0000 9995 → fonds insuffisants
 *   4000 0000 0000 0002 → carte refusée     4000 0000 0000 0119 → délai dépassé (pas de réponse)
 *   tout autre numéro valide (clé de Luhn)  → carte inconnue (refusé)
 */
final class FournisseurPaiementSimule implements FournisseurPaiement
{
    public function autoriser(DemandeAutorisation $demande): ResultatPaiement
    {
        return match ($demande->carte->numero()) {
            '4242424242424242' => ResultatPaiement::accepte(),
            '4000000000000002' => ResultatPaiement::refuse('carte_refusee'),
            '4000000000009995' => ResultatPaiement::refuse('fonds_insuffisants'),
            '4000000000000119' => ResultatPaiement::erreur('delai_depasse'),
            default => ResultatPaiement::refuse('carte_inconnue'),
        };
    }
}

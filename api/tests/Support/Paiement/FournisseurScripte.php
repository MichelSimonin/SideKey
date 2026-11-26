<?php

declare(strict_types=1);

namespace App\Tests\Support\Paiement;

use App\Service\Paiement\DemandeAutorisation;
use App\Service\Paiement\FournisseurPaiement;
use App\Service\Paiement\ResultatPaiement;

/** Prestataire dont la réponse est choisie par le test ; garde les demandes reçues pour vérifier le montant et le nombre d'appels. */
final class FournisseurScripte implements FournisseurPaiement
{
    /** @var list<DemandeAutorisation> */
    public array $demandes = [];
    public ?ResultatPaiement $resultat = null;
    public ?\Throwable $exception = null;
    /** Sans réponse scriptée, laisse ce prestataire répondre (le simulé, pour jouer les vraies cartes de test). */
    public ?FournisseurPaiement $delegue = null;

    public function autoriser(DemandeAutorisation $demande): ResultatPaiement
    {
        $this->demandes[] = $demande;
        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->resultat ?? $this->delegue?->autoriser($demande) ?? ResultatPaiement::accepte();
    }
}

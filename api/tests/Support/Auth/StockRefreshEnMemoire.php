<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Service\Auth\ResultatRotation;
use App\Service\Auth\StockRefresh;

/** Doublure du stockage Redis des jetons de rafraîchissement : même sémantique (rotation, réutilisation, famille). */
final class StockRefreshEnMemoire implements StockRefresh
{
    /** @var array<string, array{0: string, 1: string}> empreinte → [utilisateur, famille] */
    public array $actifs = [];

    /** @var array<string, string> empreinte consommée → famille */
    public array $consommes = [];

    public function enregistrer(string $empreinte, string $utilisateurId, string $familleId): void
    {
        $this->actifs[$empreinte] = [$utilisateurId, $familleId];
    }

    public function faireTourner(string $ancienneEmpreinte, string $nouvelleEmpreinte): ResultatRotation
    {
        if (isset($this->actifs[$ancienneEmpreinte])) {
            [$utilisateur, $famille] = $this->actifs[$ancienneEmpreinte];
            unset($this->actifs[$ancienneEmpreinte]);
            $this->consommes[$ancienneEmpreinte] = $famille;
            $this->actifs[$nouvelleEmpreinte] = [$utilisateur, $famille];

            return ResultatRotation::reussie($utilisateur, $famille);
        }
        if (isset($this->consommes[$ancienneEmpreinte])) {
            $famille = $this->consommes[$ancienneEmpreinte];
            $this->revoquerFamille($famille);

            return ResultatRotation::reutilisation($famille);
        }

        return ResultatRotation::inconnu();
    }

    public function revoquerFamille(string $familleId): void
    {
        foreach ($this->actifs as $empreinte => [, $famille]) {
            if ($famille === $familleId) {
                unset($this->actifs[$empreinte]);
            }
        }
    }
}

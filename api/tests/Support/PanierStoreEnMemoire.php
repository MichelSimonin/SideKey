<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Panier\PanierId;
use App\Service\Panier\PanierStore;

/** Doublure du stockage Redis pour les tests unitaires du service. */
final class PanierStoreEnMemoire implements PanierStore
{
    /** @var array<string, array<string, int>> */
    public array $paniers = [];

    public function lire(PanierId $panier): array
    {
        return $this->paniers[$panier->cle] ?? [];
    }

    public function definir(PanierId $panier, string $offreId, int $quantite): void
    {
        $this->paniers[$panier->cle][$offreId] = $quantite;
    }

    public function retirer(PanierId $panier, string $offreId): void
    {
        unset($this->paniers[$panier->cle][$offreId]);
    }

    public function vider(PanierId $panier): void
    {
        unset($this->paniers[$panier->cle]);
    }
}

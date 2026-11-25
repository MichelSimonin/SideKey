<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Panier\CatalogueOffres;
use App\Service\Panier\OffreDisponibilite;

final class CatalogueOffresEnMemoire implements CatalogueOffres
{
    /** @var array<string, OffreDisponibilite> */
    public array $offres = [];

    public function ajouter(string $id, int $prixCentimes, int $stock, bool $active = true): void
    {
        $this->offres[$id] = new OffreDisponibilite($id, 'Jeu '.$id, 'STEAM', $prixCentimes, $stock, $active);
    }

    public function disponibilites(array $offreIds): array
    {
        return array_intersect_key($this->offres, array_flip($offreIds));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Exception\ConflitOffreException;

/** Accès aux offres pour le back-office (couche données). Implémentation : Repository/OffresAdminRepository. */
interface OffresAdmin
{
    /**
     * @param string $prix décimal validé (« 14.99 »), strictement positif
     *
     * @return array{offre: OffreAdmin, jeuSlug: string}
     *
     * @throws CatalogueException jeu ou plateforme introuvable (404), offre active déjà existante pour ce couple (409)
     */
    public function creer(string $jeuId, string $plateformeId, string $prix, int $seuilAlerte): array;

    /**
     * Modifie l'offre SI sa version est celle que l'appelant a chargée (verrouillage optimiste, CDC §10.2) : l'UPDATE porte
     * `AND version = :attendue` et incrémente la version.
     *
     * @return array{offre: OffreAdmin, jeuSlug: string}
     *
     * @throws ConflitOffreException un collègue a modifié l'offre entre-temps (409, avec la version actuelle)
     * @throws CatalogueException    introuvable (404), ou réactivation impossible : une autre offre active existe pour ce couple (409)
     */
    public function modifier(string $offreId, string $prix, int $seuilAlerte, bool $actif, int $versionAttendue): array;
}

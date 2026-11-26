<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;

/** Accès aux plateformes pour le back-office (couche données). Implémentation : Repository/PlateformesRepository. */
interface PlateformesAdmin
{
    /** @return list<PlateformeAdmin> */
    public function lister(): array;

    /** @throws CatalogueException introuvable (404) */
    public function trouver(string $id): PlateformeAdmin;

    /** @throws CatalogueException code déjà pris (409) */
    public function creer(string $code, string $libelle): PlateformeAdmin;

    /** Le code est immuable : seul le libellé change. @throws CatalogueException introuvable (404) */
    public function modifier(string $id, string $libelle): PlateformeAdmin;

    /** @throws CatalogueException introuvable (404) ou plateforme utilisée par des offres (409) */
    public function supprimer(string $id): void;

    /** @return list<string> slugs des jeux ayant une offre sur la plateforme */
    public function slugsDesJeux(string $id): array;
}

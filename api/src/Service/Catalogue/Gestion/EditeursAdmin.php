<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;

/** Accès aux éditeurs pour le back-office (couche données). Implémentation : Repository/EditeursRepository. Aucune autorisation ici. */
interface EditeursAdmin
{
    /** @return list<EditeurAdmin> */
    public function lister(): array;

    /** @throws CatalogueException introuvable (404) */
    public function trouver(string $id): EditeurAdmin;

    /** @throws CatalogueException nom déjà pris (409) */
    public function creer(string $nom): EditeurAdmin;

    /** @throws CatalogueException introuvable (404) ou nom déjà pris (409) */
    public function modifier(string $id, string $nom): EditeurAdmin;

    /** @throws CatalogueException introuvable (404) ou éditeur utilisé par des jeux (409) */
    public function supprimer(string $id): void;

    /** @return list<string> slugs des jeux de l'éditeur : leurs fiches en cache sont à invalider quand le nom change */
    public function slugsDesJeux(string $id): array;
}

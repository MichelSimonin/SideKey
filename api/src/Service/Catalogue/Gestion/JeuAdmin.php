<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/** Un jeu complet vu du back-office : traductions FR et EN, genres, offres (inactives comprises). */
final class JeuAdmin
{
    /**
     * @param array{fr: string, en: string} $descriptions
     * @param list<string>                  $genres       slugs
     * @param list<OffreAdmin>              $offres
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $titre,
        public readonly ?string $dateSortie,
        public readonly int $pegi,
        public readonly bool $actif,
        public readonly ?string $jaquette,
        public readonly string $editeurId,
        public readonly string $editeur,
        public readonly array $descriptions,
        public readonly array $genres,
        public readonly array $offres,
    ) {
    }
}

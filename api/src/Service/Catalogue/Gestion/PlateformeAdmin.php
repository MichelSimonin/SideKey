<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/** Une plateforme vue du back-office : code (immuable : STEAM, PSN…), libellé affiché et nombre d'offres (bloque la suppression). */
final class PlateformeAdmin
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $libelle,
        public readonly int $nombreOffres,
    ) {
    }
}

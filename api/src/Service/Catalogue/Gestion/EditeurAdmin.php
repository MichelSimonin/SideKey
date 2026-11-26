<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/** Un éditeur vu du back-office : nom et nombre de jeux qui s'y rattachent (bloque la suppression). */
final class EditeurAdmin
{
    public function __construct(
        public readonly string $id,
        public readonly string $nom,
        public readonly int $nombreJeux,
    ) {
    }
}

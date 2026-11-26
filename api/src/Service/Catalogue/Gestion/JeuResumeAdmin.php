<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/** Une ligne de la liste des jeux du back-office (jeux inactifs compris). */
final class JeuResumeAdmin
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $titre,
        public readonly string $editeur,
        public readonly int $pegi,
        public readonly bool $actif,
        public readonly ?string $jaquette,
        public readonly int $nombreOffres,
    ) {
    }
}

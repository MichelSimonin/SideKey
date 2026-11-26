<?php

declare(strict_types=1);

namespace App\Service\Cles;

/** Ligne d'import prête à l'insertion : rien en clair, sauf les 4 derniers caractères. */
final class CleAImporter
{
    public function __construct(
        public readonly int $ligne,
        public readonly string $chiffre,
        public readonly string $empreinte,
        public readonly string $suffixe,
    ) {
    }
}

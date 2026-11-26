<?php

declare(strict_types=1);

namespace App\Service\A2f;

use App\Service\Auth\Session;

/** Résultat de la confirmation de l'enrôlement : la session ouverte et les codes de secours, en clair, à montrer une seule fois. */
final class ActivationA2f
{
    /** @param list<string> $codesSecours */
    public function __construct(
        public readonly Session $session,
        public readonly array $codesSecours,
    ) {
    }

    public function __debugInfo(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\A2f;

/** Données d'enrôlement montrées une seule fois à l'utilisateur : QR code (URI otpauth) et secret pour la saisie manuelle. */
final class EnrolementA2f
{
    public function __construct(
        public readonly string $secret,
        public readonly string $uri,
    ) {
    }

    public function __debugInfo(): array
    {
        return [];   // le secret ne sort jamais dans un var_dump ni dans une trace
    }
}

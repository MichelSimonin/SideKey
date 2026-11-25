<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** Issue d'une rotation du jeton de rafraîchissement. */
final class ResultatRotation
{
    private function __construct(
        public readonly Rotation $issue,
        public readonly ?string $utilisateurId = null,
        public readonly ?string $familleId = null,
    ) {
    }

    public static function reussie(string $utilisateurId, string $familleId): self
    {
        return new self(Rotation::Reussie, $utilisateurId, $familleId);
    }

    public static function reutilisation(string $familleId): self
    {
        return new self(Rotation::Reutilisation, null, $familleId);
    }

    public static function inconnu(): self
    {
        return new self(Rotation::Inconnu);
    }
}

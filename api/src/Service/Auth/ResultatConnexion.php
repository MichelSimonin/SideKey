<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Issue d'une connexion par mot de passe : session ouverte, ou seconde étape requise (A2F, F-04).
 * Dans ce second cas, seul un jeton intermédiaire à portée limitée est remis : aucun jeton d'accès
 * ni de rafraîchissement n'existe tant que le code TOTP n'a pas été vérifié.
 */
final class ResultatConnexion
{
    private function __construct(
        public readonly ?Session $session,
        public readonly ?string $jetonIntermediaire,
        public readonly int $intermediaireExpireDans = 0,
    ) {
    }

    public static function connecte(Session $session): self
    {
        return new self($session, null);
    }

    public static function secondeEtape(string $jetonIntermediaire, int $expireDans): self
    {
        return new self(null, $jetonIntermediaire, $expireDans);
    }

    public function exigeA2f(): bool
    {
        return null !== $this->jetonIntermediaire;
    }
}

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
        public readonly bool $enrolement = false,
    ) {
    }

    public static function connecte(Session $session): self
    {
        return new self($session, null);
    }

    /**
     * @param bool $enrolement vrai quand l'A2F est obligatoire mais pas encore configurée (rôle du back-office) : la seconde
     *                         étape est alors l'enrôlement TOTP, pas la saisie d'un code
     */
    public static function secondeEtape(string $jetonIntermediaire, int $expireDans, bool $enrolement = false): self
    {
        return new self(null, $jetonIntermediaire, $expireDans, $enrolement);
    }

    public function exigeA2f(): bool
    {
        return null !== $this->jetonIntermediaire;
    }
}

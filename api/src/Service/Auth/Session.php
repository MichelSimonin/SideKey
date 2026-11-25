<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Session ouverte ou renouvelée : un jeton d'accès (corps de la réponse, gardé en mémoire par la SPA)
 * et un jeton de rafraîchissement opaque (cookie HttpOnly, jamais lisible par le JavaScript).
 */
final class Session
{
    public function __construct(
        public readonly Compte $compte,
        public readonly string $jetonAcces,
        public readonly int $expireDans,
        public readonly string $jetonRefresh,
    ) {
    }
}

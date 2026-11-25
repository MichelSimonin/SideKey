<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Liste de révocation des jetons d'accès (CDC §9.8 : jwt:deny:{jti}, jusqu'à l'expiration du jeton).
 * Implémentation : Redis. Une indisponibilité de Redis lève une exception : le jeton est alors refusé
 * (Fail Securely, ADR-0003).
 */
interface RevocationJwt
{
    /** Révoque un jeton précis ; $ttlSecondes = durée de vie restante du jeton. */
    public function revoquerJeton(string $jti, int $ttlSecondes): void;

    /** Révoque tous les jetons d'accès d'une session (déconnexion, vol de refresh détecté). */
    public function revoquerFamille(string $familleId, int $ttlSecondes): void;

    public function estRevoque(string $jti, string $familleId): bool;
}

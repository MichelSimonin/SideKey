<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Auth\RevocationJwt;

/**
 * Liste de révocation des jetons d'accès (CDC §9.8).
 *
 *   jwt:deny:{jti}          jeton révoqué, TTL = durée de vie restante du jeton
 *   jwt:deny:fam:{famille}  session révoquée (déconnexion, vol de refresh), TTL = durée de vie d'un jeton d'accès
 *
 * Les identifiants viennent d'un jeton déjà vérifié mais sont revalidés avant d'entrer dans une clé.
 */
final class RedisRevocationJwt implements RevocationJwt
{
    private const JTI = '/^[0-9a-f]{32}$/';
    private const FAMILLE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function revoquerJeton(string $jti, int $ttlSecondes): void
    {
        $this->redis->set($this->cleJeton($jti), '1', ['ex' => max(1, $ttlSecondes)]);
    }

    public function revoquerFamille(string $familleId, int $ttlSecondes): void
    {
        $this->redis->set($this->cleFamille($familleId), '1', ['ex' => max(1, $ttlSecondes)]);
    }

    public function estRevoque(string $jti, string $familleId): bool
    {
        // Un seul aller-retour : EXISTS compte les clés présentes.
        $presentes = $this->redis->exists($this->cleJeton($jti), $this->cleFamille($familleId));

        return \is_int($presentes) && $presentes > 0;
    }

    private function cleJeton(string $jti): string
    {
        if (1 !== preg_match(self::JTI, $jti)) {
            throw new \InvalidArgumentException('Identifiant de jeton invalide.');
        }

        return 'jwt:deny:'.$jti;
    }

    private function cleFamille(string $familleId): string
    {
        if (1 !== preg_match(self::FAMILLE, $familleId)) {
            throw new \InvalidArgumentException('Identifiant de famille invalide.');
        }

        return 'jwt:deny:fam:'.$familleId;
    }
}

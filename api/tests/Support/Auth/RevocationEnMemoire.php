<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Service\Auth\RevocationJwt;

/** Doublure de la liste de révocation Redis. */
final class RevocationEnMemoire implements RevocationJwt
{
    /** @var array<string, int> jti → TTL demandé */
    public array $jetons = [];

    /** @var array<string, int> famille → TTL demandé */
    public array $familles = [];

    public bool $indisponible = false;

    public function revoquerJeton(string $jti, int $ttlSecondes): void
    {
        $this->jetons[$jti] = $ttlSecondes;
    }

    public function revoquerFamille(string $familleId, int $ttlSecondes): void
    {
        $this->familles[$familleId] = $ttlSecondes;
    }

    public function estRevoque(string $jti, string $familleId): bool
    {
        if ($this->indisponible) {
            throw new \RedisException('Redis indisponible');
        }

        return isset($this->jetons[$jti]) || isset($this->familles[$familleId]);
    }
}

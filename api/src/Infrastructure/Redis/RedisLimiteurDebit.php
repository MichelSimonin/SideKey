<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Exception\TropDeTentativesException;
use App\Service\Auth\LimiteurDebit;

/**
 * Limitation de débit à fenêtre fixe (CDC §9.8 : rl:login:{ip}, rl:register:{ip}).
 *
 * Le compteur et son expiration sont posés par un script Lua : l'incrément et le TTL sont atomiques, donc
 * jamais de compteur sans expiration (qui bloquerait une adresse pour toujours) et pas de course entre deux
 * requêtes simultanées. La fenêtre démarre à la première tentative et n'est pas prolongée par les suivantes.
 *
 * Redis indisponible : l'exception remonte (500 générique) et la tentative n'est pas traitée. Pas de repli
 * « sans limite » (Fail Securely).
 */
final class RedisLimiteurDebit implements LimiteurDebit
{
    private const CLE = '/^rl:[a-z]+:[0-9a-fA-F.:]{2,45}$|^rl:[a-z]+:inconnue$/';

    private const SCRIPT = <<<'LUA'
        local compteur = redis.call('INCR', KEYS[1])
        if compteur == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return {compteur, redis.call('TTL', KEYS[1])}
        LUA;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function enregistrerTentative(string $cle, int $max, int $fenetreSecondes): void
    {
        if (1 !== preg_match(self::CLE, $cle)) {
            throw new \InvalidArgumentException('Clé de limitation invalide.');
        }

        $resultat = $this->redis->eval(self::SCRIPT, [$cle, (string) $fenetreSecondes], 1);
        if (!\is_array($resultat)) {
            throw new \RuntimeException('Limitation de débit : réponse Redis inattendue.');
        }

        if ((int) $resultat[0] > $max) {
            $restant = (int) $resultat[1];
            throw new TropDeTentativesException($restant > 0 ? $restant : $fenetreSecondes);
        }
    }
}

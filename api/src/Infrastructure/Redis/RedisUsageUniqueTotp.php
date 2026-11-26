<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\A2f\UsageUniqueTotp;

/**
 * a2f:used:{userId}:{pas} (String, TTL 120 s) posé avec SET NX : l'écriture et le contrôle sont un seul ordre atomique, donc
 * deux requêtes simultanées avec le même code ne passent pas toutes les deux. 120 s couvrent la fenêtre de validité d'un
 * code (±1 pas = 90 s). Redis indisponible : l'exception remonte, la vérification échoue (Fail Securely).
 */
final class RedisUsageUniqueTotp implements UsageUniqueTotp
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const TTL = 120;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function reserver(string $utilisateurId, int $pas): bool
    {
        if (1 !== preg_match(self::UUID, $utilisateurId) || $pas < 0) {
            throw new \InvalidArgumentException('Réservation de code TOTP invalide.');
        }

        return true === $this->redis->set('a2f:used:'.$utilisateurId.':'.$pas, '1', ['nx', 'ex' => self::TTL]);
    }
}

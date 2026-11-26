<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Paiement\Idempotence;

/**
 * idem:{empreinte} (String, TTL 24 h, CDC §9.8), posé avec SET NX : l'écriture et le contrôle sont un seul ordre atomique,
 * deux requêtes simultanées avec la même clé ne passent pas toutes les deux. L'empreinte (SHA-256 de « client + clé ») est
 * la seule valeur qui entre dans la clé Redis : le texte envoyé par le client n'y figure jamais.
 * Redis indisponible : l'exception remonte et le paiement est refusé (Fail Securely).
 */
final class RedisIdempotence implements Idempotence
{
    private const EMPREINTE = '/^[0-9a-f]{64}$/';
    private const TTL = 86_400;

    public function __construct(private readonly \Redis $redis)
    {
    }

    public function reserver(string $empreinte): bool
    {
        return true === $this->redis->set('idem:'.self::valide($empreinte), '1', ['nx', 'ex' => self::TTL]);
    }

    public function liberer(string $empreinte): void
    {
        $this->redis->del('idem:'.self::valide($empreinte));
    }

    private static function valide(string $empreinte): string
    {
        if (1 !== preg_match(self::EMPREINTE, $empreinte)) {
            throw new \InvalidArgumentException('Empreinte d\'idempotence invalide.');
        }

        return $empreinte;
    }
}

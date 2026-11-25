<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Auth\ResultatRotation;
use App\Service\Auth\StockRefresh;

/**
 * Jetons de rafraîchissement dans Redis (CDC §9.8).
 *
 *   refresh:{empreinte}         String  "{userId}:{familleId}"   jeton actif, TTL 7 j
 *   refresh:used:{empreinte}    String  "{familleId}"            jeton consommé, TTL 7 j (détection de réutilisation)
 *   refresh:family:{familleId}  Set     empreintes de la famille TTL 7 j (révocation de toute la session)
 *
 * {empreinte} est le SHA-256 du jeton : Redis ne contient jamais un jeton utilisable.
 * La rotation est un script Lua : Redis l'exécute d'un bloc, donc deux requêtes simultanées avec le même
 * jeton ne peuvent pas toutes deux réussir (une seule consomme le jeton, l'autre voit « déjà consommé »).
 */
final class RedisStockRefresh implements StockRefresh
{
    private const FAMILLE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const EMPREINTE = '/^[0-9a-f]{64}$/';

    /**
     * KEYS[1] jeton actif · KEYS[2] marqueur « consommé » · KEYS[3] successeur
     * ARGV[1] TTL · ARGV[2] préfixe « refresh: » · ARGV[3] empreinte du successeur · ARGV[4] empreinte de l'ancien.
     */
    private const ROTATION = <<<'LUA'
        local actif = redis.call('GET', KEYS[1])
        if actif then
            local separateur = string.find(actif, ':', 1, true)
            local utilisateur = string.sub(actif, 1, separateur - 1)
            local famille = string.sub(actif, separateur + 1)
            local cleFamille = ARGV[2] .. 'family:' .. famille
            redis.call('SET', KEYS[3], actif, 'EX', ARGV[1])
            redis.call('DEL', KEYS[1])
            redis.call('SET', KEYS[2], famille, 'EX', ARGV[1])
            redis.call('SADD', cleFamille, ARGV[3])
            redis.call('EXPIRE', cleFamille, ARGV[1])
            return {1, utilisateur, famille}
        end
        local famille = redis.call('GET', KEYS[2])
        if famille then
            local cleFamille = ARGV[2] .. 'family:' .. famille
            for _, membre in ipairs(redis.call('SMEMBERS', cleFamille)) do
                redis.call('DEL', ARGV[2] .. membre)
            end
            redis.call('DEL', cleFamille)
            return {-1, '', famille}
        end
        return {0, '', ''}
        LUA;

    private const REVOCATION = <<<'LUA'
        for _, membre in ipairs(redis.call('SMEMBERS', KEYS[1])) do
            redis.call('DEL', ARGV[1] .. membre)
        end
        return redis.call('DEL', KEYS[1])
        LUA;

    public function __construct(
        private readonly \Redis $redis,
        private readonly ?int $ttlForce = null,   // tests uniquement : expiration courte
    ) {
    }

    public function enregistrer(string $empreinte, string $utilisateurId, string $familleId): void
    {
        $cleFamille = $this->cleFamille($familleId);
        $ttl = $this->ttl();

        $this->redis->multi()
            ->set($this->cleJeton($empreinte), $utilisateurId.':'.$familleId, ['ex' => $ttl])
            ->sAdd($cleFamille, $empreinte)
            ->expire($cleFamille, $ttl)
            ->exec();
    }

    public function faireTourner(string $ancienneEmpreinte, string $nouvelleEmpreinte): ResultatRotation
    {
        $resultat = $this->redis->eval(
            self::ROTATION,
            [
                $this->cleJeton($ancienneEmpreinte),
                'refresh:used:'.$this->empreinte($ancienneEmpreinte),
                $this->cleJeton($nouvelleEmpreinte),
                (string) $this->ttl(),
                'refresh:',
                $this->empreinte($nouvelleEmpreinte),
                $this->empreinte($ancienneEmpreinte),
            ],
            3,
        );
        if (!\is_array($resultat)) {
            throw new \RuntimeException('Rotation du jeton de rafraîchissement : réponse Redis inattendue.');
        }

        return match ((int) $resultat[0]) {
            1 => ResultatRotation::reussie((string) $resultat[1], (string) $resultat[2]),
            -1 => ResultatRotation::reutilisation((string) $resultat[2]),
            default => ResultatRotation::inconnu(),
        };
    }

    public function revoquerFamille(string $familleId): void
    {
        $this->redis->eval(self::REVOCATION, [$this->cleFamille($familleId), 'refresh:'], 1);
    }

    private function ttl(): int
    {
        return $this->ttlForce ?? self::TTL;
    }

    private function cleJeton(string $empreinte): string
    {
        return 'refresh:'.$this->empreinte($empreinte);
    }

    private function cleFamille(string $familleId): string
    {
        if (1 !== preg_match(self::FAMILLE, $familleId)) {
            throw new \InvalidArgumentException('Identifiant de famille invalide.');
        }

        return 'refresh:family:'.$familleId;
    }

    /** L'empreinte entre dans une clé Redis : seule une valeur hexadécimale de 64 caractères est acceptée. */
    private function empreinte(string $empreinte): string
    {
        if (1 !== preg_match(self::EMPREINTE, $empreinte)) {
            throw new \InvalidArgumentException('Empreinte de jeton invalide.');
        }

        return $empreinte;
    }
}

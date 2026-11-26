<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Paiement\CompteursFraude;
use App\Service\Paiement\ConfigurationFraude;
use Psr\Clock\ClockInterface;

/**
 * Compteurs anti-fraude dans Redis (CDC §9.8 : fraud:*). Aucune donnée de référence : leur perte remet les compteurs à zéro,
 * jamais une commande ou une clé en cause. Redis indisponible : l'exception remonte et PaiementService REFUSE le paiement
 * (Fail Securely, ADR-0008).
 *
 *   fraud:keys24h:{userId}  ensemble trié : un membre « {commandeId}:{n} » par clé payée, score = instant du paiement.
 *                           Une vraie fenêtre GLISSANTE de 24 h (on compte les scores des 86 400 dernières secondes) et non
 *                           un compteur à TTL, qui repartirait de zéro à une heure fixe et laisserait passer 2 × 10 clés
 *                           autour du changement de fenêtre. TTL de 24 h : la clé disparaît quand elle n'a plus rien à compter.
 *   fraud:fails:{userId}    compteur d'échecs de paiement, TTL = durée du blocage (fenêtre qui démarre au premier échec).
 *   fraud:block:{userId}    présent = client bloqué ; son TTL est le temps de blocage restant.
 */
final class RedisCompteursFraude implements CompteursFraude
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const FENETRE_CLES = 86_400;

    /** Incrément, expiration et pose du blocage en UN script : pas de compteur sans TTL, pas de course entre deux échecs. */
    private const SCRIPT_ECHEC = <<<'LUA'
        local echecs = redis.call('INCR', KEYS[1])
        if echecs == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        if echecs >= tonumber(ARGV[2]) then
            redis.call('SET', KEYS[2], '1', 'EX', ARGV[1])
            redis.call('DEL', KEYS[1])
            return 0
        end
        return tonumber(ARGV[2]) - echecs
        LUA;

    public function __construct(
        private readonly \Redis $redis,
        private readonly ConfigurationFraude $configuration,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function secondesDeBlocage(string $utilisateurId): ?int
    {
        $ttl = $this->redis->ttl('fraud:block:'.self::utilisateur($utilisateurId));

        // -2 : clé absente. -1 : clé sans expiration (ne devrait pas exister) : bloqué, pour la durée normale
        return match (true) {
            $ttl > 0 => $ttl,
            -1 === $ttl => $this->configuration->blocageSecondes,
            default => null,
        };
    }

    public function clesAcheteesSur24h(string $utilisateurId): int
    {
        $depuis = $this->horloge->now()->getTimestamp() - self::FENETRE_CLES;

        // « ( » : borne exclusive, une clé payée il y a exactement 24 h ne compte plus
        return (int) $this->redis->zCount('fraud:keys24h:'.self::utilisateur($utilisateurId), '('.$depuis, '+inf');
    }

    public function enregistrerClesAchetees(string $utilisateurId, string $commandeId, int $nombre): void
    {
        if (1 !== preg_match(self::UUID, $commandeId) || $nombre < 1) {
            throw new \InvalidArgumentException('Enregistrement de clés achetées invalide.');
        }
        $cle = 'fraud:keys24h:'.self::utilisateur($utilisateurId);
        $maintenant = $this->horloge->now()->getTimestamp();

        $this->redis->zRemRangeByScore($cle, '-inf', (string) ($maintenant - self::FENETRE_CLES));   // ménage des échéances passées
        for ($i = 1; $i <= $nombre; ++$i) {
            // NX : rejouer la même commande ne compte pas deux fois
            $this->redis->zAdd($cle, ['NX'], (float) $maintenant, $commandeId.':'.$i);
        }
        $this->redis->expire($cle, self::FENETRE_CLES);
    }

    public function enregistrerEchec(string $utilisateurId): int
    {
        $id = self::utilisateur($utilisateurId);
        $restantes = $this->redis->eval(
            self::SCRIPT_ECHEC,
            ['fraud:fails:'.$id, 'fraud:block:'.$id, (string) $this->configuration->blocageSecondes, (string) $this->configuration->echecsMax],
            2,
        );
        if (!\is_int($restantes)) {
            throw new \RuntimeException('Anti-fraude : réponse Redis inattendue.');
        }

        return $restantes;
    }

    public function reinitialiserEchecs(string $utilisateurId): void
    {
        $this->redis->del('fraud:fails:'.self::utilisateur($utilisateurId));
    }

    /** Une clé Redis n'est jamais construite à partir d'une valeur non validée. */
    private static function utilisateur(string $id): string
    {
        if (1 !== preg_match(self::UUID, $id)) {
            throw new \InvalidArgumentException('Identifiant de client invalide.');
        }

        return $id;
    }
}

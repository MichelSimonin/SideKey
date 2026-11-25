<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Panier\PanierId;
use App\Service\Panier\PanierStore;

/**
 * Panier dans Redis (CDC §9.8) : un hash par panier, champ = identifiant d'offre, valeur = quantité.
 * Chaque écriture repousse l'expiration (TTL glissant : 24 h visiteur, 7 j client) ; un panier
 * abandonné disparaît sans tâche de purge. Écriture et expiration partent dans une même
 * transaction Redis (MULTI / EXEC) : pas de panier sans TTL.
 */
final class RedisPanierStore implements PanierStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly ?int $ttlForce = null,   // tests uniquement : expiration courte
    ) {
    }

    public function lire(PanierId $panier): array
    {
        $contenu = $this->redis->hGetAll($panier->cle);
        if (!\is_array($contenu)) {
            return [];
        }

        $lignes = [];
        foreach ($contenu as $offreId => $quantite) {
            $quantite = (int) $quantite;
            if ($quantite > 0) {
                $lignes[(string) $offreId] = $quantite;
            }
        }

        return $lignes;
    }

    public function definir(PanierId $panier, string $offreId, int $quantite): void
    {
        $this->redis->multi()
            ->hSet($panier->cle, $offreId, (string) $quantite)
            ->expire($panier->cle, $this->ttl($panier))
            ->exec();
    }

    public function retirer(PanierId $panier, string $offreId): void
    {
        $this->redis->multi()
            ->hDel($panier->cle, $offreId)
            ->expire($panier->cle, $this->ttl($panier))
            ->exec();
    }

    public function vider(PanierId $panier): void
    {
        $this->redis->del($panier->cle);
    }

    private function ttl(PanierId $panier): int
    {
        return $this->ttlForce ?? $panier->ttl;
    }
}

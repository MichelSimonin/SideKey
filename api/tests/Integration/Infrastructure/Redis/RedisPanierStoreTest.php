<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisPanierStore;
use App\Service\Panier\PanierId;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Composant d'accès Redis du panier, contre un vrai serveur Redis (F-05).
 * Chaque test travaille sur un panier au nom aléatoire et le supprime ensuite.
 */
#[Group('redis')]
final class RedisPanierStoreTest extends KernelTestCase
{
    private \Redis $redis;
    private PanierId $panier;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->panier = PanierId::visiteur(sprintf('%s-%s-4%s-8%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
            substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        $this->redis->del($this->panier->cle);
        parent::tearDown();
    }

    public function testEcritLitEtRetire(): void
    {
        $store = new RedisPanierStore($this->redis);

        $store->definir($this->panier, 'offre-a', 2);
        $store->definir($this->panier, 'offre-b', 1);
        $store->definir($this->panier, 'offre-a', 3);
        self::assertSame(['offre-a' => 3, 'offre-b' => 1], $store->lire($this->panier));

        $store->retirer($this->panier, 'offre-b');
        self::assertSame(['offre-a' => 3], $store->lire($this->panier));

        $store->vider($this->panier);
        self::assertSame([], $store->lire($this->panier));
        self::assertSame(0, $this->redis->exists($this->panier->cle));
    }

    public function testStockeUnHashAvecLeTtlDuVisiteur(): void
    {
        (new RedisPanierStore($this->redis))->definir($this->panier, 'offre-a', 1);

        self::assertSame(\Redis::REDIS_HASH, $this->redis->type($this->panier->cle));
        $ttl = $this->redis->ttl($this->panier->cle);
        self::assertGreaterThan(86_400 - 5, $ttl);
        self::assertLessThanOrEqual(86_400, $ttl);
    }

    public function testChaqueEcritureRepousseLExpiration(): void
    {
        $store = new RedisPanierStore($this->redis);
        $store->definir($this->panier, 'offre-a', 1);
        $this->redis->expire($this->panier->cle, 60);   // panier presque expiré

        $store->definir($this->panier, 'offre-b', 1);

        self::assertGreaterThan(86_400 - 5, $this->redis->ttl($this->panier->cle));
    }

    public function testLePanierExpireTout(): void
    {
        $store = new RedisPanierStore($this->redis, ttlForce: 1);
        $store->definir($this->panier, 'offre-a', 1);
        self::assertSame(['offre-a' => 1], $store->lire($this->panier));

        usleep(1_200_000);

        self::assertSame([], $store->lire($this->panier), 'le panier a expiré sans aucune purge applicative');
    }
}

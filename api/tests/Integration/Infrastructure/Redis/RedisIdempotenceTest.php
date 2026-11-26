<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisIdempotence;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** CDC §9.8 : idem:{empreinte}, 24 h, SET NX atomique. */
#[Group('redis')]
final class RedisIdempotenceTest extends KernelTestCase
{
    private const EMPREINTE = 'f07abcde01234567f07abcde01234567f07abcde01234567f07abcde01234567';

    private \Redis $redis;
    private RedisIdempotence $idempotence;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->redis->del('idem:'.self::EMPREINTE);
        $this->idempotence = new RedisIdempotence($redis);
    }

    protected function tearDown(): void
    {
        $this->redis->del('idem:'.self::EMPREINTE);
        parent::tearDown();
    }

    public function testSeulLePremierAppelantObtientLaCle(): void
    {
        self::assertTrue($this->idempotence->reserver(self::EMPREINTE));
        self::assertFalse($this->idempotence->reserver(self::EMPREINTE), 'une requête simultanée avec la même clé est refusée');
        self::assertFalse($this->idempotence->reserver(self::EMPREINTE));
    }

    public function testLaCleExpireApres24Heures(): void
    {
        $this->idempotence->reserver(self::EMPREINTE);

        $ttl = $this->redis->ttl('idem:'.self::EMPREINTE);
        self::assertGreaterThan(86_300, $ttl);
        self::assertLessThanOrEqual(86_400, $ttl);
    }

    public function testRendreLaCleLaRendReutilisable(): void
    {
        $this->idempotence->reserver(self::EMPREINTE);
        $this->idempotence->liberer(self::EMPREINTE);

        self::assertTrue($this->idempotence->reserver(self::EMPREINTE));
    }

    public function testSeulUneEmpreinteDeSoixanteQuatreHexadecimauxEntreDansUneCleRedis(): void
    {
        foreach (['', 'abc', '0123456789abcdef-clé-du-client', str_repeat('A', 64), str_repeat('g', 64), self::EMPREINTE.'*', "x\r\nFLUSHALL"] as $forge) {
            try {
                $this->idempotence->reserver($forge);
                self::fail('Une valeur forgée aurait dû être refusée.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, $this->redis->exists('idem:'.str_repeat('A', 64)));
    }
}

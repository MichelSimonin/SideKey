<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisUsageUniqueTotp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** a2f:used:{userId}:{pas} : réservation atomique (SET NX) d'un pas TOTP, contre un vrai Redis. */
#[Group('redis')]
final class RedisUsageUniqueTotpTest extends KernelTestCase
{
    private const COMPTE = 'a2f0a2f0-0000-4000-8000-00000000cc01';
    private const AUTRE = 'a2f0a2f0-0000-4000-8000-00000000cc02';

    private \Redis $redis;
    private RedisUsageUniqueTotp $usages;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->nettoyer();
        $this->usages = new RedisUsageUniqueTotp($redis);
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testUnPasNeSeReserveQuUneFois(): void
    {
        self::assertTrue($this->usages->reserver(self::COMPTE, 57_000_000));
        self::assertFalse($this->usages->reserver(self::COMPTE, 57_000_000), 'le même code rejoué est refusé');
    }

    public function testLeStockageEstUneCleAvecUnTtlDeCentVingtSecondes(): void
    {
        $this->usages->reserver(self::COMPTE, 57_000_001);

        $cle = 'a2f:used:'.self::COMPTE.':57000001';
        self::assertSame(\Redis::REDIS_STRING, $this->redis->type($cle));
        self::assertEqualsWithDelta(120, $this->redis->ttl($cle), 5, 'couvre la fenêtre de ±1 pas (90 s)');
    }

    public function testLesPasEtLesComptesSontIndependants(): void
    {
        self::assertTrue($this->usages->reserver(self::COMPTE, 57_000_002));
        self::assertTrue($this->usages->reserver(self::COMPTE, 57_000_003), 'autre pas');
        self::assertTrue($this->usages->reserver(self::AUTRE, 57_000_002), 'autre compte');
    }

    public function testLaReservationExpireAvecLaFenetre(): void
    {
        $this->usages->reserver(self::COMPTE, 57_000_004);
        $this->redis->expire('a2f:used:'.self::COMPTE.':57000004', 1);
        usleep(1_300_000);

        self::assertTrue($this->usages->reserver(self::COMPTE, 57_000_004), 'plus de trace après la fenêtre : Redis ne grossit pas');
    }

    public function testDeuxReservationsSimultanees_UneSeuleReussit(): void
    {
        $reussites = 0;
        for ($i = 0; $i < 20; ++$i) {
            $reussites += $this->usages->reserver(self::COMPTE, 57_000_005) ? 1 : 0;
        }

        self::assertSame(1, $reussites, 'SET NX est atomique');
    }

    /** @return iterable<string, array{string, int}> */
    public static function valeursForgees(): iterable
    {
        yield 'identifiant injecté' => ["x\r\nFLUSHALL", 1];
        yield 'joker' => ['*', 1];
        yield 'vide' => ['', 1];
        yield 'majuscules' => [strtoupper(self::COMPTE), 1];
        yield 'pas négatif' => [self::COMPTE, -1];
    }

    #[DataProvider('valeursForgees')]
    public function testValeursForgeesRefusees(string $compte, int $pas): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->usages->reserver($compte, $pas);
    }

    private function nettoyer(): void
    {
        foreach ([self::COMPTE, self::AUTRE] as $compte) {
            foreach ((array) $this->redis->keys('a2f:used:'.$compte.':*') as $cle) {
                $this->redis->del((string) $cle);
            }
        }
    }
}

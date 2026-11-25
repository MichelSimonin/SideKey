<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisMinuteurReservation;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[Group('redis')]
final class RedisMinuteurReservationTest extends KernelTestCase
{
    private const COMMANDE = '5a1d2c3e-0000-4000-8000-00000000f005';

    private \Redis $redis;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->redis->del('resa:'.self::COMMANDE);
    }

    protected function tearDown(): void
    {
        $this->redis->del('resa:'.self::COMMANDE);
        parent::tearDown();
    }

    public function testReservationDeQuinzeMinutes(): void
    {
        $maintenant = new \DateTimeImmutable('2026-11-25 10:00:00');
        $minuteur = new RedisMinuteurReservation($this->redis, new MockClock($maintenant));

        $minuteur->demarrer(self::COMMANDE, $maintenant->modify('+15 minutes'));

        self::assertSame(\Redis::REDIS_STRING, $this->redis->type('resa:'.self::COMMANDE));
        $restant = $minuteur->secondesRestantes(self::COMMANDE);
        self::assertNotNull($restant);
        self::assertGreaterThan(895, $restant);
        self::assertLessThanOrEqual(900, $restant);
    }

    public function testLeMinuteurSuitLEcheanceDeLaCommande(): void
    {
        $maintenant = new \DateTimeImmutable('2026-11-25 10:00:00');
        $minuteur = new RedisMinuteurReservation($this->redis, new MockClock($maintenant));

        $minuteur->demarrer(self::COMMANDE, $maintenant->modify('+2 seconds'));
        self::assertLessThanOrEqual(2, $minuteur->secondesRestantes(self::COMMANDE));

        usleep(2_200_000);
        self::assertNull($minuteur->secondesRestantes(self::COMMANDE), 'réservation libérée à l\'expiration');
    }

    public function testArreterEtRefusDesValeursInvalides(): void
    {
        $maintenant = new \DateTimeImmutable();
        $minuteur = new RedisMinuteurReservation($this->redis, new MockClock($maintenant));
        $minuteur->demarrer(self::COMMANDE, $maintenant->modify('+15 minutes'));

        $minuteur->arreter(self::COMMANDE);
        self::assertNull($minuteur->secondesRestantes(self::COMMANDE));

        try {
            $minuteur->demarrer(self::COMMANDE, $maintenant->modify('-1 second'));
            self::fail('Une échéance passée doit être refusée');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $minuteur->secondesRestantes('resa:*');
    }
}

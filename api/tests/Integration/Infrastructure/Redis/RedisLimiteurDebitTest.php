<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Exception\TropDeTentativesException;
use App\Infrastructure\Redis\RedisLimiteurDebit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** rl:login:{ip} : compteur à fenêtre fixe, atomique, avec TTL, contre un vrai Redis. */
#[Group('redis')]
final class RedisLimiteurDebitTest extends KernelTestCase
{
    private const CLE = 'rl:login:198.51.100.77';

    private \Redis $redis;
    private RedisLimiteurDebit $limiteur;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->redis->del(self::CLE, 'rl:login:2001:db8::1', 'rl:login:inconnue');
        $this->limiteur = new RedisLimiteurDebit($this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->del(self::CLE, 'rl:login:2001:db8::1', 'rl:login:inconnue');
        parent::tearDown();
    }

    public function testLesTentativesJusquAuMaximumPassent(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->limiteur->enregistrerTentative(self::CLE, 3, 900);
        }

        self::assertSame('3', $this->redis->get(self::CLE));
    }

    public function testLaTentativeSuivanteEstBloqueeAvecLeDelaiRestant(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->limiteur->enregistrerTentative(self::CLE, 3, 900);
        }

        try {
            $this->limiteur->enregistrerTentative(self::CLE, 3, 900);
            self::fail('La 4e tentative doit être bloquée.');
        } catch (TropDeTentativesException $e) {
            self::assertSame(429, $e->codeHttp());
            self::assertGreaterThan(890, $e->reessayerDans());
            self::assertLessThanOrEqual(900, $e->reessayerDans());
            self::assertSame((string) $e->reessayerDans(), $e->entetes()['Retry-After']);
        }
    }

    public function testLaFenetreEstDe15MinutesEtNEstPasProlongeeParLesTentatives(): void
    {
        $this->limiteur->enregistrerTentative(self::CLE, 10, 900);
        $ttl = $this->redis->ttl(self::CLE);
        self::assertGreaterThan(895, $ttl);
        self::assertLessThanOrEqual(900, $ttl);

        $this->redis->expire(self::CLE, 100);   // simule 800 s écoulées
        $this->limiteur->enregistrerTentative(self::CLE, 10, 900);

        self::assertLessThanOrEqual(100, $this->redis->ttl(self::CLE), 'une nouvelle tentative ne repousse pas l\'expiration (fenêtre fixe)');
    }

    public function testToujoursUnTtlJamaisDeCompteurEternel(): void
    {
        $this->limiteur->enregistrerTentative(self::CLE, 10, 900);

        self::assertGreaterThan(0, $this->redis->ttl(self::CLE), 'un compteur sans TTL bloquerait l\'adresse pour toujours');
    }

    public function testLeCompteurRepartApresLaFenetre(): void
    {
        for ($i = 1; $i <= 2; ++$i) {
            $this->limiteur->enregistrerTentative(self::CLE, 2, 1);
        }
        usleep(1_300_000);

        $this->limiteur->enregistrerTentative(self::CLE, 2, 1);   // nouvelle fenêtre : aucune exception

        self::assertSame('1', $this->redis->get(self::CLE));
    }

    public function testChaqueAdresseALeSienCompteur(): void
    {
        for ($i = 1; $i <= 2; ++$i) {
            $this->limiteur->enregistrerTentative(self::CLE, 2, 900);
        }

        $this->limiteur->enregistrerTentative('rl:login:2001:db8::1', 2, 900);   // IPv6 : pas affecté
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function clesForgees(): iterable
    {
        yield 'sans préfixe' => ['login:1.2.3.4'];
        yield 'injection' => ["rl:login:1.2.3.4\r\nFLUSHALL"];
        yield 'joker' => ['rl:login:*'];
        yield 'espace' => ['rl:login:1.2.3.4 5'];
        yield 'vide' => [''];
    }

    #[DataProvider('clesForgees')]
    public function testCleForgeeRefusee(string $cle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->limiteur->enregistrerTentative($cle, 10, 900);
    }

    public function testAdresseInconnueAUneCleCommune(): void
    {
        $this->limiteur->enregistrerTentative('rl:login:inconnue', 10, 900);
        self::assertSame('1', $this->redis->get('rl:login:inconnue'));
    }
}

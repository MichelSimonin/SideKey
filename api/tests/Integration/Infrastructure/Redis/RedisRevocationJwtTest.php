<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisRevocationJwt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** jwt:deny:{jti} : liste de révocation des jetons d'accès, contre un vrai Redis. */
#[Group('redis')]
final class RedisRevocationJwtTest extends KernelTestCase
{
    private const JTI = 'abcdef0123456789abcdef0123456789';
    private const AUTRE_JTI = '00112233445566778899aabbccddeeff';
    private const FAMILLE = 'f0f0f0f0-0000-4000-8000-00000000bbb1';

    private \Redis $redis;
    private RedisRevocationJwt $revocation;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->nettoyer();
        $this->revocation = new RedisRevocationJwt($this->redis);
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testUnJetonNonRevoqueEstAccepte(): void
    {
        self::assertFalse($this->revocation->estRevoque(self::JTI, self::FAMILLE));
    }

    public function testRevoquerUnJetonJusquASonExpiration(): void
    {
        $this->revocation->revoquerJeton(self::JTI, 600);

        self::assertTrue($this->revocation->estRevoque(self::JTI, self::FAMILLE));
        self::assertSame(\Redis::REDIS_STRING, $this->redis->type('jwt:deny:'.self::JTI), 'String (CDC §9.8)');
        self::assertEqualsWithDelta(600, $this->redis->ttl('jwt:deny:'.self::JTI), 5, 'TTL = durée de vie restante du jeton');
        self::assertFalse($this->revocation->estRevoque(self::AUTRE_JTI, self::FAMILLE), 'les autres jetons ne sont pas touchés');
    }

    public function testRevoquerUneSessionRefuseTousSesJetons(): void
    {
        $this->revocation->revoquerFamille(self::FAMILLE, 900);

        self::assertTrue($this->revocation->estRevoque(self::JTI, self::FAMILLE));
        self::assertTrue($this->revocation->estRevoque(self::AUTRE_JTI, self::FAMILLE));
        self::assertFalse($this->revocation->estRevoque(self::JTI, 'f0f0f0f0-0000-4000-8000-00000000bbb2'));
        self::assertEqualsWithDelta(900, $this->redis->ttl('jwt:deny:fam:'.self::FAMILLE), 5);
    }

    public function testLaRevocationExpireAvecLeJeton(): void
    {
        $this->revocation->revoquerJeton(self::JTI, 1);
        usleep(1_300_000);

        self::assertFalse($this->revocation->estRevoque(self::JTI, self::FAMILLE), 'plus de trace une fois le jeton expiré : Redis ne grossit pas');
    }

    public function testUnTtlNulDonneQuandMemeUneExpiration(): void
    {
        $this->revocation->revoquerJeton(self::JTI, 0);   // jeton qui expire à l'instant

        self::assertGreaterThan(0, $this->redis->ttl('jwt:deny:'.self::JTI), 'jamais de clé sans expiration');
    }

    /** @return iterable<string, array{string, string}> */
    public static function identifiantsForges(): iterable
    {
        yield 'jti court' => ['abc', self::FAMILLE];
        yield 'jti en majuscules' => [strtoupper(self::JTI), self::FAMILLE];
        yield 'jti avec saut de ligne' => [self::JTI."\r\nDEL x", self::FAMILLE];
        yield 'famille forgée' => [self::JTI, '*'];
        yield 'famille vide' => [self::JTI, ''];
    }

    #[DataProvider('identifiantsForges')]
    public function testIdentifiantsForgesJamaisDansUneCleRedis(string $jti, string $famille): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->revocation->estRevoque($jti, $famille);
    }

    private function nettoyer(): void
    {
        $this->redis->del('jwt:deny:'.self::JTI, 'jwt:deny:'.self::AUTRE_JTI, 'jwt:deny:fam:'.self::FAMILLE);
    }
}

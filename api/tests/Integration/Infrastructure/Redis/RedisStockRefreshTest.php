<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisStockRefresh;
use App\Service\Auth\Rotation;
use App\Service\Auth\StockRefresh;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Jetons de rafraîchissement contre un vrai Redis : TTL de 7 jours, rotation atomique, réutilisation,
 * révocation de famille, entrées invalides (clés Redis construites uniquement depuis des valeurs validées).
 */
#[Group('redis')]
final class RedisStockRefreshTest extends KernelTestCase
{
    private const UTILISATEUR = '00000000-0000-4000-8000-0000000000a1';
    private const FAMILLE = 'f0f0f0f0-0000-4000-8000-00000000aaa1';
    private const AUTRE_FAMILLE = 'f0f0f0f0-0000-4000-8000-00000000aaa2';

    private \Redis $redis;
    private RedisStockRefresh $stock;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->nettoyer();
        $this->stock = new RedisStockRefresh($this->redis);
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testEnregistrerPoseUnJetonActifDeSeptJours(): void
    {
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);

        $cle = 'refresh:'.$this->e(1);
        self::assertSame(\Redis::REDIS_STRING, $this->redis->type($cle), 'String (CDC §9.8)');
        self::assertSame(self::UTILISATEUR.':'.self::FAMILLE, $this->redis->get($cle));
        self::assertEqualsWithDelta(StockRefresh::TTL, $this->redis->ttl($cle), 5);
        self::assertSame(604_800, StockRefresh::TTL, '7 jours');
        self::assertSame(\Redis::REDIS_SET, $this->redis->type('refresh:family:'.self::FAMILLE));
        self::assertEqualsWithDelta(StockRefresh::TTL, $this->redis->ttl('refresh:family:'.self::FAMILLE), 5, 'la famille expire aussi');
    }

    public function testLeJetonEnClairNEstJamaisUneCleRedis(): void
    {
        $jeton = str_repeat('c', 64);
        $this->stock->enregistrer(hash('sha256', $jeton), self::UTILISATEUR, self::FAMILLE);

        $cles = $this->redis->keys('refresh:*');
        self::assertIsArray($cles);
        foreach ($cles as $cle) {
            self::assertStringNotContainsString($jeton, (string) $cle);
        }
    }

    public function testRotationConsommeEtRemplace(): void
    {
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);

        $resultat = $this->stock->faireTourner($this->e(1), $this->e(2));

        self::assertSame(Rotation::Reussie, $resultat->issue);
        self::assertSame(self::UTILISATEUR, $resultat->utilisateurId);
        self::assertSame(self::FAMILLE, $resultat->familleId);
        self::assertSame(0, $this->redis->exists('refresh:'.$this->e(1)), 'l\'ancien jeton n\'est plus actif');
        self::assertSame(self::UTILISATEUR.':'.self::FAMILLE, $this->redis->get('refresh:'.$this->e(2)), 'le successeur hérite du compte et de la famille');
        self::assertEqualsWithDelta(StockRefresh::TTL, $this->redis->ttl('refresh:'.$this->e(2)), 5, 'TTL de 7 jours renouvelé');
        self::assertSame(self::FAMILLE, $this->redis->get('refresh:used:'.$this->e(1)), 'marqueur « consommé »');
        self::assertEqualsWithDelta(StockRefresh::TTL, $this->redis->ttl('refresh:used:'.$this->e(1)), 5);
    }

    public function testUnJetonConsommeEstDetecteEtRevoqueToutLaFamille(): void
    {
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);
        $this->stock->faireTourner($this->e(1), $this->e(2));
        $this->stock->faireTourner($this->e(2), $this->e(3));

        $reutilisation = $this->stock->faireTourner($this->e(1), $this->e(9));   // rejeu du tout premier jeton

        self::assertSame(Rotation::Reutilisation, $reutilisation->issue);
        self::assertSame(self::FAMILLE, $reutilisation->familleId);
        self::assertSame(0, $this->redis->exists('refresh:'.$this->e(3)), 'le jeton actif de la famille est révoqué');
        self::assertSame(0, $this->redis->exists('refresh:'.$this->e(9)), 'le successeur proposé n\'est pas créé');
        self::assertSame(Rotation::Reutilisation, $this->stock->faireTourner($this->e(2), $this->e(8))->issue, 'jeton consommé : toujours une réutilisation');
        self::assertSame(Rotation::Inconnu, $this->stock->faireTourner($this->e(3), $this->e(7))->issue, 'révoqué : inconnu');
    }

    public function testLaReutilisationNAffecteQueSaFamille(): void
    {
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);
        $this->stock->enregistrer($this->e(20), self::UTILISATEUR, self::AUTRE_FAMILLE);   // autre appareil
        $this->stock->faireTourner($this->e(1), $this->e(2));

        $this->stock->faireTourner($this->e(1), $this->e(3));

        self::assertSame(1, $this->redis->exists('refresh:'.$this->e(20)), 'l\'autre appareil reste connecté');
    }

    public function testJetonInconnu(): void
    {
        self::assertSame(Rotation::Inconnu, $this->stock->faireTourner($this->e(5), $this->e(6))->issue);
        self::assertSame(0, $this->redis->exists('refresh:'.$this->e(6)), 'aucun jeton créé pour une rotation refusée');
    }

    public function testRotationsSimultaneesUneSeuleReussit(): void
    {
        // Même jeton présenté N fois d'affilée (course de deux onglets, ou rejeu d'un voleur) :
        // le script Lua s'exécute d'un bloc, donc une seule rotation aboutit.
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);

        $issues = [];
        foreach (range(10, 14) as $n) {
            $issues[] = $this->stock->faireTourner($this->e(1), $this->e($n))->issue;
        }

        self::assertSame(1, \count(array_filter($issues, static fn (Rotation $i): bool => Rotation::Reussie === $i)));
    }

    public function testRevoquerFamilleSupprimeTousLesJetonsActifs(): void
    {
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);
        $this->stock->faireTourner($this->e(1), $this->e(2));
        $this->stock->enregistrer($this->e(20), self::UTILISATEUR, self::AUTRE_FAMILLE);

        $this->stock->revoquerFamille(self::FAMILLE);

        self::assertSame(0, $this->redis->exists('refresh:'.$this->e(2)));
        self::assertSame(0, $this->redis->exists('refresh:family:'.self::FAMILLE));
        self::assertSame(1, $this->redis->exists('refresh:'.$this->e(20)), 'les autres sessions ne sont pas touchées');
        self::assertSame(Rotation::Inconnu, $this->stock->faireTourner($this->e(2), $this->e(3))->issue);
    }

    public function testExpirationReelleDuJeton(): void
    {
        $court = new RedisStockRefresh($this->redis, ttlForce: 1);
        $court->enregistrer($this->e(1), self::UTILISATEUR, self::FAMILLE);

        usleep(1_300_000);

        self::assertSame(Rotation::Inconnu, $court->faireTourner($this->e(1), $this->e(2))->issue, 'jeton expiré après son TTL');
    }

    public function testRefuseLesValeursQuiNeSontPasDesEmpreintesOuDesFamilles(): void
    {
        foreach (["abc", str_repeat('A', 64), "x*\r\nDEL k", ''] as $mauvaise) {
            try {
                $this->stock->enregistrer($mauvaise, self::UTILISATEUR, self::FAMILLE);
                self::fail('Empreinte invalide acceptée : '.$mauvaise);
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->stock->enregistrer($this->e(1), self::UTILISATEUR, 'pas-une-famille');
    }

    /** Empreinte de test déterministe : SHA-256 de « jeton-n ». */
    private function e(int $n): string
    {
        return hash('sha256', 'jeton-test-'.$n);
    }

    private function nettoyer(): void
    {
        foreach ([1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 20] as $n) {
            $this->redis->del('refresh:'.$this->e($n), 'refresh:used:'.$this->e($n));
        }
        $this->redis->del('refresh:family:'.self::FAMILLE, 'refresh:family:'.self::AUTRE_FAMILLE);
        $this->redis->del('refresh:'.hash('sha256', str_repeat('c', 64)));
    }
}

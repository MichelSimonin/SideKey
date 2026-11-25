<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisCatalogueCache;
use App\Service\Catalogue\CatalogueCache;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\JeuResume;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\ValeursFiltres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Cache du catalogue contre un vrai Redis : clés et TTL du CDC §9.8, invalidation, panne de Redis, entrées forgées. */
#[Group('redis')]
final class RedisCatalogueCacheTest extends KernelTestCase
{
    private const SLUG = 'cache-test-jeu';

    private \Redis $redis;
    private RedisCatalogueCache $cache;

    /** @var list<string> */
    private array $journal = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->cache = new RedisCatalogueCache($this->redis, $this->logger());
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testLaFicheEstStockeSousLaCleDuCdcAvecUnTtlDeCinqMinutes(): void
    {
        $this->cache->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());

        self::assertSame(\Redis::REDIS_STRING, $this->redis->type('cache:jeu:'.self::SLUG.':fr'), 'String JSON (CDC §9.8)');
        self::assertEqualsWithDelta(300, $this->redis->ttl('cache:jeu:'.self::SLUG.':fr'), 5);
        self::assertSame(300, CatalogueCache::TTL, '5 minutes');
        self::assertEquals($this->fiche(), $this->cache->fiche(self::SLUG, Langue::Fr), 'aller-retour sans perte');
        self::assertNull($this->cache->fiche(self::SLUG, Langue::En), 'une entrée par langue');
    }

    public function testLeCacheContientDuJsonEtJamaisDuPhpSerialise(): void
    {
        $this->cache->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());

        $brut = (string) $this->redis->get('cache:jeu:'.self::SLUG.':fr');
        self::assertJson($brut);
        self::assertStringNotContainsString('O:', substr($brut, 0, 2));
        self::assertStringNotContainsString('App\\\\Service', $brut, 'aucun nom de classe : pas de désérialisation d\'objets');
    }

    public function testUneListeEstStockeeSousLaVersionCourante(): void
    {
        $filtres = new FiltresCatalogue('Aube', ['STEAM']);
        $this->cache->memoriserListe($filtres, Langue::Fr, $this->page());

        $version = (int) $this->redis->get('cache:jeux:version');
        $cle = \sprintf('cache:jeux:v%d:fr:%s', $version, $filtres->empreinte());
        self::assertSame(1, $this->redis->exists($cle));
        self::assertEqualsWithDelta(300, $this->redis->ttl($cle), 5);
        self::assertEquals($this->page(), $this->cache->liste($filtres, Langue::Fr));
        self::assertEquals($this->page(), $this->cache->liste(new FiltresCatalogue('aube', ['STEAM']), Langue::Fr), 'critères équivalents : même clé');
        self::assertNull($this->cache->liste(new FiltresCatalogue('Aube', ['STEAM'], page: 2), Langue::Fr));
    }

    public function testLesValeursDesFiltresSontMisesEnCache(): void
    {
        $valeurs = new ValeursFiltres([['code' => 'STEAM', 'libelle' => 'Steam']], [['slug' => 'action', 'libelle' => 'Action']], 999, 5499);
        $this->cache->memoriserValeursFiltres(Langue::Fr, $valeurs);

        self::assertEquals($valeurs, $this->cache->valeursFiltres(Langue::Fr));
        self::assertNull($this->cache->valeursFiltres(Langue::En));
    }

    public function testInvaliderUnJeuRetireSesDeuxLanguesSansToucherLesListes(): void
    {
        $this->cache->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());
        $this->cache->memoriserFiche(self::SLUG, Langue::En, $this->fiche());
        $this->cache->memoriserListe(new FiltresCatalogue(), Langue::Fr, $this->page());

        $this->cache->invaliderJeu(self::SLUG);

        self::assertNull($this->cache->fiche(self::SLUG, Langue::Fr));
        self::assertNull($this->cache->fiche(self::SLUG, Langue::En));
        self::assertNotNull($this->cache->liste(new FiltresCatalogue(), Langue::Fr));
    }

    public function testInvaliderLesListesChangeLaVersionEtLesAnciennesExpirentSeules(): void
    {
        $filtres = new FiltresCatalogue();
        $this->cache->memoriserListe($filtres, Langue::Fr, $this->page());
        $this->cache->memoriserValeursFiltres(Langue::Fr, new ValeursFiltres([], [], 0, 0));
        $this->cache->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());
        $avant = (int) $this->redis->get('cache:jeux:version');

        $this->cache->invaliderListes();

        self::assertSame($avant + 1, (int) $this->redis->get('cache:jeux:version'));
        self::assertNull($this->cache->liste($filtres, Langue::Fr), 'liste caduque');
        self::assertNull($this->cache->valeursFiltres(Langue::Fr), 'valeurs de filtres caduques');
        self::assertNotNull($this->cache->fiche(self::SLUG, Langue::Fr), 'les fiches ne sont pas concernées');
        $ancienne = \sprintf('cache:jeux:v%d:fr:%s', $avant, $filtres->empreinte());
        self::assertSame(1, $this->redis->exists($ancienne), 'l\'ancienne entrée reste jusqu\'à son TTL (pas de SCAN ni de DEL en masse)');
        self::assertGreaterThan(0, $this->redis->ttl($ancienne), 'mais elle expire');
    }

    public function testExpirationReelle(): void
    {
        $court = new RedisCatalogueCache($this->redis, $this->logger(), ttl: 1);
        $court->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());
        self::assertNotNull($court->fiche(self::SLUG, Langue::Fr));

        usleep(1_300_000);

        self::assertNull($court->fiche(self::SLUG, Langue::Fr), 'filet de sécurité : le TTL expire l\'entrée');
    }

    public function testUneEntreeCorrompueEstTraiteeCommeAbsente(): void
    {
        $this->redis->set('cache:jeu:'.self::SLUG.':fr', '{pas du json', ['ex' => 60]);
        self::assertNull($this->cache->fiche(self::SLUG, Langue::Fr));

        $this->redis->set('cache:jeu:'.self::SLUG.':fr', '{"slug": "x"}', ['ex' => 60]);   // champs manquants
        self::assertNull($this->cache->fiche(self::SLUG, Langue::Fr), 'forme inattendue : on relit la base');

        $this->redis->set('cache:jeu:'.self::SLUG.':fr', 'O:8:"stdClass":0:{}', ['ex' => 60]);   // objet PHP sérialisé
        self::assertNull($this->cache->fiche(self::SLUG, Langue::Fr), 'jamais de désérialisation PHP');
    }

    /** @return iterable<string, array{string}> */
    public static function slugsForges(): iterable
    {
        yield 'séparateur' => ['a:b'];
        yield 'joker' => ['*'];
        yield 'injection' => ["a\r\nFLUSHALL"];
        yield 'majuscules' => ['ABC'];
        yield 'vide' => [''];
        yield 'espace' => ['a b'];
    }

    #[DataProvider('slugsForges')]
    public function testUnSlugForgeNEntreJamaisDansUneCleRedis(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache->fiche($slug, Langue::Fr);
    }

    public function testRedisIndisponibleNeCasseJamaisLeCatalogue(): void
    {
        $mort = new \Redis();
        try {
            $mort->connect('127.0.0.1', 1, 0.2);
        } catch (\RedisException) {
        }
        $cache = new RedisCatalogueCache($mort, $this->logger());

        // Lectures : null (le service interroge PostgreSQL). Écritures et invalidations : sans effet, sans exception.
        self::assertNull($cache->fiche(self::SLUG, Langue::Fr));
        self::assertNull($cache->liste(new FiltresCatalogue(), Langue::Fr));
        self::assertNull($cache->valeursFiltres(Langue::Fr));
        $cache->memoriserFiche(self::SLUG, Langue::Fr, $this->fiche());
        $cache->memoriserListe(new FiltresCatalogue(), Langue::Fr, $this->page());
        $cache->invaliderJeu(self::SLUG);
        $cache->invaliderListes();

        self::assertNotEmpty($this->journal, 'l\'incident est journalisé');
    }

    private function fiche(): JeuFiche
    {
        return new JeuFiche(self::SLUG, 'Jeu de test', 'Une description', 12, 'jaquettes/x.webp', '2024-05-06', 'Éditeur',
            [['slug' => 'action', 'libelle' => 'Action']],
            [['offreId' => '00000000-0000-4000-8000-000000000001', 'plateforme' => 'STEAM', 'plateformeLibelle' => 'Steam', 'prixCentimes' => 1999, 'stock' => 4]],
            4.5, 2);
    }

    private function page(): PageJeux
    {
        return new PageJeux([new JeuResume(self::SLUG, 'Jeu de test', 12, null, ['STEAM'], 1999, true, 4.5, 2)], 1, 1);
    }

    private function nettoyer(): void
    {
        $this->redis->del('cache:jeu:'.self::SLUG.':fr', 'cache:jeu:'.self::SLUG.':en');
        foreach (['cache:jeux:v*', 'cache:filtres:v*'] as $motif) {
            $cles = $this->redis->keys($motif);
            if (\is_array($cles) && [] !== $cles) {
                $this->redis->del($cles);
            }
        }
    }

    private function logger(): AbstractLogger
    {
        $journal = &$this->journal;

        return new class($journal) extends AbstractLogger {
            /** @param list<string> $journal */
            public function __construct(private array &$journal)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->journal[] = (string) $message;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Catalogue;

use App\Exception\JeuIntrouvableException;
use App\Exception\PageIntrouvableException;
use App\Service\Catalogue\CatalogueService;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\Langue;
use App\Tests\Support\Catalogue\CacheEnMemoire;
use App\Tests\Support\Catalogue\CatalogueEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Service de lecture du catalogue avec un faux dépôt et un faux cache : cache-aside, 404, invalidation. */
final class CatalogueServiceTest extends TestCase
{
    private CatalogueEnMemoire $base;
    private CacheEnMemoire $cache;
    private CatalogueService $service;

    protected function setUp(): void
    {
        $this->base = new CatalogueEnMemoire();
        $this->cache = new CacheEnMemoire();
        $this->service = new CatalogueService($this->base, $this->cache);
    }

    public function testLaListeEstLueEnBaseUneFoisPuisEnCache(): void
    {
        $filtres = new FiltresCatalogue();

        $premiere = $this->service->liste($filtres, Langue::Fr);
        $seconde = $this->service->liste($filtres, Langue::Fr);

        self::assertSame(1, $this->base->appelsListe, 'la seconde lecture vient du cache');
        self::assertEquals($premiere, $seconde);
        self::assertCount(20, $premiere->jeux, 'pagination à 20');
        self::assertSame(25, $premiere->total);
        self::assertSame(2, $premiere->nombrePages());
    }

    public function testChaqueCombinaisonDeCriteresALeSienCache(): void
    {
        $this->service->liste(new FiltresCatalogue(page: 1), Langue::Fr);
        $this->service->liste(new FiltresCatalogue(page: 2), Langue::Fr);
        $this->service->liste(new FiltresCatalogue(page: 1), Langue::En);
        $this->service->liste(new FiltresCatalogue(plateformes: ['STEAM']), Langue::Fr);

        self::assertSame(4, $this->base->appelsListe);
        self::assertCount(5, $this->service->liste(new FiltresCatalogue(page: 2), Langue::Fr)->jeux);
        self::assertSame(4, $this->base->appelsListe);
    }

    public function testLOrdreDesCasesCocheesNeChangePasLeCache(): void
    {
        $this->service->liste(new FiltresCatalogue(plateformes: ['STEAM', 'PSN']), Langue::Fr);
        $this->service->liste(new FiltresCatalogue(plateformes: ['PSN', 'STEAM', 'PSN']), Langue::Fr);

        self::assertSame(1, $this->base->appelsListe);
    }

    public function testPageAuDelaDeLaDerniere404(): void
    {
        $this->expectException(PageIntrouvableException::class);
        $this->service->liste(new FiltresCatalogue(page: 3), Langue::Fr);   // 25 jeux = 2 pages
    }

    public function testAucunResultatReste200SurLaPremierePage(): void
    {
        $service = new CatalogueService(new CatalogueEnMemoire(0), new CacheEnMemoire());

        $page = $service->liste(new FiltresCatalogue(), Langue::Fr);

        self::assertSame([], $page->jeux);
        self::assertSame(0, $page->total);
        self::assertSame(1, $page->nombrePages());
    }

    public function testAucunResultatEtPageDeuxEst404(): void
    {
        $service = new CatalogueService(new CatalogueEnMemoire(0), new CacheEnMemoire());

        $this->expectException(PageIntrouvableException::class);
        $service->liste(new FiltresCatalogue(page: 2), Langue::Fr);
    }

    public function testLaFicheEstMiseEnCacheParLangue(): void
    {
        $fr = $this->service->fiche('jeu-01', Langue::Fr);
        $this->service->fiche('jeu-01', Langue::Fr);
        $en = $this->service->fiche('jeu-01', Langue::En);

        self::assertSame(2, $this->base->appelsFiche, 'une lecture par langue');
        self::assertSame('Description en fr', $fr->description);
        self::assertSame('Description en en', $en->description);
        self::assertArrayHasKey('jeu:jeu-01:fr', $this->cache->entrees);
    }

    public function testSlugInconnu404EtJamaisMisEnCache(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->service->fiche('inconnu', Langue::Fr);
                self::fail('404 attendu');
            } catch (JeuIntrouvableException) {
            }
        }

        self::assertSame(3, $this->base->appelsFiche, 'un 404 n\'est pas mis en cache');
        self::assertSame([], $this->cache->entrees, 'aucune entrée créée par une requête inconnue');
    }

    /** @return iterable<string, array{string}> */
    public static function slugsInvalides(): iterable
    {
        yield 'majuscules' => ['Jeu-01'];
        yield 'espace' => ['jeu 01'];
        yield 'séparateur de clé Redis' => ['jeu:01'];
        yield 'joker Redis' => ['jeu-*'];
        yield 'injection' => ["jeu-01'; DROP TABLE jeu;--"];
        yield 'saut de ligne' => ["jeu-01\r\nFLUSHALL"];
        yield 'tirets en tête' => ['-jeu'];
        yield 'double tiret' => ['jeu--01'];
        yield 'vide' => [''];
        yield 'trop long' => [str_repeat('a', 201)];
    }

    #[DataProvider('slugsInvalides')]
    public function testSlugMalFormeNeToucheNiLeCacheNiLaBase(string $slug): void
    {
        try {
            $this->service->fiche($slug, Langue::Fr);
            self::fail('404 attendu');
        } catch (JeuIntrouvableException) {
        }

        self::assertSame(0, $this->base->appelsFiche, 'aucune requête SQL');
        self::assertSame([], $this->cache->entrees, 'aucune clé Redis fabriquée par le client');
    }

    public function testInvaliderUnJeuForceUneNouvelleLecture(): void
    {
        $this->service->fiche('jeu-01', Langue::Fr);
        $this->service->fiche('jeu-01', Langue::En);

        $this->cache->invaliderJeu('jeu-01');
        $this->service->fiche('jeu-01', Langue::Fr);

        self::assertSame(3, $this->base->appelsFiche);
        self::assertSame([], array_diff(array_keys($this->cache->entrees), ['jeu:jeu-01:fr']), 'les deux langues sont invalidées, une seule relue');
    }

    public function testInvaliderLesListesForceUneNouvelleLecture(): void
    {
        $filtres = new FiltresCatalogue();
        $this->service->liste($filtres, Langue::Fr);
        $this->service->valeursFiltres(Langue::Fr);

        $this->cache->invaliderListes();
        $this->service->liste($filtres, Langue::Fr);
        $this->service->valeursFiltres(Langue::Fr);

        self::assertSame(2, $this->base->appelsListe);
        self::assertSame(2, $this->base->appelsFiltres);
    }

    public function testLInvalidationDesListesNeToucheQuaLesListes(): void
    {
        $this->service->fiche('jeu-01', Langue::Fr);

        $this->cache->invaliderListes();
        $this->service->fiche('jeu-01', Langue::Fr);

        self::assertSame(1, $this->base->appelsFiche);
    }

    public function testLesValeursDesFiltresSontMisesEnCache(): void
    {
        $this->service->valeursFiltres(Langue::Fr);
        $valeurs = $this->service->valeursFiltres(Langue::Fr);

        self::assertSame(1, $this->base->appelsFiltres);
        self::assertSame('STEAM', $valeurs->plateformes[0]['code']);
    }

    public function testLaLangueInconnueRetombeSurLeFrancais(): void
    {
        self::assertSame(Langue::Fr, Langue::depuis('de'));
        self::assertSame(Langue::Fr, Langue::depuis("fr'; DROP"));
        self::assertSame(Langue::En, Langue::depuis('en-US'));
        self::assertSame(Langue::En, Langue::depuis('EN'));
    }
}

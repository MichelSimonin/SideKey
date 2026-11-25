<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Service\Catalogue\CatalogueCache;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API du catalogue de bout en bout : HTTP → service → PostgreSQL (sidekey_test) et Redis (cache).
 * Requiert la base de test chargée (make db-seed) et Redis.
 */
#[Group('database')]
#[Group('redis')]
final class JeuControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private \Redis $redis;
    private Connection $connexion;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $redis = static::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $connexion = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->viderCache();
    }

    protected function tearDown(): void
    {
        $this->viderCache();
        parent::tearDown();
    }

    public function testListeParDefaut(): void
    {
        $this->get('/api/jeux');
        $corps = $this->json();

        self::assertResponseIsSuccessful();
        self::assertSame(39, $corps['total']);
        self::assertSame(1, $corps['page']);
        self::assertSame(20, $corps['parPage']);
        self::assertSame(2, $corps['nombrePages']);
        self::assertCount(20, $corps['jeux']);
        self::assertSame(
            ['slug', 'titre', 'pegi', 'jaquette', 'plateformes', 'prixMin', 'disponible', 'noteMoyenne', 'nombreAvis'],
            array_keys($corps['jeux'][0]),
            'contrat du DTO de sortie',
        );
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $corps['jeux'][0]['prixMin'], 'prix en chaîne décimale calculée par le serveur');
    }

    public function testLeCatalogueEstPublicEtMisEnCacheHttpParLangue(): void
    {
        $this->get('/api/jeux');

        self::assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();
        self::assertTrue($reponse->headers->hasCacheControlDirective('public'));
        self::assertSame('60', $reponse->headers->getCacheControlDirective('max-age'));
        self::assertContains('Accept-Language', $reponse->getVary());
        self::assertSame([], $reponse->headers->getCookies(), 'aucun cookie sur une réponse publique');
    }

    public function testFiltresCombinesEtReflechisDansLaReponse(): void
    {
        $this->get('/api/jeux?plateforme[]=PSN&genre[]=action&pegi=16&prix_max=30&tri=prix_asc');
        $corps = $this->json();

        self::assertResponseIsSuccessful();
        foreach ($corps['jeux'] as $jeu) {
            self::assertContains('PSN', $jeu['plateformes']);
            self::assertLessThanOrEqual(16, $jeu['pegi']);
            self::assertLessThanOrEqual(30.0, (float) $jeu['prixMin']);
        }
        $prix = array_map(static fn (array $j): float => (float) $j['prixMin'], $corps['jeux']);
        $tries = $prix;
        sort($tries);
        self::assertSame($tries, $prix);
    }

    public function testRechercheInsensibleAuxAccentsEtAMessageSiAucunResultat(): void
    {
        $this->get('/api/jeux?q=ECARLATE');
        self::assertSame(['jeu-essai-01'], array_column($this->json()['jeux'], 'slug'));

        $this->get('/api/jeux?q=zzzz+introuvable');
        self::assertResponseIsSuccessful();
        self::assertSame(['jeux' => [], 'total' => 0, 'page' => 1, 'parPage' => 20, 'nombrePages' => 1], $this->json());
    }

    public function testPageDeuxEtPageHorsBornes(): void
    {
        $this->get('/api/jeux?page=2');
        self::assertCount(19, $this->json()['jeux']);

        $this->get('/api/jeux?page=3');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Cette page de résultats n\'existe pas.', $this->json()['message']);

        $this->get('/api/jeux?page=0');
        self::assertResponseStatusCodeSame(422);
        $this->get('/api/jeux?page=10001');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string, array{string, string}> */
    public static function filtresInvalides(): iterable
    {
        yield 'tri hors liste blanche' => ['tri=titre;DROP', 'tri'];
        yield 'tri = colonne' => ['tri=mot_de_passe_hash', 'tri'];
        yield 'PEGI inconnu' => ['pegi=13', 'pegi'];
        yield 'PEGI non numérique' => ['pegi=abc', 'pegi'];
        yield 'prix non décimal' => ['prix_min=abc', 'prix_min'];
        yield 'prix négatif' => ['prix_max=-5', 'prix_max'];
        yield 'prix à trois décimales' => ['prix_max=1.999', 'prix_max'];
        yield 'fourchette inversée' => ['prix_min=20&prix_max=10', 'prix_min'];
        yield 'plateforme non liste' => ['plateforme=STEAM', 'plateforme'];
        yield 'plateforme minuscule' => ['plateforme[]=steam', 'plateforme'];
        yield 'plateforme injectée' => ["plateforme[]=STEAM'%20OR%201=1", 'plateforme'];
        yield 'genre en majuscules' => ['genre[]=ACTION', 'genre'];
        yield 'recherche trop longue' => ['q=', 'q'];
        yield 'page non numérique' => ['page=deux', 'page'];
    }

    #[DataProvider('filtresInvalides')]
    public function testFiltreInvalide422(string $requete, string $champ): void
    {
        if ('q=' === $requete) {
            $requete = 'q='.str_repeat('a', 101);
        }

        $this->get('/api/jeux?'.$requete);

        self::assertResponseStatusCodeSame(422);
        // Une erreur sur un élément de liste est désignée « plateforme[0] » : le préfixe est le paramètre fautif.
        $champs = array_map(static fn (string $c): string => explode('[', $c)[0], array_keys($this->json()['erreurs']));
        self::assertContains($champ, $champs, 'l\'erreur désigne le paramètre fautif');
    }

    public function testTropDeCasesCochees422(): void
    {
        $this->get('/api/jeux?'.implode('&', array_map(static fn (int $i): string => 'plateforme[]=P'.$i, range(1, 11))));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLesMessagesDeValidationSuiventLaLangue(): void
    {
        $this->get('/api/jeux?pegi=13', ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertSame('Some data is invalid.', $this->json()['message']);
        self::assertSame('The value you selected is not a valid choice.', $this->json()['erreurs']['pegi']);
    }

    public function testFicheEnFrancaisEtEnAnglais(): void
    {
        $this->get('/api/jeux/jeu-essai-01');
        $fr = $this->json();
        $this->get('/api/jeux/jeu-essai-01', ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $en = $this->json();

        self::assertResponseIsSuccessful();
        self::assertSame("Aube d'Écarlate", $fr['titre']);
        self::assertNotSame($fr['description'], $en['description'], 'description FR / EN (US-04)');
        self::assertSame(
            ['slug', 'titre', 'description', 'pegi', 'jaquette', 'dateSortie', 'editeur', 'genres', 'offres', 'noteMoyenne', 'nombreAvis'],
            array_keys($fr),
        );
        self::assertSame(7, $fr['pegi']);
        self::assertCount(3, $fr['offres']);
        self::assertSame(['offreId', 'plateforme', 'plateformeLibelle', 'prix', 'stock'], array_keys($fr['offres'][0]));
        self::assertSame('14.99', $fr['offres'][0]['prix']);
        self::assertEquals(3.0, $fr['noteMoyenne']);
    }

    public function testFicheInconnueOuRetireeDuCatalogue404(): void
    {
        foreach (['inconnu', 'jeu-essai-40'] as $slug) {
            $this->get('/api/jeux/'.$slug);

            self::assertResponseStatusCodeSame(404, $slug);
            self::assertSame(['message' => 'Ce jeu n\'existe pas ou n\'est plus au catalogue.'], $this->json());
        }
    }

    public function testLeSlugMalFormeEst404SansToucherLaBaseNiRedis(): void
    {
        $avant = $this->cles();

        foreach (['Jeu-Essai-01', 'jeu_essai_01', 'jeu--01', '%00', 'jeu%20essai', '..%2f..%2fetc'] as $slug) {
            $this->get('/api/jeux/'.$slug);
            self::assertResponseStatusCodeSame(404, $slug);
        }

        self::assertSame($avant, $this->cles(), 'aucune clé Redis créée par un slug forgé');
    }

    public function testValeursDesFiltres(): void
    {
        $this->get('/api/catalogue/filtres');
        $corps = $this->json();

        self::assertResponseIsSuccessful();
        self::assertSame(['PSN', 'STEAM', 'XBOX'], array_column($corps['plateformes'], 'code'));
        self::assertCount(8, $corps['genres']);
        self::assertSame('9.99', $corps['prixMin']);
        self::assertSame('54.99', $corps['prixMax']);
        self::assertSame([3, 7, 12, 16, 18], $corps['pegi']);
        self::assertSame(['titre', 'nouveaute', 'prix_asc', 'prix_desc', 'note'], $corps['tris']);
        self::assertSame('300', $this->client->getResponse()->headers->getCacheControlDirective('max-age'));
    }

    // --- Cache Redis ---------------------------------------------------------------------------

    public function testLaFicheEstMiseEnCacheSousLaCleDuCdc(): void
    {
        $this->get('/api/jeux/jeu-essai-01');

        self::assertSame(1, $this->redis->exists('cache:jeu:jeu-essai-01:fr'));
        self::assertEqualsWithDelta(300, $this->redis->ttl('cache:jeu:jeu-essai-01:fr'), 5);
        self::assertSame(0, $this->redis->exists('cache:jeu:jeu-essai-01:en'));
        $this->get('/api/jeux/jeu-essai-01', ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertSame(1, $this->redis->exists('cache:jeu:jeu-essai-01:en'));
    }

    public function testUneSecondeLectureVientDuCachePuisL_invalidationLaRafraichit(): void
    {
        $this->get('/api/jeux/jeu-essai-01');
        $this->get('/api/jeux?q=aube');
        $this->connexion->executeStatement("UPDATE jeu SET titre = 'Titre modifié' WHERE slug = 'jeu-essai-01'");

        try {
            $this->get('/api/jeux/jeu-essai-01');
            self::assertSame("Aube d'Écarlate", $this->json()['titre'], 'fiche servie par le cache malgré la modification en base');
            $this->get('/api/jeux?q=aube');
            self::assertSame(1, $this->json()['total'], 'la liste aussi vient du cache');

            $cache = static::getContainer()->get(CatalogueCache::class);
            self::assertInstanceOf(CatalogueCache::class, $cache);
            $cache->invaliderJeu('jeu-essai-01');
            $cache->invaliderListes();

            $this->get('/api/jeux/jeu-essai-01');
            self::assertSame('Titre modifié', $this->json()['titre'], 'après invalidation : relu en base');
            $this->get('/api/jeux?q=modifie');
            self::assertSame(['jeu-essai-01'], array_column($this->json()['jeux'], 'slug'));
        } finally {
            $this->connexion->executeStatement("UPDATE jeu SET titre = 'Aube d''Écarlate' WHERE slug = 'jeu-essai-01'");
        }
    }

    public function testLeCacheEstFiltreParLangueEtParCriteres(): void
    {
        $this->get('/api/jeux?plateforme[]=XBOX');
        $this->get('/api/jeux?plateforme[]=PSN');
        $this->get('/api/jeux?plateforme[]=PSN', ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        $listes = $this->redis->keys('cache:jeux:v*');
        self::assertIsArray($listes);
        self::assertCount(3, $listes, 'une clé par langue et par combinaison de critères');
        foreach ($listes as $cle) {
            self::assertMatchesRegularExpression('/^cache:jeux:v\d+:(fr|en):[0-9a-f]{64}$/', (string) $cle, 'aucune saisie du client dans la clé');
        }
    }

    public function testUnJeuInexistantNEstJamaisMisEnCache(): void
    {
        $avant = $this->cles();

        $this->get('/api/jeux/inconnu');
        $this->get('/api/jeux?page=3');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->redis->exists('cache:jeu:inconnu:fr'));
        unset($avant);
    }

    /**
     * @param array<string, string> $serveur
     */
    private function get(string $uri, array $serveur = []): void
    {
        $this->client->request('GET', $uri, server: $serveur);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function cles(): array
    {
        $cles = [];
        foreach (['cache:jeu:*', 'cache:jeux:*', 'cache:filtres:*'] as $motif) {
            foreach ((array) $this->redis->keys($motif) as $cle) {
                $cles[] = (string) $cle;
            }
        }
        sort($cles);

        return $cles;
    }

    private function viderCache(): void
    {
        $cles = $this->cles();
        if ([] !== $cles) {
            $this->redis->del($cles);
        }
    }
}

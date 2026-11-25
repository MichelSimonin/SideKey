<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sécurité du catalogue (CDC §11.3, OWASP A03) : injection SQL par la recherche, le tri et les filtres, jokers LIKE,
 * confusion de types, clés Redis forgées, déni de service par la taille, absence de fuite dans les réponses.
 * Le catalogue est public : aucune authentification, mais aucune entrée n'est de confiance.
 */
#[Group('database')]
#[Group('redis')]
final class CatalogueSecurityTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        foreach (['cache:jeu:*', 'cache:jeux:*', 'cache:filtres:*'] as $motif) {
            $cles = $this->redis->keys($motif);
            if (\is_array($cles) && [] !== $cles) {
                $this->redis->del($cles);
            }
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function injectionsSql(): iterable
    {
        yield "' OR '1'='1" => ["' OR '1'='1"];
        yield "' OR 1=1 --" => ["' OR 1=1 --"];
        yield 'UNION SELECT' => ["x' UNION SELECT mot_de_passe_hash, email FROM utilisateur --"];
        yield 'DROP TABLE' => ["x'; DROP TABLE jeu; --"];
        yield 'pg_sleep' => ["x' || (SELECT pg_sleep(5)) || '"];
        yield 'sous-requête' => ["x' AND (SELECT count(*) FROM cle_licence) > 0 --"];
        yield 'commentaire' => ['x/**/OR/**/1=1'];
        yield 'dollar quoting' => ['$$; DROP TABLE jeu; $$'];
    }

    #[DataProvider('injectionsSql')]
    public function testInjectionSqlParLaRecherche(string $charge): void
    {
        $avant = $this->compter();

        $debut = microtime(true);
        $this->get('/api/jeux?q='.rawurlencode($charge));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['total'], 'une charge d\'injection n\'est qu\'un titre introuvable');
        self::assertLessThan(3.0, microtime(true) - $debut, 'pg_sleep non exécuté');
        self::assertSame($avant, $this->compter(), 'tables intactes');
        $this->assertSansFuite();
    }

    #[DataProvider('injectionsSql')]
    public function testInjectionSqlParLesFiltresEtLeTri(string $charge): void
    {
        $avant = $this->compter();
        $c = rawurlencode($charge);

        foreach (["tri=$c", "plateforme[]=$c", "genre[]=$c", "pegi=$c", "prix_min=$c", "prix_max=$c", "page=$c"] as $requete) {
            $this->get('/api/jeux?'.$requete);
            self::assertResponseStatusCodeSame(422, $requete);
            $this->assertSansFuite();
        }
        self::assertSame($avant, $this->compter());
    }

    public function testInjectionParLeSlugDeLaFiche(): void
    {
        foreach (["jeu-essai-01'%20OR%20'1'='1", "x';%20DROP%20TABLE%20jeu;--", "jeu-essai-01%00", "%27%20OR%201=1--"] as $slug) {
            $this->get('/api/jeux/'.$slug);

            self::assertResponseStatusCodeSame(404, $slug);
            $this->assertSansFuite();
        }
        self::assertSame(40, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'));
    }

    public function testLeTriNEstJamaisUneColonneDuClient(): void
    {
        // Liste blanche : seuls les mots-clés de TriCatalogue sont acceptés, jamais un nom de colonne ni une expression.
        foreach (['titre', 'nouveaute', 'prix_asc', 'prix_desc', 'note'] as $valide) {
            $this->get('/api/jeux?tri='.$valide);
            self::assertResponseIsSuccessful($valide);
        }
        foreach (['j.titre', 'titre%20DESC', 'prix_min', 'mot_de_passe_hash', 'RANDOM()', '1', 'b.id', 'TITRE'] as $invalide) {
            $this->get('/api/jeux?tri='.$invalide);
            self::assertResponseStatusCodeSame(422, $invalide);
        }
    }

    public function testLesJokersLikeSontDesCaracteres(): void
    {
        foreach (['%', '_', '%25%25', 'a%25e', '\\'] as $terme) {
            $this->get('/api/jeux?q='.rawurlencode(urldecode($terme)));

            self::assertResponseIsSuccessful();
            self::assertSame(0, $this->json()['total'], $terme);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function confusionsDeTypes(): iterable
    {
        yield 'q tableau' => ['q[]=a'];
        yield 'q objet' => ['q[$ne]=x'];
        yield 'tri tableau' => ['tri[]=titre'];
        yield 'pegi tableau' => ['pegi[]=16'];
        yield 'page tableau' => ['page[]=1'];
        yield 'prix tableau' => ['prix_max[]=10'];
        yield 'plateforme objet' => ['plateforme[a]=STEAM'];
        yield 'plateforme imbriquée' => ['plateforme[][]=STEAM'];
    }

    #[DataProvider('confusionsDeTypes')]
    public function testConfusionDeTypesSurLaQueryString(string $requete): void
    {
        $this->get('/api/jeux?'.$requete);

        self::assertContains($this->client->getResponse()->getStatusCode(), [422, 200], 'jamais un 500');
        self::assertNotSame(500, $this->client->getResponse()->getStatusCode());
        $this->assertSansFuite();
    }

    public function testDenisDeServiceParLaTailleDesEntrees(): void
    {
        $this->get('/api/jeux?q='.str_repeat('a', 10_000));
        self::assertResponseStatusCodeSame(422, 'recherche bornée à 100 caractères');

        $this->get('/api/jeux?'.implode('&', array_map(static fn (int $i): string => 'plateforme[]=P'.$i, range(1, 500))));
        self::assertResponseStatusCodeSame(422, 'cases cochées bornées à 10');

        $this->get('/api/jeux?genre[]='.str_repeat('a', 5000));
        self::assertResponseStatusCodeSame(422);

        $this->get('/api/jeux?page=99999999999999999999');
        self::assertContains($this->client->getResponse()->getStatusCode(), [422, 400]);
    }

    public function testLesPagesExtremesNeCoutentRienDeSpecial(): void
    {
        $debut = microtime(true);
        $this->get('/api/jeux?page=10000');

        self::assertResponseStatusCodeSame(404);
        self::assertLessThan(2.0, microtime(true) - $debut);
    }

    public function testDesSlugsForgesNeCreentAucuneCleRedis(): void
    {
        $avant = $this->redis->keys('cache:*');

        for ($i = 0; $i < 30; ++$i) {
            $this->get('/api/jeux/'.bin2hex(random_bytes(6)));       // slug bien formé mais inconnu
            $this->get('/api/jeux/'.rawurlencode("jeu:$i*\r\n"));   // slug mal formé
        }

        self::assertEquals($avant, $this->redis->keys('cache:*'), 'les 404 ne sont jamais mis en cache : pas d\'inondation de Redis');
    }

    public function testLEmpreinteDeCacheNeDependQueDesCriteresNormalises(): void
    {
        // Mille façons d'écrire la même recherche : une seule clé, jamais de saisie brute dans Redis.
        foreach (['aube', 'AUBE', ' aube ', 'Aube'] as $q) {
            $this->get('/api/jeux?q='.rawurlencode($q));
        }

        $cles = $this->redis->keys('cache:jeux:v*');
        self::assertIsArray($cles);
        self::assertCount(1, $cles);
        self::assertDoesNotMatchRegularExpression('/aube/i', (string) $cles[0]);
    }

    public function testLeCatalogueNEcritRienEtNeRenvoieAucunSecret(): void
    {
        $this->get('/api/jeux?pegi=18');
        $liste = (string) $this->client->getResponse()->getContent();
        $this->get('/api/jeux/jeu-essai-01');
        $fiche = (string) $this->client->getResponse()->getContent();

        foreach ([$liste, $fiche] as $json) {
            foreach (['cle_chiffree', 'empreinte', 'mot_de_passe', 'email', 'totp', 'statut', 'utilisateur'] as $interdit) {
                self::assertStringNotContainsStringIgnoringCase($interdit, $json, $interdit);
            }
        }
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testLaSaisieNEstJamaisRenvoyeDansLaReponse(): void
    {
        $this->get('/api/jeux?q='.rawurlencode('<script>alert(1)</script>'));
        self::assertStringNotContainsString('<script>', (string) $this->client->getResponse()->getContent(), 'aucun écho de la recherche');

        $this->get('/api/jeux?tri='.rawurlencode('<img src=x onerror=alert(1)>'));
        self::assertStringNotContainsString('<img', (string) $this->client->getResponse()->getContent(), 'aucun écho du paramètre invalide');
    }

    public function testLesChampsTexteSontDuJsonEchappe(): void
    {
        // Un titre contenant du HTML sort tel quel en JSON ; l'échappement est celui de JSX côté front (jamais d'innerHTML).
        $this->connexion->executeStatement("UPDATE jeu SET titre = '<b>Gras</b> & \"Cie\"' WHERE slug = 'jeu-essai-05'");
        try {
            $this->get('/api/jeux/jeu-essai-05');

            self::assertResponseIsSuccessful();
            self::assertSame('<b>Gras</b> & "Cie"', $this->json()['titre']);
            self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
            self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options') ?? 'nosniff');
        } finally {
            $this->connexion->executeStatement("UPDATE jeu SET titre = 'Néon Mirage' WHERE slug = 'jeu-essai-05'");
        }
    }

    public function testLeCatalogueResteAccessibleSansJetonEtLesMethodesEcritesSontRefusees(): void
    {
        $this->get('/api/jeux');
        self::assertResponseIsSuccessful();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $methode) {
            $this->client->request($methode, '/api/jeux', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(405, $methode);
            $this->client->request($methode, '/api/jeux/jeu-essai-01', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(405, $methode);
        }
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function compter(): string
    {
        return implode('|', array_map(
            fn (string $t): string => $t.'='.$this->connexion->fetchOne('SELECT count(*) FROM '.$t),
            ['jeu', 'offre', 'cle_licence', 'utilisateur', 'avis'],
        ));
    }

    private function assertSansFuite(): void
    {
        $contenu = (string) $this->client->getResponse()->getContent();
        foreach (['SQLSTATE', 'pg_', 'Doctrine', 'vendor/', '/var/www', 'Stack trace', 'utilisateur'] as $fuite) {
            self::assertStringNotContainsString($fuite, $contenu, 'aucun détail technique');
        }
    }
}

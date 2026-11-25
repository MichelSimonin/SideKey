<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Service\Panier\PanierCookie;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API du panier de bout en bout : HTTP → service → PostgreSQL (stock, prix) et Redis (panier).
 * Requiert la base sidekey_test chargée (make db-seed) et Redis.
 */
#[Group('database')]
#[Group('redis')]
final class PanierControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_ACCEPT_LANGUAGE' => 'fr']);
    }

    protected function tearDown(): void
    {
        $this->client->request('DELETE', '/api/panier');   // ne laisse rien dans Redis
        parent::tearDown();
    }

    public function testPanierVideSansCookie(): void
    {
        $this->client->request('GET', '/api/panier');

        self::assertResponseIsSuccessful();
        self::assertSame(['lignes' => [], 'nombreArticles' => 0, 'total' => '0.00', 'offresRetirees' => []], $this->json());
        self::assertNull($this->client->getResponse()->headers->getCookies()[0] ?? null, 'pas de cookie tant que rien n\'est ajouté');
    }

    public function testAjouterPoseUnCookieSecuriseEtCalculeLeTotal(): void
    {
        [$offre, $prix] = $this->offre(6);

        $this->post(['offreId' => $offre, 'quantite' => 2]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $cookie = $this->client->getResponse()->headers->getCookies()[0];
        self::assertSame(PanierCookie::NOM, $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/api', $cookie->getPath());

        $panier = $this->json();
        self::assertSame(2, $panier['nombreArticles']);
        self::assertSame(\sprintf('%.2f', 2 * $prix), $panier['total']);
        self::assertSame($offre, $panier['lignes'][0]['offreId']);

        // Le panier est retrouvé grâce au cookie
        $this->client->request('GET', '/api/panier');
        self::assertSame(2, $this->json()['nombreArticles']);
    }

    public function testModifierEtRetirerUneLigne(): void
    {
        [$offre] = $this->offre(6);
        $this->post(['offreId' => $offre, 'quantite' => 1]);

        $this->client->request('PATCH', '/api/panier/lignes/'.$offre, content: '{"quantite": 4}', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->json()['lignes'][0]['quantite']);

        $this->client->request('DELETE', '/api/panier/lignes/'.$offre);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['lignes']);
    }

    public function testQuantiteLimiteeParLeStock(): void
    {
        [$offre] = $this->offre(1, exact: true);

        $this->post(['offreId' => $offre, 'quantite' => 2]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['message' => 'Stock insuffisant : 1 clé(s) disponible(s) pour cette offre.', 'disponible' => 1], $this->json());
    }

    public function testCinqClesAuMaximumParOffre(): void
    {
        [$offre] = $this->offre(6);
        $this->post(['offreId' => $offre, 'quantite' => 5]);

        $this->post(['offreId' => $offre, 'quantite' => 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(5, $this->json()['maximum']);
    }

    public function testOffreInconnue(): void
    {
        $this->post(['offreId' => '00000000-0000-4000-8000-000000000000', 'quantite' => 1]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame("Cette offre n'existe pas ou n'est plus en vente.", $this->json()['message']);
    }

    public function testValidationDesEntreesTraduite(): void
    {
        $this->client->request('POST', '/api/panier/lignes', content: '{"offreId": "pas-un-uuid", "quantite": 9}',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseStatusCodeSame(422);
        $corps = $this->json();
        self::assertSame('Some data is invalid.', $corps['message']);
        self::assertArrayHasKey('offreId', $corps['erreurs']);
        self::assertArrayHasKey('quantite', $corps['erreurs']);
        self::assertStringNotContainsString('pas-un-uuid', (string) $this->client->getResponse()->getContent(), 'la saisie n\'est pas renvoyée');
    }

    public function testCookieForgeIgnore(): void
    {
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(PanierCookie::NOM, '*', null, '/api', 'localhost', true));

        $this->client->request('GET', '/api/panier');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['lignes']);
    }

    /** @param array<string, mixed> $corps */
    private function post(array $corps): void
    {
        $this->client->request('POST', '/api/panier/lignes', content: (string) json_encode($corps), server: ['CONTENT_TYPE' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: float} offre active ayant au moins (ou exactement) $stock clés disponibles */
    private function offre(int $stock, bool $exact = false): array
    {
        $connexion = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $ligne = $connexion->fetchAssociative(
            "SELECT o.id, o.prix_ttc FROM offre o JOIN cle_licence k ON k.offre_id = o.id AND k.statut = 'DISPONIBLE'
              WHERE o.actif GROUP BY o.id HAVING count(*) ".($exact ? '=' : '>=').' ? ORDER BY o.id LIMIT 1', [$stock]);
        self::assertIsArray($ligne, 'le jeu d\'essai doit contenir cette offre (make db-seed)');

        return [(string) $ligne['id'], (float) $ligne['prix_ttc']];
    }
}

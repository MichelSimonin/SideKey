<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Auth\AuthWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sécurité de la seule route à cookie, POST /api/token/refresh (CDC §11.4) : CSRF (Origin, double soumission
 * signée), cookie forgé, mauvaises méthodes, jeton hors canal. Une requête refusée NE consomme PAS le jeton :
 * un attaquant ne peut pas déconnecter la victime en faisant échouer son refresh.
 */
#[Group('database')]
#[Group('redis')]
final class RefreshCsrfTest extends AuthWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.40';
    }

    public function testRequeteLegitimeAcceptee(): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        $this->rafraichir($session['refresh'], $session['csrf']);

        self::assertSame(200, $this->etat()['statut'], 'témoin');
    }

    public function testSansEnTeteOriginRefuse(): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        $this->rafraichir($session['refresh'], $session['csrf'], origine: null);

        self::assertSame(403, $this->etat()['statut']);
        $this->assertJetonNonConsomme($session);
    }

    /** @return iterable<string, array{string}> */
    public static function originesHostiles(): iterable
    {
        yield 'site tiers' => ['https://evil.example'];
        yield 'sous-domaine piégé' => ['https://localhost.evil.example'];
        yield 'origine opaque' => ['null'];
        yield 'schéma file' => ['file://'];
    }

    #[DataProvider('originesHostiles')]
    public function testOriginHostileRefusee(string $origine): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        $this->rafraichir($session['refresh'], $session['csrf'], origine: $origine);

        self::assertSame(403, $this->etat()['statut'], $origine);
        $this->assertJetonNonConsomme($session);
    }

    public function testSansEnTeteCsrfRefuse(): void
    {
        $session = $this->connecter($this->creerClient()['email']);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('sk_refresh', (string) $session['refresh'], null, '/api/token/refresh', 'localhost', true));
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('sk_csrf', (string) $session['csrf'], null, '/', 'localhost', true));

        $this->client->request('POST', '/api/token/refresh', server: ['HTTP_ORIGIN' => self::ORIGINE]);   // cookies seuls, pas d'en-tête

        self::assertSame(403, $this->etat()['statut'], 'des cookies seuls (ce que ferait un formulaire inter-site) ne suffisent pas');
        $this->assertJetonNonConsomme($session);
    }

    public function testEnTeteDifferentDuCookieRefuse(): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        $this->rafraichir($session['refresh'], $session['csrf'], enteteCsrf: 'une-autre-valeur');

        self::assertSame(403, $this->etat()['statut']);
        $this->assertJetonNonConsomme($session);
    }

    public function testDoubleSoumissionNaiveAvecUneValeurChoisieParLAttaquantRefusee(): void
    {
        // L'attaquant pose le même cookie et le même en-tête (cookie tossing depuis un sous-domaine) :
        // la valeur n'est pas le HMAC du refresh, donc refusée.
        $session = $this->connecter($this->creerClient()['email']);

        $this->rafraichir($session['refresh'], 'valeur-choisie-par-l-attaquant');

        self::assertSame(403, $this->etat()['statut']);
        $this->assertJetonNonConsomme($session);
    }

    public function testJetonCsrfDUneAutreSessionRefuse(): void
    {
        $victime = $this->connecter($this->creerClient()['email']);
        $attaquant = $this->connecter($this->creerClient('attaquant')['email']);

        // Cookie de refresh de la victime + jeton CSRF légitime… de l'attaquant.
        $this->rafraichir($victime['refresh'], $attaquant['csrf']);

        self::assertSame(403, $this->etat()['statut']);
        $this->assertJetonNonConsomme($victime);
    }

    public function testSansCookieDeRefresh401(): void
    {
        $this->rafraichir(null, null, enteteCsrf: $this->csrfPour(str_repeat('a', 64)));

        self::assertSame(401, $this->etat()['statut']);
    }

    /** @return iterable<string, array{string}> */
    public static function cookiesForges(): iterable
    {
        yield 'valeur inventée au bon format' => [str_repeat('a', 64)];
        yield 'trop court' => ['abc'];
        yield 'injection Redis' => ["x\r\nFLUSHALL"];
        yield 'joker' => ['*'];
        yield 'très long' => [str_repeat('a', 10_000)];
    }

    #[DataProvider('cookiesForges')]
    public function testCookieDeRefreshForge401(string $valeur): void
    {
        $this->rafraichir($valeur, $this->csrfPour($valeur));   // même un CSRF valide pour la valeur forgée

        self::assertSame(401, $this->etat()['statut']);
        self::assertSame(['message' => 'Session expirée ou invalide. Veuillez vous reconnecter.'], $this->etat()['corps']);
    }

    public function testLeRefreshNEstPasAccepteDansLeCorpsOuLEnTeteAuthorization(): void
    {
        $session = $this->connecter($this->creerClient()['email']);
        $this->client->getCookieJar()->clear();   // aucun cookie : seuls des canaux non prévus transportent le jeton

        $this->client->request('POST', '/api/token/refresh', content: (string) json_encode(['refresh' => $session['refresh'], 'jeton' => $session['refresh']]),
            server: ['HTTP_ORIGIN' => self::ORIGINE, 'HTTP_X_CSRF_TOKEN' => $session['csrf'], 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$session['refresh']]);

        self::assertSame(401, $this->etat()['statut']);
    }

    /** @return iterable<string, array{string}> */
    public static function methodesInterdites(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
        yield 'PATCH' => ['PATCH'];
    }

    #[DataProvider('methodesInterdites')]
    public function testSeulPostEstAccepte(string $methode): void
    {
        $session = $this->connecter($this->creerClient()['email']);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('sk_refresh', (string) $session['refresh'], null, '/api/token/refresh', 'localhost', true));

        $this->client->request($methode, '/api/token/refresh', server: ['HTTP_ORIGIN' => self::ORIGINE, 'HTTP_X_CSRF_TOKEN' => (string) $session['csrf']]);

        self::assertSame(405, $this->etat()['statut'], 'un GET ne doit jamais consommer un refresh (lien, image, prefetch)');
        $this->assertJetonNonConsomme($session);
    }

    public function testLeCookieDeRefreshNEstPasEnvoyeAuxAutresRoutes(): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        // Le navigateur n'envoie sk_refresh qu'à /api/token/refresh : le chemin du cookie l'impose.
        $this->client->request('GET', '/api/moi');
        $requete = $this->client->getRequest();

        self::assertNull($requete->cookies->get('sk_refresh'), 'Path=/api/token/refresh');
        self::assertSame(401, $this->etat()['statut']);
        unset($session);
    }

    public function testLeJetonDAccesNeSertPasDeRefreshNiInversement(): void
    {
        $session = $this->connecter($this->creerClient()['email']);

        $this->json('GET', '/api/moi', jeton: (string) $session['refresh']);   // le refresh opaque comme jeton d'accès
        self::assertSame(401, $this->etat()['statut']);

        $this->rafraichir($session['corps']['jetonAcces'], $this->csrfPour($session['corps']['jetonAcces']));   // le JWT comme refresh
        self::assertSame(401, $this->etat()['statut']);
    }

    /** @param array{statut: int, corps: array<string, mixed>, refresh: ?string, csrf: ?string} $session */
    private function assertJetonNonConsomme(array $session): void
    {
        self::assertSame(1, $this->redis->exists('refresh:'.hash('sha256', (string) $session['refresh'])), 'le jeton n\'est pas consommé par une requête refusée');
        $this->rafraichir($session['refresh'], $session['csrf']);
        self::assertSame(200, $this->etat()['statut'], 'et la victime peut toujours rafraîchir');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Exception\OrigineRefuseeException;
use App\Exception\SessionInvalideException;
use App\Security\Http\CookiesSession;
use App\Security\Http\ProtectionCsrf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protection de la seule route à cookie : Origin, double soumission signée, SameSite=Strict (CDC §11.4). */
final class ProtectionCsrfTest extends TestCase
{
    private const ORIGINES = '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$';
    private const REFRESH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ProtectionCsrf $csrf;

    protected function setUp(): void
    {
        $this->csrf = new ProtectionCsrf('secret-de-test', self::ORIGINES);
    }

    public function testRequeteLegitimeAcceptee(): void
    {
        $this->csrf->verifier($this->requete());
        $this->addToAssertionCount(1);   // aucune exception
    }

    public function testOriginAbsenteRefusee(): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(origine: null));
    }

    /** @return iterable<string, array{string}> */
    public static function originesHostiles(): iterable
    {
        yield 'autre site' => ['https://evil.example'];
        yield 'sous-domaine piégé' => ['https://localhost.evil.example'];
        yield 'préfixe piégé' => ['https://localhostevil.example'];
        yield 'origine null (iframe sandbox)' => ['null'];
        yield 'schéma exotique' => ['file://localhost'];
        yield 'vide' => [''];
        yield 'localhost avec chemin' => ['https://localhost/x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originesHostiles')]
    public function testOriginHostileRefusee(string $origine): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(origine: $origine));
    }

    public function testOriginsDeDeveloppementAcceptees(): void
    {
        foreach (['http://localhost:5173', 'https://localhost', 'http://127.0.0.1:8000'] as $origine) {
            $this->csrf->verifier($this->requete(origine: $origine));
        }
        $this->addToAssertionCount(1);
    }

    public function testEnTeteCsrfAbsentRefuse(): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(entete: null));
    }

    public function testCookieCsrfAbsentRefuse(): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(cookieCsrf: null));
    }

    public function testEnTeteDifferentDuCookieRefuse(): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(entete: 'autre-valeur'));
    }

    public function testDoubleSoumissionNaiveRefusee(): void
    {
        // Un attaquant qui sait poser un cookie (sous-domaine) choisit la même valeur partout :
        // la valeur n'est pas le HMAC du refresh, donc refusée.
        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(entete: 'valeur-choisie-par-l-attaquant', cookieCsrf: 'valeur-choisie-par-l-attaquant'));
    }

    public function testJetonCsrfLieAUnAutreRefreshRefuse(): void
    {
        $this->expectException(OrigineRefuseeException::class);
        $autre = $this->csrf->jetonPour(str_repeat('b', 64));
        $this->csrf->verifier($this->requete(entete: $autre, cookieCsrf: $autre));
    }

    public function testJetonCsrfSigneAvecUnAutreSecretRefuse(): void
    {
        $faux = (new ProtectionCsrf('un-autre-secret', self::ORIGINES))->jetonPour(self::REFRESH);

        $this->expectException(OrigineRefuseeException::class);
        $this->csrf->verifier($this->requete(entete: $faux, cookieCsrf: $faux));
    }

    public function testSansCookieDeRefreshOnRepondSessionInvalide(): void
    {
        // 401 (pas de session) plutôt que 403 (attaque) : le client sait qu'il doit se reconnecter.
        $this->expectException(SessionInvalideException::class);
        $this->csrf->verifier($this->requete(refresh: null));
    }

    public function testLeJetonCsrfEstDeterministeEtOpaque(): void
    {
        $jeton = $this->csrf->jetonPour(self::REFRESH);

        self::assertSame($jeton, $this->csrf->jetonPour(self::REFRESH));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $jeton, 'base64url sans remplissage');
        self::assertStringNotContainsString(self::REFRESH, $jeton);
    }

    public function testCookiesPosesAvecLesBonsAttributs(): void
    {
        $reponse = new Response();
        (new CookiesSession($this->csrf))->poser($reponse, self::REFRESH);

        $cookies = [];
        foreach ($reponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $refresh = $cookies[CookiesSession::REFRESH];
        self::assertSame(self::REFRESH, $refresh->getValue());
        self::assertTrue($refresh->isHttpOnly(), 'HttpOnly : illisible en JavaScript');
        self::assertTrue($refresh->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $refresh->getSameSite());
        self::assertSame('/api/token/refresh', $refresh->getPath(), 'jamais envoyé aux autres routes');
        self::assertNull($refresh->getDomain(), 'cookie « host-only »');
        self::assertEqualsWithDelta(time() + 604_800, $refresh->getExpiresTime(), 5, '7 jours');

        $csrf = $cookies[CookiesSession::CSRF];
        self::assertSame($this->csrf->jetonPour(self::REFRESH), $csrf->getValue());
        self::assertFalse($csrf->isHttpOnly(), 'lisible par la SPA pour la double soumission');
        self::assertTrue($csrf->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $csrf->getSameSite());
        self::assertSame('/', $csrf->getPath());
    }

    public function testEffacementAvecLesMemesAttributs(): void
    {
        $reponse = new Response();
        (new CookiesSession($this->csrf))->effacer($reponse);

        foreach ($reponse->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isCleared(), $cookie->getName());
            self::assertSame('', $cookie->getValue());
        }
        $chemins = array_map(static fn (Cookie $c): string => $c->getPath(), $reponse->headers->getCookies());
        self::assertSame(['/api/token/refresh', '/'], $chemins, 'même chemin que la pose, sinon le navigateur garde le cookie');
    }

    private function requete(?string $origine = 'http://localhost:5173', ?string $entete = '', ?string $cookieCsrf = '', ?string $refresh = self::REFRESH): Request
    {
        $jeton = $this->csrf->jetonPour(self::REFRESH);
        $entete = '' === $entete ? $jeton : $entete;
        $cookieCsrf = '' === $cookieCsrf ? $jeton : $cookieCsrf;

        $cookies = [];
        if (null !== $refresh) {
            $cookies[CookiesSession::REFRESH] = $refresh;
        }
        if (null !== $cookieCsrf) {
            $cookies[CookiesSession::CSRF] = $cookieCsrf;
        }
        $requete = Request::create('/api/token/refresh', 'POST', cookies: $cookies);
        if (null !== $origine) {
            $requete->headers->set('Origin', $origine);
        }
        if (null !== $entete) {
            $requete->headers->set(ProtectionCsrf::EN_TETE, $entete);
        }

        return $requete;
    }
}

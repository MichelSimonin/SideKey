<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Jwt\JwtSubscriber;
use App\Service\Auth\TypeJeton;
use App\Tests\Support\Auth\RevocationEnMemoire;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\ExpiredTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\MissingTokenException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/** Règles d'acceptation d'un JWT par le pare-feu : portée, révocation, Redis indisponible, 401 générique. */
final class JwtSubscriberTest extends TestCase
{
    private const JTI = '0123456789abcdef0123456789abcdef';
    private const FAMILLE = '11111111-2222-4333-8444-555555555555';

    private RevocationEnMemoire $revocation;
    private JwtSubscriber $abonne;
    private RequestStack $requetes;

    protected function setUp(): void
    {
        $this->revocation = new RevocationEnMemoire();
        $traducteur = new Translator('fr');
        $traducteur->addLoader('array', new ArrayLoader());
        $traducteur->addResource('array', ['error.unauthorized' => 'Authentification requise.'], 'fr', 'errors');
        $this->requetes = new RequestStack();
        $this->requetes->push(Request::create('/api/moi'));
        $this->abonne = new JwtSubscriber($this->revocation, $traducteur, new NullLogger(), $this->requetes);
    }

    public function testJetonDAccesValideAccepte(): void
    {
        $evenement = new JWTDecodedEvent($this->charge());
        $this->abonne->surJetonDecode($evenement);

        self::assertTrue($evenement->isValid());
    }

    /** @return iterable<string, array{string}> */
    public static function routesDeLaSecondeEtape(): iterable
    {
        yield 'vérification' => ['/api/connexion/a2f'];
        yield 'enrôlement' => ['/api/connexion/a2f/enrolement'];
        yield 'confirmation' => ['/api/connexion/a2f/enrolement/confirmation'];
    }

    #[DataProvider('routesDeLaSecondeEtape')]
    public function testLeJetonIntermediaireOuvreLesRoutesDeLaSecondeEtape(string $chemin): void
    {
        $this->requetes->push(Request::create($chemin, 'POST'));

        $intermediaire = new JWTDecodedEvent($this->charge(['typ' => TypeJeton::A2f->value]));
        $this->abonne->surJetonDecode($intermediaire);
        $acces = new JWTDecodedEvent($this->charge());
        $this->abonne->surJetonDecode($acces);

        self::assertTrue($intermediaire->isValid());
        self::assertFalse($acces->isValid(), "le jeton d'accès n'ouvre pas la seconde étape : il faut repasser par le mot de passe");
    }

    /** @return iterable<string, array{string}> */
    public static function autresRoutes(): iterable
    {
        yield 'profil' => ['/api/moi'];
        yield 'back-office' => ['/api/admin/offres/x/cles'];
        yield 'préfixe voisin' => ['/api/connexion/a2fx'];
        yield 'racine de la connexion' => ['/api/connexion'];
        yield 'casse différente' => ['/api/connexion/A2F'];
    }

    #[DataProvider('autresRoutes')]
    public function testLeJetonIntermediaireNOuvreAucuneAutreRoute(string $chemin): void
    {
        $this->requetes->push(Request::create($chemin));

        $evenement = new JWTDecodedEvent($this->charge(['typ' => TypeJeton::A2f->value]));
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid(), $chemin);
    }

    public function testLeJetonIntermediaireA2fNOuvrePasLAPI(): void
    {
        $evenement = new JWTDecodedEvent($this->charge(['typ' => TypeJeton::A2f->value]));
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid(), 'portée limitée à la saisie du code TOTP');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function chargesInvalides(): iterable
    {
        yield 'sans type' => [['typ' => null]];
        yield 'type inconnu' => [['typ' => 'refresh']];
        yield 'type en majuscules' => [['typ' => 'ACCESS']];
        yield 'sans jti' => [['jti' => null]];
        yield 'jti hors format' => [['jti' => 'abc']];
        yield 'jti injecté' => [['jti' => "0123456789abcdef0123456789abcde\n"]];
        yield 'jti non texte' => [['jti' => ['$ne' => null]]];
        yield 'sans famille' => [['fam' => null]];
        yield 'sujet absent' => [['sub' => null]];
        yield 'sujet tableau' => [['sub' => ['$ne' => '']]];
        yield 'sujet non UUID' => [['sub' => "1' OR '1'='1"]];
        yield 'famille hors format' => [['fam' => 'pas-un-uuid']];
        yield 'famille non texte' => [['fam' => 42]];
    }

    /** @param array<string, mixed> $modifs */
    #[DataProvider('chargesInvalides')]
    public function testChargeInvalideRefusee(array $modifs): void
    {
        $evenement = new JWTDecodedEvent($this->charge($modifs));
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid());
    }

    public function testJetonRevoqueRefuse(): void
    {
        $this->revocation->revoquerJeton(self::JTI, 600);

        $evenement = new JWTDecodedEvent($this->charge());
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid(), 'jwt:deny:{jti}');
    }

    public function testSessionRevoqueeRefuseTousSesJetons(): void
    {
        $this->revocation->revoquerFamille(self::FAMILLE, 900);

        $evenement = new JWTDecodedEvent($this->charge(['jti' => 'fedcba9876543210fedcba9876543210']));
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid(), 'un jeton d\'accès émis avant la révocation de la session reste refusé');
    }

    public function testRedisIndisponibleRefuseLeJeton(): void
    {
        // Fail Securely : on ne peut pas prouver que le jeton n'est pas révoqué, donc on le refuse.
        $this->revocation->indisponible = true;

        $evenement = new JWTDecodedEvent($this->charge());
        $this->abonne->surJetonDecode($evenement);

        self::assertFalse($evenement->isValid());
    }

    public function testToutEchecDAuthentificationDonneLeMemeQuatreCentUnGenerique(): void
    {
        $evenements = [
            new JWTNotFoundEvent(new MissingTokenException('JWT Token not found'), new Response()),
            new JWTInvalidEvent(new InvalidTokenException('Invalid JWT Token'), new Response()),
            new JWTExpiredEvent(new ExpiredTokenException(), new Response()),
        ];

        $corps = [];
        foreach ($evenements as $evenement) {
            $this->abonne->surEchec($evenement);
            $reponse = $evenement->getResponse();
            self::assertNotNull($reponse);
            self::assertSame(401, $reponse->getStatusCode());
            self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
            self::assertSame('Bearer', $reponse->headers->get('WWW-Authenticate'));
            $corps[] = $reponse->getContent();
        }

        self::assertCount(1, array_unique($corps), 'absent, falsifié ou expiré : rien n\'indique pourquoi');
        self::assertSame('{"message":"Authentification requise."}', $corps[0]);
    }

    /**
     * @param array<string, mixed> $modifs
     *
     * @return array<string, mixed>
     */
    private function charge(array $modifs = []): array
    {
        return array_merge(['sub' => '00000000-0000-4000-8000-000000000001', 'typ' => TypeJeton::Acces->value,
            'jti' => self::JTI, 'fam' => self::FAMILLE, 'iat' => time(), 'exp' => time() + 900], $modifs);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Auth\AuthWebTestCase;
use App\Tests\Support\Auth\JwtForge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests de sécurité du jeton d'accès (CDC §14.4, ADR-0003) : jeton falsifié, « alg: none », confusion
 * d'algorithme, mauvaise clé, expiré, révoqué, à portée limitée, compte supprimé, en-têtes détournés.
 * Chaque attaque doit finir en 401 générique, sans indication de la cause.
 */
#[Group('database')]
#[Group('redis')]
final class JwtAttaquesTest extends AuthWebTestCase
{
    private const GENERIQUE = ['message' => 'Authentification requise.'];

    protected static function adresseIp(): string
    {
        return '198.51.100.20';
    }

    public function testUnJetonValideEstAccepte(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];

        $this->json('GET', '/api/moi', jeton: $jeton);

        self::assertSame(200, $this->etat()['statut'], 'témoin : sans lui, les 401 ci-dessous ne prouveraient rien');
    }

    public function testChargeFalsifieeAvecLaSignatureOriginale(): void
    {
        $client = $this->creerClient();
        $admin = (string) $this->connexion->fetchOne("SELECT id::text FROM utilisateur WHERE email = 'admin@sidekey.test'");
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];
        $charge = JwtForge::charge($jeton);
        $charge['sub'] = $admin;   // usurpation du compte administrateur

        $this->json('GET', '/api/moi', jeton: JwtForge::chargeRemplacee($jeton, $charge));

        $this->assertRefus401();
    }

    public function testSignatureAlteree(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];

        $this->json('GET', '/api/moi', jeton: JwtForge::signatureAlteree($jeton));

        $this->assertRefus401();
    }

    /** @return iterable<string, array{string}> */
    public static function variantesAlgNone(): iterable
    {
        yield 'none' => ['none'];
        yield 'None' => ['None'];
        yield 'NONE' => ['NONE'];
        yield 'nOnE' => ['nOnE'];
        yield 'vide' => [''];
    }

    #[DataProvider('variantesAlgNone')]
    public function testAlgNoneRefuse(string $alg): void
    {
        $client = $this->creerClient();
        $charge = $this->chargeValide($client['id']);

        $this->json('GET', '/api/moi', jeton: JwtForge::sansSignature($charge, $alg));

        $this->assertRefus401();
    }

    public function testConfusionRs256Hs256AvecLaClePublique(): void
    {
        // L'attaquant lit la clé PUBLIQUE (publique par nature) et signe un HS256 avec comme secret HMAC.
        $client = $this->creerClient();
        $publique = (string) file_get_contents($this->racine().'/config/jwt/public.pem');

        $this->json('GET', '/api/moi', jeton: JwtForge::hs256($this->chargeValide($client['id']), $publique));

        $this->assertRefus401();
    }

    public function testJetonSigneParUneAutreCle(): void
    {
        $client = $this->creerClient();

        $this->json('GET', '/api/moi', jeton: JwtForge::rs256AvecAutreCle($this->chargeValide($client['id'])));

        $this->assertRefus401();
    }

    public function testJetonExpire(): void
    {
        $client = $this->creerClient();
        $charge = $this->chargeValide($client['id']);
        $charge['iat'] = time() - 1000;
        $charge['exp'] = time() - 100;   // valablement signé, mais expiré depuis 100 s

        $this->json('GET', '/api/moi', jeton: $this->signer($charge));

        $this->assertRefus401();
    }

    public function testLeMemeJetonSigneEtNonExpireEstAccepte(): void
    {
        // Témoin de la forge : sans l'expiration, ce jeton (signé avec la vraie clé) passe.
        $client = $this->creerClient();

        $this->json('GET', '/api/moi', jeton: $this->signer($this->chargeValide($client['id'])));

        self::assertSame(200, $this->etat()['statut']);
    }

    public function testJetonRevoqueParSonJti(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];
        $this->redis->set('jwt:deny:'.JwtForge::charge($jeton)['jti'], '1', ['ex' => 60]);

        $this->json('GET', '/api/moi', jeton: $jeton);

        $this->assertRefus401();
    }

    public function testJetonRevoqueAvecSaSession(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];
        $this->redis->set('jwt:deny:fam:'.JwtForge::charge($jeton)['fam'], '1', ['ex' => 60]);

        $this->json('GET', '/api/moi', jeton: $jeton);

        $this->assertRefus401();
    }

    public function testJetonIntermediaireA2fRefuseSurToutesLesRoutes(): void
    {
        $client = $this->creerClient();
        $charge = $this->chargeValide($client['id']);
        $charge['typ'] = 'a2f';
        $jeton = $this->signer($charge);

        foreach ([['GET', '/api/moi'], ['POST', '/api/deconnexion'], ['GET', '/api/_sonde/commander']] as [$methode, $uri]) {
            $this->json($methode, $uri, jeton: $jeton);
            self::assertSame(401, $this->etat()['statut'], $uri);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function chargesIncompletes(): iterable
    {
        yield 'sans type' => [['typ' => null]];
        yield 'type refresh' => [['typ' => 'refresh']];
        yield 'sans jti' => [['jti' => null]];
        yield 'sans famille' => [['fam' => null]];
        yield 'sans sujet' => [['sub' => null]];
        yield 'sujet non UUID' => [['sub' => "1' OR '1'='1"]];
        yield 'sujet tableau' => [['sub' => ['$ne' => '']]];
    }

    /** @param array<string, mixed> $modifs */
    #[DataProvider('chargesIncompletes')]
    public function testChargeIncompleteOuHostileRefusee(array $modifs): void
    {
        $client = $this->creerClient();
        $charge = array_filter(array_merge($this->chargeValide($client['id']), $modifs), static fn (mixed $v): bool => null !== $v);

        $this->json('GET', '/api/moi', jeton: $this->signer($charge));

        $this->assertRefus401();
    }

    public function testCompteSupprimeOuInconnu(): void
    {
        $this->json('GET', '/api/moi', jeton: $this->signer($this->chargeValide('00000000-0000-4000-8000-0000000000ff')));

        $this->assertRefus401();
    }

    public function testLesRolesDuJetonNeServentJamaisAAutoriser(): void
    {
        // Jeton valablement signé qui PRÉTEND être administrateur : les droits viennent de la base.
        $client = $this->creerClient();
        $charge = $this->chargeValide($client['id']);
        $charge['roles'] = ['ROLE_ADMIN', 'ROLE_GESTIONNAIRE'];

        $this->json('GET', '/api/_sonde/gerer_roles_et_audit', jeton: $this->signer($charge));

        self::assertSame(403, $this->etat()['statut'], 'authentifié (le jeton est valide) mais sans le droit : 403, pas 200');
    }

    /** @return iterable<string, array{string}> */
    public static function enTetesDetournes(): iterable
    {
        yield 'schéma Basic' => ['Basic dXNlcjpwYXNz'];
        yield 'Bearer vide' => ['Bearer '];
        yield 'Bearer sans jeton' => ['Bearer'];
        yield 'jeton sans schéma' => ['n.importe.quoi'];
        yield 'schéma inconnu' => ['Token abc.def.ghi'];
        yield 'jeton tronqué' => ['Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9'];
        yield 'jeton à deux segments' => ['Bearer aaa.bbb'];
        yield 'octets de contrôle' => ["Bearer \x01\x02\x03"];
    }

    #[DataProvider('enTetesDetournes')]
    public function testEnTeteAuthorizationMalformeRefuse(string $valeur): void
    {
        $this->client->request('GET', '/api/moi', server: ['HTTP_AUTHORIZATION' => $valeur]);

        $this->assertRefus401();
    }

    public function testLeJetonNEstPasLuDansLUrlNiDansUnCookie(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/moi?bearer='.$jeton.'&access_token='.$jeton.'&jwt='.$jeton);
        $this->assertRefus401();

        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('BEARER', $jeton, null, '/', 'localhost', true));
        $this->client->request('GET', '/api/moi');
        $this->assertRefus401();
    }

    public function testLesReponsesDErreurNeDonnentAucuneIndicationSurLaCause(): void
    {
        $client = $this->creerClient();
        $valide = $this->connecter($client['email'])['corps']['jetonAcces'];
        $revoque = $this->connecter($client['email'])['corps']['jetonAcces'];
        $this->redis->set('jwt:deny:'.JwtForge::charge($revoque)['jti'], '1', ['ex' => 60]);

        $corps = [];
        foreach ([
            JwtForge::signatureAlteree($valide),
            JwtForge::sansSignature($this->chargeValide($client['id'])),
            $revoque,
            $this->signer(array_merge($this->chargeValide($client['id']), ['exp' => time() - 10, 'iat' => time() - 900])),
        ] as $jeton) {
            $this->json('GET', '/api/moi', jeton: $jeton);
            $corps[] = $this->client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($corps), 'falsifié, sans signature, révoqué ou expiré : même réponse');
        self::assertStringNotContainsStringIgnoringCase('expired', $corps[0]);
        self::assertStringNotContainsStringIgnoringCase('signature', $corps[0]);
        self::assertStringNotContainsStringIgnoringCase('revoked', $corps[0]);
    }

    private function assertRefus401(): void
    {
        $etat = $this->etat();
        self::assertSame(401, $etat['statut']);
        self::assertSame(self::GENERIQUE, $etat['corps']);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /** @return array<string, mixed> charge d'un jeton d'accès régulier (à signer avec la vraie clé ou à falsifier) */
    private function chargeValide(string $compteId): array
    {
        return [
            'sub' => $compteId,
            'jti' => bin2hex(random_bytes(16)),
            'fam' => \sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6))),
            'typ' => 'access',
            'iat' => time(),
            'exp' => time() + 900,
        ];
    }

    /** @param array<string, mixed> $charge */
    private function signer(array $charge): string
    {
        $cle = (string) file_get_contents($this->racine().'/config/jwt/private.pem');

        return JwtForge::rs256($charge, $cle, (string) ($_SERVER['JWT_PASSPHRASE'] ?? $_ENV['JWT_PASSPHRASE'] ?? ''));
    }

    private function racine(): string
    {
        return \dirname(__DIR__, 2);
    }
}

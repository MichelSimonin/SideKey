<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Tests\Support\Auth\AuthWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Authentification de bout en bout : HTTP → pare-feu JWT → services → PostgreSQL et Redis réels.
 * Parcours nominaux et cas d'exception (CDC §11.4, US-20, US-21). Les attaques sont dans tests/Security.
 */
#[Group('database')]
#[Group('redis')]
final class AuthControllerTest extends AuthWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.10';
    }

    // --- Inscription ---------------------------------------------------------------------------

    public function testInscriptionCreeUnCompteClientSansSecret(): void
    {
        $email = 'auth-test-inscrit-'.bin2hex(random_bytes(3)).'@example.test';

        $this->json('POST', '/api/inscription', $this->inscription($email));
        $etat = $this->etat();

        self::assertSame(201, $etat['statut']);
        self::assertSame($email, $etat['corps']['utilisateur']['email']);
        self::assertSame(['CLIENT'], $etat['corps']['utilisateur']['roles']);
        self::assertFalse($etat['corps']['utilisateur']['emailVerifie']);
        self::assertStringNotContainsString('argon2', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        // Le mot de passe est haché en argon2id en base (CDC §11.4).
        $hash = (string) $this->connexion->fetchOne('SELECT mot_de_passe_hash FROM utilisateur WHERE email = :e', ['e' => $email]);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertSame(1, (int) $this->connexion->fetchOne(
            "SELECT count(*) FROM utilisateur_role ur JOIN utilisateur u ON u.id = ur.utilisateur_id WHERE u.email = :e AND ur.role_code = 'CLIENT'",
            ['e' => $email],
        ));
    }

    public function testInscriptionPuisConnexion(): void
    {
        $email = 'auth-test-parcours-'.bin2hex(random_bytes(3)).'@example.test';
        $this->json('POST', '/api/inscription', $this->inscription($email));
        self::assertSame(201, $this->etat()['statut']);

        $connexion = $this->connecter($email, self::MOT_DE_PASSE);

        self::assertSame(200, $connexion['statut']);
        self::assertSame('connecte', $connexion['corps']['etape']);
    }

    public function testInscriptionRefuseUnEmailDejaUtilise(): void
    {
        $client = $this->creerClient();

        $this->json('POST', '/api/inscription', $this->inscription(strtoupper($client['email'])));

        self::assertSame(409, $this->etat()['statut']);
        self::assertSame('Un compte existe déjà avec cet e-mail.', $this->etat()['corps']['message']);
    }

    public function testInscriptionValidationTraduiteEnAnglais(): void
    {
        $this->json('POST', '/api/inscription', ['email' => 'pas-un-email', 'motDePasse' => 'court', 'prenom' => '', 'nom' => '<script>', 'langue' => 'de', 'consentement' => false],
            serveur: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $etat = $this->etat();

        self::assertSame(422, $etat['statut']);
        self::assertSame('Some data is invalid.', $etat['corps']['message']);
        self::assertEqualsCanonicalizing(['email', 'motDePasse', 'prenom', 'nom', 'langue', 'consentement'], array_keys($etat['corps']['erreurs']));
        self::assertSame('The password must be at least 12 characters long.', $etat['corps']['erreurs']['motDePasse']);
        self::assertSame('This field contains forbidden characters.', $etat['corps']['erreurs']['nom']);
        self::assertSame('You must accept the terms to create an account.', $etat['corps']['erreurs']['consentement']);
        self::assertStringNotContainsString('<script>', (string) $this->client->getResponse()->getContent(), 'la saisie n\'est pas renvoyée');
    }

    public function testInscriptionMessagesFrancais(): void
    {
        $this->json('POST', '/api/inscription', ['email' => 'a@example.test', 'motDePasse' => 'password12345', 'prenom' => 'A', 'nom' => 'B', 'consentement' => true]);

        self::assertSame(422, $this->etat()['statut']);
        self::assertSame('Ce mot de passe est trop courant. Choisissez une phrase de passe.', $this->etat()['corps']['erreurs']['motDePasse']);
    }

    // --- Connexion -----------------------------------------------------------------------------

    public function testConnexionRenvoieUnJetonDAccesEtPoseLeCookieDeRefresh(): void
    {
        $client = $this->creerClient();

        $this->json('POST', '/api/connexion', ['email' => $client['email'], 'motDePasse' => self::MOT_DE_PASSE]);
        $etat = $this->etat();

        self::assertSame(200, $etat['statut']);
        self::assertSame('Bearer', $etat['corps']['typeJeton']);
        self::assertSame(900, $etat['corps']['expireDans'], '15 minutes');
        self::assertSame($client['id'], $etat['corps']['utilisateur']['id']);
        self::assertArrayNotHasKey('refresh', $etat['corps'], 'le refresh ne voyage pas dans le corps');
        self::assertStringNotContainsString((string) $etat['refresh'], (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // JWT RS256 : en-tête, charge et signature.
        [$entete, $charge] = array_map(static fn (string $p): array => (array) json_decode((string) base64_decode(strtr($p, '-_', '+/')), true), \array_slice(explode('.', $etat['corps']['jetonAcces']), 0, 2));
        self::assertSame('RS256', $entete['alg']);
        self::assertSame($client['id'], $charge['sub']);
        self::assertSame('access', $charge['typ']);
        self::assertSame(900, $charge['exp'] - $charge['iat'], 'durée de vie de 15 minutes');
        self::assertArrayNotHasKey('email', $charge, 'aucune donnée personnelle dans le jeton');
    }

    public function testLeCookieDeRefreshEstProtege(): void
    {
        $client = $this->creerClient();
        $this->json('POST', '/api/connexion', ['email' => $client['email'], 'motDePasse' => self::MOT_DE_PASSE]);

        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        $refresh = $cookies['sk_refresh'];
        self::assertTrue($refresh->isHttpOnly());
        self::assertTrue($refresh->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $refresh->getSameSite());
        self::assertSame('/api/token/refresh', $refresh->getPath());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $refresh->getValue(), 'opaque : 256 bits aléatoires');
        // Redis : seule l'empreinte est stockée, avec un TTL de 7 jours.
        $cle = 'refresh:'.hash('sha256', $refresh->getValue());
        self::assertSame($client['id'], explode(':', (string) $this->redis->get($cle))[0]);
        self::assertEqualsWithDelta(604_800, $this->redis->ttl($cle), 10);
        self::assertSame(0, $this->redis->exists('refresh:'.$refresh->getValue()), 'jamais le jeton en clair');
    }

    public function testConnexionInsensibleALaCasseDeLEmail(): void
    {
        $client = $this->creerClient();

        self::assertSame(200, $this->connecter(strtoupper($client['email']))['statut']);
    }

    public function testMauvaisMotDePasse401Generique(): void
    {
        $client = $this->creerClient();

        $etat = $this->connecter($client['email'], 'un mauvais mot de passe');

        self::assertSame(401, $etat['statut']);
        self::assertSame(['message' => 'E-mail ou mot de passe incorrect.'], $etat['corps']);
        self::assertNull($etat['refresh'], 'aucun cookie de session');
    }

    public function testCompteDesactiveRefuse(): void
    {
        self::assertSame(401, $this->connecter('client15@sidekey.test', self::MOT_DE_PASSE_JEU_ESSAI)['statut'], 'même avec le bon mot de passe');
    }

    public function testUnCompteDuJeuDEssaiEnBcryptSeConnecteEtMigreVersArgon2id(): void
    {
        $email = 'client02@sidekey.test';
        $avant = (string) $this->connexion->fetchOne('SELECT mot_de_passe_hash FROM utilisateur WHERE email = :e', ['e' => $email]);

        try {
            $etat = $this->connecter($email, self::MOT_DE_PASSE_JEU_ESSAI);

            self::assertSame(200, $etat['statut']);
            $apres = (string) $this->connexion->fetchOne('SELECT mot_de_passe_hash FROM utilisateur WHERE email = :e', ['e' => $email]);
            self::assertStringStartsWith('$argon2id$', $apres, 'le hash bcrypt du jeu d\'essai est remplacé à la connexion');
            // Et le compte continue à se connecter avec le nouveau hash.
            self::assertSame(200, $this->connecter($email, self::MOT_DE_PASSE_JEU_ESSAI)['statut']);
        } finally {
            $this->connexion->executeStatement('UPDATE utilisateur SET mot_de_passe_hash = :h WHERE email = :e', ['h' => $avant, 'e' => $email]);
        }
    }

    public function testUnCompteAvecA2fRecoitSeulementUnJetonIntermediaire(): void
    {
        $etat = $this->connecter('admin@sidekey.test', self::MOT_DE_PASSE_JEU_ESSAI);

        self::assertSame(200, $etat['statut']);
        self::assertSame('a2f', $etat['corps']['etape']);
        self::assertArrayNotHasKey('utilisateur', $etat['corps']);
        self::assertNull($etat['refresh'], 'aucun refresh avant le code TOTP');
        self::assertNull($etat['csrf']);
        self::assertLessThanOrEqual(300, $etat['corps']['expireDans']);

        // Le jeton intermédiaire n'ouvre aucune route de l'API.
        $this->json('GET', '/api/moi', jeton: $etat['corps']['jetonIntermediaire']);
        self::assertSame(401, $this->etat()['statut']);
    }

    public function testConnexionDonneesInvalides422(): void
    {
        $this->json('POST', '/api/connexion', ['email' => '', 'motDePasse' => '']);

        self::assertSame(422, $this->etat()['statut']);
        self::assertEqualsCanonicalizing(['email', 'motDePasse'], array_keys($this->etat()['corps']['erreurs']));
    }

    public function testConnexionLimiteeAuDixiemeEssaiAvecRetryAfter(): void
    {
        $client = $this->creerClient();
        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(401, $this->connecter($client['email'], 'faux mot de passe '.$i)['statut']);
        }

        // 11e tentative : bloquée même avec le bon mot de passe (le compteur précède la vérification).
        $etat = $this->connecter($client['email'], self::MOT_DE_PASSE);

        self::assertSame(429, $etat['statut']);
        self::assertSame('Trop de tentatives. Réessayez plus tard.', $etat['corps']['message']);
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(880, $retryAfter);
        self::assertLessThanOrEqual(900, $retryAfter, 'fenêtre de 15 minutes');
        self::assertEqualsWithDelta(900, $this->redis->ttl('rl:login:'.static::adresseIp()), 10, 'clé rl:login:{ip} (CDC §9.8)');
    }

    // --- Profil et déconnexion -----------------------------------------------------------------

    public function testMoiRenvoieLeProfilDuJeton(): void
    {
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];

        $this->json('GET', '/api/moi', jeton: $jeton);
        $etat = $this->etat();

        self::assertSame(200, $etat['statut']);
        self::assertSame($client['id'], $etat['corps']['utilisateur']['id']);
        self::assertSame(['commander', 'deposer_avis'], $etat['corps']['utilisateur']['permissions']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testMoiSansJeton401(): void
    {
        $this->json('GET', '/api/moi');

        self::assertSame(401, $this->etat()['statut']);
        self::assertSame(['message' => 'Authentification requise.'], $this->etat()['corps']);
    }

    public function testDeconnexionRevoqueLeRefreshEtLeJetonDAcces(): void
    {
        $client = $this->creerClient();
        $connexion = $this->connecter($client['email']);
        $jeton = $connexion['corps']['jetonAcces'];
        $cleRefresh = 'refresh:'.hash('sha256', (string) $connexion['refresh']);
        self::assertSame(1, $this->redis->exists($cleRefresh));

        $this->json('POST', '/api/deconnexion', jeton: $jeton);

        self::assertSame(204, $this->etat()['statut']);
        self::assertSame(0, $this->redis->exists($cleRefresh), 'refresh supprimé de Redis');
        // Cookies effacés.
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isCleared(), $cookie->getName());
        }
        // jwt:deny:{jti}, TTL = durée de vie restante du jeton.
        $charge = (array) json_decode((string) base64_decode(strtr(explode('.', $jeton)[1], '-_', '+/')), true);
        $cleRevocation = 'jwt:deny:'.$charge['jti'];
        self::assertSame(1, $this->redis->exists($cleRevocation));
        self::assertGreaterThan(0, $this->redis->ttl($cleRevocation));
        self::assertLessThanOrEqual(900, $this->redis->ttl($cleRevocation));
        // Le jeton d'accès est immédiatement refusé.
        $this->json('GET', '/api/moi', jeton: $jeton);
        self::assertSame(401, $this->etat()['statut']);
        // Et le refresh ne peut plus servir.
        $this->rafraichir($connexion['refresh'], $connexion['csrf']);
        self::assertSame(401, $this->etat()['statut']);
    }

    public function testDeconnexionSansJeton401(): void
    {
        $this->json('POST', '/api/deconnexion');

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testLaDeconnexionDUnAppareilLaisseLesAutresConnectes(): void
    {
        $client = $this->creerClient();
        $telephone = $this->connecter($client['email']);
        $ordinateur = $this->connecter($client['email']);

        $this->json('POST', '/api/deconnexion', jeton: $telephone['corps']['jetonAcces']);

        $this->json('GET', '/api/moi', jeton: $ordinateur['corps']['jetonAcces']);
        self::assertSame(200, $this->etat()['statut'], 'l\'autre session reste valide');
    }

    // --- Rafraîchissement ----------------------------------------------------------------------

    public function testRafraichissementRemplaceLesDeuxJetons(): void
    {
        $client = $this->creerClient();
        $connexion = $this->connecter($client['email']);

        $this->rafraichir($connexion['refresh'], $connexion['csrf']);
        $suite = $this->etat();

        self::assertSame(200, $suite['statut']);
        self::assertNotSame($connexion['corps']['jetonAcces'], $suite['corps']['jetonAcces']);
        self::assertNotSame($connexion['refresh'], $suite['refresh'], 'rotation : nouveau refresh à chaque échange');
        self::assertNotSame($connexion['csrf'], $suite['csrf'], 'le jeton CSRF suit le refresh');
        self::assertSame($client['id'], $suite['corps']['utilisateur']['id']);
        self::assertSame(0, $this->redis->exists('refresh:'.hash('sha256', (string) $connexion['refresh'])), 'ancien refresh consommé');
        self::assertSame(1, $this->redis->exists('refresh:'.hash('sha256', (string) $suite['refresh'])));
        // Le nouveau jeton d'accès fonctionne.
        $this->json('GET', '/api/moi', jeton: $suite['corps']['jetonAcces']);
        self::assertSame(200, $this->etat()['statut']);
    }

    public function testUnRefreshEstAUsageUnique(): void
    {
        $client = $this->creerClient();
        $connexion = $this->connecter($client['email']);
        $this->rafraichir($connexion['refresh'], $connexion['csrf']);
        self::assertSame(200, $this->etat()['statut']);

        $this->rafraichir($connexion['refresh'], $connexion['csrf']);   // rejeu

        self::assertSame(401, $this->etat()['statut']);
        self::assertSame(['message' => 'Session expirée ou invalide. Veuillez vous reconnecter.'], $this->etat()['corps']);
    }

    public function testLaReutilisationRevoqueToutLaFamilleMemeLeRefreshLegitime(): void
    {
        $client = $this->creerClient();
        $connexion = $this->connecter($client['email']);
        $this->rafraichir($connexion['refresh'], $connexion['csrf']);
        $legitime = $this->etat();                                         // l'utilisateur légitime a tourné
        $this->rafraichir($connexion['refresh'], $connexion['csrf']);      // le voleur rejoue l'ancien
        self::assertSame(401, $this->etat()['statut']);

        // La victime est déconnectée : son refresh et son jeton d'accès sont révoqués.
        $this->rafraichir($legitime['refresh'], $legitime['csrf']);
        self::assertSame(401, $this->etat()['statut'], 'refresh de la famille révoqué');
        $this->json('GET', '/api/moi', jeton: $legitime['corps']['jetonAcces']);
        self::assertSame(401, $this->etat()['statut'], 'jeton d\'accès de la famille révoqué');
    }

    public function testRafraichissementSansCookie401(): void
    {
        $this->rafraichir(null, null, enteteCsrf: 'x');

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testRafraichissementPourUnCompteDesactiveDepuisLaConnexion(): void
    {
        $client = $this->creerClient();
        $connexion = $this->connecter($client['email']);
        $this->connexion->executeStatement('UPDATE utilisateur SET actif = false WHERE id = :id::uuid', ['id' => $client['id']]);

        $this->rafraichir($connexion['refresh'], $connexion['csrf']);

        self::assertSame(401, $this->etat()['statut']);
        // Le jeton d'accès déjà émis est refusé aussitôt : l'utilisateur est relu en base à chaque requête.
        $this->json('GET', '/api/moi', jeton: $connexion['corps']['jetonAcces']);
        self::assertSame(401, $this->etat()['statut']);
    }

    // --- Panier anonyme (F-05) non cassé ---------------------------------------------------------

    public function testLePanierVisiteurResteAccessibleSansJeton(): void
    {
        $this->json('GET', '/api/panier');

        self::assertSame(200, $this->etat()['statut']);
        self::assertSame(0, $this->etat()['corps']['nombreArticles']);
    }

    public function testLaSondeDeSanteResteAccessibleSansJeton(): void
    {
        $this->json('GET', '/api/health');

        self::assertSame(200, $this->etat()['statut']);
    }

    /** @return array<string, mixed> */
    private function inscription(string $email): array
    {
        return ['email' => $email, 'motDePasse' => self::MOT_DE_PASSE, 'prenom' => 'Ines', 'nom' => 'Roy', 'langue' => 'fr', 'consentement' => true];
    }
}

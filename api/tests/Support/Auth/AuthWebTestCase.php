<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Repository\UtilisateurRepository;
use App\Security\Http\CookiesSession;
use App\Security\Http\ProtectionCsrf;
use App\Service\Auth\SessionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests HTTP d'authentification : API réelle, PostgreSQL (base sidekey_test chargée) et Redis réels.
 *
 * Chaque classe de test utilise sa propre adresse IP (la limitation de débit est par IP) et nettoie ses
 * comptes, ses compteurs et ses jetons : les tests ne se polluent pas et ne laissent rien dans Redis.
 */
abstract class AuthWebTestCase extends WebTestCase
{
    public const MOT_DE_PASSE = 'une phrase de passe solide';
    public const ORIGINE = 'http://localhost:5173';
    /** Mot de passe de tous les comptes du jeu d'essai (api/sql/seed/01_jeu_essai.sql). */
    public const MOT_DE_PASSE_JEU_ESSAI = 'SideKey-Dev-2026!';

    protected KernelBrowser $client;
    protected \Redis $redis;
    protected Connection $connexion;

    /** @var array<string, string> sessions ouvertes pendant le test */
    private array $familles = [];

    /** Adresse IP propre à la classe de test : compteurs de débit isolés. */
    abstract protected static function adresseIp(): string;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_ACCEPT_LANGUAGE' => 'fr', 'REMOTE_ADDR' => static::adresseIp()]);
        $redis = static::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $connexion = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    protected function nettoyer(): void
    {
        $this->redis->del('rl:login:'.static::adresseIp(), 'rl:register:'.static::adresseIp());
        // Sessions ouvertes par le test : on retire leurs jetons, leurs marqueurs et leur révocation.
        foreach ($this->familles as $famille) {
            $membres = $this->redis->sMembers('refresh:family:'.$famille);
            foreach (\is_array($membres) ? $membres : [] as $empreinte) {
                $this->redis->del('refresh:'.$empreinte, 'refresh:used:'.$empreinte);
            }
            $this->redis->del('refresh:family:'.$famille, 'jwt:deny:fam:'.$famille);
        }
        $this->familles = [];
        $this->connexion->executeStatement("DELETE FROM utilisateur_role WHERE utilisateur_id IN (SELECT id FROM utilisateur WHERE email LIKE 'auth-test-%')");
        $this->connexion->executeStatement("DELETE FROM utilisateur WHERE email LIKE 'auth-test-%'");
    }

    /** Retient la session d'un jeton d'accès pour la nettoyer (revendication « fam »). */
    protected function noter(string $jwt): void
    {
        $charge = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/'), true), true);
        if (\is_array($charge) && \is_string($charge['fam'] ?? null)) {
            $this->familles[$charge['fam']] = $charge['fam'];
        }
    }

    /** @return array{id: string, email: string} compte CLIENT créé directement en base (hash argon2id de MOT_DE_PASSE) */
    protected function creerClient(string $suffixe = 'client'): array
    {
        $email = 'auth-test-'.$suffixe.'-'.bin2hex(random_bytes(4)).'@example.test';
        $repository = static::getContainer()->get(UtilisateurRepository::class);
        self::assertInstanceOf(UtilisateurRepository::class, $repository);
        $id = $repository->creerClient($email, FabriqueCompte::hash(self::MOT_DE_PASSE), 'Test', 'Auth', 'fr');

        return ['id' => $id, 'email' => $email];
    }

    /**
     * Connexion par l'API.
     *
     * @return array{statut: int, corps: array<string, mixed>, refresh: ?string, csrf: ?string}
     */
    protected function connecter(string $email, string $motDePasse = self::MOT_DE_PASSE): array
    {
        $this->json('POST', '/api/connexion', ['email' => $email, 'motDePasse' => $motDePasse]);

        return $this->etat();
    }

    /** @return array{statut: int, corps: array<string, mixed>, refresh: ?string, csrf: ?string} */
    protected function etat(): array
    {
        $reponse = $this->client->getResponse();
        $cookies = [];
        foreach ($reponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $contenu = (string) $reponse->getContent();
        $corps = '' === $contenu ? [] : (array) json_decode($contenu, true, flags: \JSON_THROW_ON_ERROR);
        if (\is_string($corps['jetonAcces'] ?? null)) {
            $this->noter($corps['jetonAcces']);
        }

        return [
            'statut' => $reponse->getStatusCode(),
            'corps' => $corps,
            'refresh' => $cookies[CookiesSession::REFRESH] ?? null,
            'csrf' => $cookies[CookiesSession::CSRF] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $corps
     * @param array<string, string>     $serveur
     */
    protected function json(string $methode, string $uri, ?array $corps = null, ?string $jeton = null, array $serveur = []): void
    {
        $serveur += ['CONTENT_TYPE' => 'application/json'];
        if (null !== $jeton) {
            $serveur['HTTP_AUTHORIZATION'] = 'Bearer '.$jeton;
        }
        $this->client->request($methode, $uri, content: null === $corps ? null : (string) json_encode($corps), server: $serveur);
    }

    /** Rafraîchissement tel que la SPA le fait : cookies de la session, Origin et jeton CSRF. */
    protected function rafraichir(?string $refresh, ?string $csrf, ?string $enteteCsrf = null, ?string $origine = self::ORIGINE): void
    {
        $this->client->getCookieJar()->clear();
        if (null !== $refresh) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(CookiesSession::REFRESH, $refresh, null, '/api/token/refresh', 'localhost', true));
        }
        if (null !== $csrf) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(CookiesSession::CSRF, $csrf, null, '/', 'localhost', true));
        }
        $serveur = [];
        if (null !== $origine) {
            $serveur['HTTP_ORIGIN'] = $origine;
        }
        $enteteCsrf ??= $csrf;
        if (null !== $enteteCsrf) {
            $serveur['HTTP_X_CSRF_TOKEN'] = $enteteCsrf;
        }
        $this->client->request('POST', '/api/token/refresh', server: $serveur);
    }

    /** Jeton d'accès émis directement pour un compte du jeu d'essai (y compris l'ADMIN, qui passe par l'A2F à la connexion). */
    protected function jetonPour(string $email): string
    {
        $repository = static::getContainer()->get(UtilisateurRepository::class);
        self::assertInstanceOf(UtilisateurRepository::class, $repository);
        $compte = $repository->parEmail($email);
        self::assertNotNull($compte, $email.' doit exister (make db-seed)');
        $sessions = static::getContainer()->get(SessionService::class);
        self::assertInstanceOf(SessionService::class, $sessions);

        $jeton = $sessions->ouvrir($compte)->jetonAcces;
        $this->noter($jeton);

        return $jeton;
    }

    protected function csrfPour(string $refresh): string
    {
        $csrf = static::getContainer()->get(ProtectionCsrf::class);
        self::assertInstanceOf(ProtectionCsrf::class, $csrf);

        return $csrf->jetonPour($refresh);
    }
}

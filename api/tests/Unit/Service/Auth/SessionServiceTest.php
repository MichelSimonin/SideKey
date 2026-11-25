<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Exception\SessionInvalideException;
use App\Service\Auth\SessionService;
use App\Tests\Support\Auth\ComptesEnMemoire;
use App\Tests\Support\Auth\EmetteurFactice;
use App\Tests\Support\Auth\FabriqueCompte;
use App\Tests\Support\Auth\RevocationEnMemoire;
use App\Tests\Support\Auth\StockRefreshEnMemoire;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/** Cycle de vie des jetons : rotation à usage unique, détection de réutilisation, déconnexion (CDC §11.4). */
final class SessionServiceTest extends TestCase
{
    private ComptesEnMemoire $comptes;
    private StockRefreshEnMemoire $refresh;
    private RevocationEnMemoire $revocation;
    private MockClock $horloge;
    private SessionService $service;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->comptes = new ComptesEnMemoire();
        $this->comptes->ajouter(FabriqueCompte::creer(1));
        $this->refresh = new StockRefreshEnMemoire();
        $this->revocation = new RevocationEnMemoire();
        $this->horloge = new MockClock('2026-11-25 12:00:00');
        $this->logs = [];
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->service = new SessionService($this->refresh, $this->revocation, new EmetteurFactice(), $this->comptes, $this->horloge, $logger);
    }

    public function testOuvrirEmetUnAccesDe15MinutesEtUnRefreshOpaque(): void
    {
        $session = $this->service->ouvrir($this->compte());

        $acces = EmetteurFactice::lire($session->jetonAcces);
        self::assertSame('access', $acces['type']);
        self::assertSame(900, $acces['duree']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $acces['jti']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $session->jetonRefresh, '256 bits aléatoires, opaque');
        self::assertSame([hash('sha256', $session->jetonRefresh)], array_keys($this->refresh->actifs), 'seule l\'empreinte est stockée');
    }

    public function testDeuxSessionsOntDesJetonsEtDesFamillesDistincts(): void
    {
        $a = $this->service->ouvrir($this->compte());
        $b = $this->service->ouvrir($this->compte());

        self::assertNotSame($a->jetonRefresh, $b->jetonRefresh);
        self::assertNotSame(EmetteurFactice::lire($a->jetonAcces)['famille'], EmetteurFactice::lire($b->jetonAcces)['famille']);
    }

    public function testRotationRemplaceLeRefreshEtGardeLaFamille(): void
    {
        $premiere = $this->service->ouvrir($this->compte());

        $seconde = $this->service->rafraichir($premiere->jetonRefresh);

        self::assertNotSame($premiere->jetonRefresh, $seconde->jetonRefresh, 'rotation : un nouveau refresh à chaque échange');
        self::assertSame(
            EmetteurFactice::lire($premiere->jetonAcces)['famille'],
            EmetteurFactice::lire($seconde->jetonAcces)['famille'],
            'même famille',
        );
        self::assertNotSame(EmetteurFactice::lire($premiere->jetonAcces)['jti'], EmetteurFactice::lire($seconde->jetonAcces)['jti']);
        self::assertCount(1, $this->refresh->actifs, 'l\'ancien refresh est consommé');
    }

    public function testUnRefreshEstAUsageUnique(): void
    {
        $session = $this->service->ouvrir($this->compte());
        $this->service->rafraichir($session->jetonRefresh);

        $this->expectException(SessionInvalideException::class);
        $this->service->rafraichir($session->jetonRefresh);
    }

    public function testLaReutilisationRevoqueToutLaFamille(): void
    {
        $premiere = $this->service->ouvrir($this->compte());
        $famille = EmetteurFactice::lire($premiere->jetonAcces)['famille'];
        $seconde = $this->service->rafraichir($premiere->jetonRefresh);

        try {
            $this->service->rafraichir($premiere->jetonRefresh);   // rejeu d'un jeton volé
            self::fail('La réutilisation doit être refusée.');
        } catch (SessionInvalideException) {
        }

        self::assertSame([], $this->refresh->actifs, 'le refresh légitime est lui aussi révoqué');
        self::assertArrayHasKey($famille, $this->revocation->familles, 'les jetons d\'accès de la session sont révoqués');
        self::assertSame(900, $this->revocation->familles[$famille], 'jusqu\'à l\'expiration d\'un jeton d\'accès');
        $this->expectException(SessionInvalideException::class);
        $this->service->rafraichir($seconde->jetonRefresh);   // le voleur comme la victime doivent se reconnecter
    }

    public function testLaReutilisationEstJournaliseeSansLeJeton(): void
    {
        $session = $this->service->ouvrir($this->compte());
        $this->service->rafraichir($session->jetonRefresh);
        try {
            $this->service->rafraichir($session->jetonRefresh);
        } catch (SessionInvalideException) {
        }

        self::assertCount(1, $this->logs);
        self::assertStringNotContainsString($session->jetonRefresh, json_encode($this->logs, \JSON_THROW_ON_ERROR), 'Secure Logger');
    }

    /** @return iterable<string, array{string}> */
    public static function jetonsMalformes(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['abc'];
        yield 'majuscules' => [str_repeat('A', 64)];
        yield 'caractères hors hexadécimal' => [str_repeat('z', 64)];
        yield 'trop long' => [str_repeat('a', 65)];
        yield 'injection de clé Redis' => ["refresh:*\r\nDEL x"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jetonsMalformes')]
    public function testJetonMalformeRefuseSansToucherAuStock(string $jeton): void
    {
        $this->service->ouvrir($this->compte());

        try {
            $this->service->rafraichir($jeton);
            self::fail('Un jeton malformé doit être refusé.');
        } catch (SessionInvalideException) {
        }

        self::assertCount(1, $this->refresh->actifs, 'la session existante n\'est pas affectée');
        self::assertSame([], $this->refresh->consommes);
    }

    public function testJetonInconnu(): void
    {
        $this->expectException(SessionInvalideException::class);
        $this->service->rafraichir(bin2hex(random_bytes(32)));
    }

    public function testCompteDesactiveDepuisLaConnexionArreteLaSession(): void
    {
        $session = $this->service->ouvrir($this->compte());
        $this->comptes->comptes[FabriqueCompte::uuid(1)] = FabriqueCompte::creer(1, actif: false);

        try {
            $this->service->rafraichir($session->jetonRefresh);
            self::fail('Un compte désactivé ne peut plus rafraîchir sa session.');
        } catch (SessionInvalideException) {
        }

        self::assertSame([], $this->refresh->actifs);
        self::assertCount(1, $this->revocation->familles);
    }

    public function testCompteSupprimeArreteLaSession(): void
    {
        $session = $this->service->ouvrir($this->compte());
        unset($this->comptes->comptes[FabriqueCompte::uuid(1)]);

        $this->expectException(SessionInvalideException::class);
        $this->service->rafraichir($session->jetonRefresh);
    }

    public function testDeconnexionRevoqueLeRefreshEtLeJetonDAcces(): void
    {
        $session = $this->service->ouvrir($this->compte());
        $acces = EmetteurFactice::lire($session->jetonAcces);
        $expiration = $this->horloge->now()->getTimestamp() + 600;   // il reste 10 minutes au jeton

        $this->service->deconnecter($acces['jti'], $expiration, $acces['famille']);

        self::assertSame([], $this->refresh->actifs, 'refresh supprimé de Redis');
        self::assertSame([$acces['jti'] => 600], $this->revocation->jetons, 'jwt:deny:{jti} pour la durée restante');
        self::assertArrayHasKey($acces['famille'], $this->revocation->familles);
        $this->expectException(SessionInvalideException::class);
        $this->service->rafraichir($session->jetonRefresh);
    }

    public function testDeconnexionNeTouchePasLesAutresSessions(): void
    {
        $a = $this->service->ouvrir($this->compte());
        $b = $this->service->ouvrir($this->compte());
        $acces = EmetteurFactice::lire($a->jetonAcces);

        $this->service->deconnecter($acces['jti'], $this->horloge->now()->getTimestamp() + 600, $acces['famille']);

        self::assertNotNull($this->service->rafraichir($b->jetonRefresh)->jetonAcces, 'l\'appareil B reste connecté');
    }

    public function testJetonIntermediaireA2fEstDeTypeA2fSansRefresh(): void
    {
        $jeton = EmetteurFactice::lire($this->service->emettreJetonIntermediaire($this->compte()));

        self::assertSame('a2f', $jeton['type']);
        self::assertSame(300, $jeton['duree']);
        self::assertSame([], $this->refresh->actifs);
    }

    private function compte(): \App\Service\Auth\Compte
    {
        return FabriqueCompte::creer(1);
    }
}

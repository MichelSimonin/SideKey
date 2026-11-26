<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisCompteursFraude;
use App\Service\Paiement\ConfigurationFraude;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Compteurs anti-fraude contre un vrai Redis (CDC §9.8 : fraud:*) : fenêtre GLISSANTE de 24 h, blocage posé atomiquement au
 * troisième échec, TTL réels. Un faux Redis ne prouverait ni l'atomicité du script Lua ni les expirations.
 */
#[Group('redis')]
final class RedisCompteursFraudeTest extends KernelTestCase
{
    private const CLIENT = '5a1d2c3e-0000-4000-8000-00000000f070';
    private const AUTRE = '5a1d2c3e-0000-4000-8000-00000000f071';
    private const COMMANDE = '5a1d2c3e-0000-4000-8000-00000000f072';

    private \Redis $redis;
    private MockClock $horloge;
    private RedisCompteursFraude $compteurs;

    protected function setUp(): void
    {
        self::bootKernel();
        $redis = self::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->nettoyer();
        $this->horloge = new MockClock('2026-11-26 12:00:00');
        $this->compteurs = new RedisCompteursFraude($redis, new ConfigurationFraude(10, 3, 3600, 24, 150, 200), $this->horloge);
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function nettoyer(): void
    {
        foreach ([self::CLIENT, self::AUTRE] as $id) {
            $this->redis->del('fraud:keys24h:'.$id, 'fraud:fails:'.$id, 'fraud:block:'.$id);
        }
    }

    // ---- Clés achetées : fenêtre glissante de 24 h ----------------------------------------------------------------

    public function testLesClesSontComptéesSurUneFenetreGlissanteDe24Heures(): void
    {
        self::assertSame(0, $this->compteurs->clesAcheteesSur24h(self::CLIENT));

        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 4);
        self::assertSame(4, $this->compteurs->clesAcheteesSur24h(self::CLIENT));

        $this->horloge->sleep(23 * 3600 + 59 * 60);   // 23 h 59 plus tard : toujours comptées
        self::assertSame(4, $this->compteurs->clesAcheteesSur24h(self::CLIENT));

        $this->horloge->sleep(60 + 1);   // 24 h et 1 s : sorties de la fenêtre, sans qu'aucune clé ne soit « remise à zéro »
        self::assertSame(0, $this->compteurs->clesAcheteesSur24h(self::CLIENT));
    }

    public function testUneCleAchetéeIlYADixHeuresEtUneAutreMaintenantSAdditionnent(): void
    {
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 6);
        $this->horloge->sleep(10 * 3600);
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, '5a1d2c3e-0000-4000-8000-00000000f073', 3);

        self::assertSame(9, $this->compteurs->clesAcheteesSur24h(self::CLIENT), 'pas de remise à zéro à heure fixe : c\'est une vraie fenêtre glissante');

        $this->horloge->sleep(15 * 3600);   // les 6 premières ont plus de 24 h, les 3 suivantes 15 h
        self::assertSame(3, $this->compteurs->clesAcheteesSur24h(self::CLIENT));
    }

    public function testRejouerLaMemeCommandeNeCompteQuUneFois(): void
    {
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 3);
        $this->horloge->sleep(60);
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 3);

        self::assertSame(3, $this->compteurs->clesAcheteesSur24h(self::CLIENT));
    }

    public function testLeCompteurDUnClientNeToucheJamaisCeluiDUnAutre(): void
    {
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 5);

        self::assertSame(0, $this->compteurs->clesAcheteesSur24h(self::AUTRE));
    }

    public function testLaCleRedisEstUnEnsembleTrieAvecUneExpirationDe24Heures(): void
    {
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 2);

        self::assertSame(\Redis::REDIS_ZSET, $this->redis->type('fraud:keys24h:'.self::CLIENT));
        $ttl = $this->redis->ttl('fraud:keys24h:'.self::CLIENT);
        self::assertGreaterThan(86_300, $ttl);
        self::assertLessThanOrEqual(86_400, $ttl, 'CDC §9.8 : TTL de 24 h, jamais de clé sans expiration');
    }

    // ---- Échecs de paiement et blocage ----------------------------------------------------------------------------

    public function testLesEchecsDecomptentLesTentativesRestantesPuisBloquentAuTroisieme(): void
    {
        self::assertNull($this->compteurs->secondesDeBlocage(self::CLIENT));

        self::assertSame(2, $this->compteurs->enregistrerEchec(self::CLIENT));
        self::assertSame(1, $this->compteurs->enregistrerEchec(self::CLIENT));
        self::assertNull($this->compteurs->secondesDeBlocage(self::CLIENT), 'pas encore bloqué');
        self::assertSame(0, $this->compteurs->enregistrerEchec(self::CLIENT), 'le troisième échec bloque');

        $blocage = $this->compteurs->secondesDeBlocage(self::CLIENT);
        self::assertNotNull($blocage);
        self::assertGreaterThan(3_590, $blocage);
        self::assertLessThanOrEqual(3_600, $blocage, 'blocage d\'une heure (RG-14)');
        self::assertSame(0, $this->redis->exists('fraud:fails:'.self::CLIENT), 'le compteur d\'échecs est remis à zéro avec le blocage');
    }

    public function testLeCompteurDEchecsPorteUneExpirationEtNeBloqueJamaisPourToujours(): void
    {
        $this->compteurs->enregistrerEchec(self::CLIENT);

        $ttl = $this->redis->ttl('fraud:fails:'.self::CLIENT);
        self::assertGreaterThan(0, $ttl, 'un compteur sans expiration bloquerait un client pour toujours');
        self::assertLessThanOrEqual(3_600, $ttl);

        $this->compteurs->enregistrerEchec(self::CLIENT);
        self::assertLessThanOrEqual($ttl, $this->redis->ttl('fraud:fails:'.self::CLIENT), 'la fenêtre démarre au premier échec et n\'est pas prolongée');
    }

    public function testUnPaiementAccepteRemetLesEchecsAZero(): void
    {
        $this->compteurs->enregistrerEchec(self::CLIENT);
        $this->compteurs->enregistrerEchec(self::CLIENT);

        $this->compteurs->reinitialiserEchecs(self::CLIENT);

        self::assertSame(2, $this->compteurs->enregistrerEchec(self::CLIENT), 'on repart de zéro : 2 essais restants, pas un blocage');
        self::assertNull($this->compteurs->secondesDeBlocage(self::CLIENT));
    }

    public function testLeBlocageNeConcerneQueLeClientEnCause(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->compteurs->enregistrerEchec(self::CLIENT);
        }

        self::assertNotNull($this->compteurs->secondesDeBlocage(self::CLIENT));
        self::assertNull($this->compteurs->secondesDeBlocage(self::AUTRE));
        self::assertSame(2, $this->compteurs->enregistrerEchec(self::AUTRE));
    }

    public function testDesEchecsSimultanesPosentLeBlocageUneSeuleFoisSansPerdreDeComptage(): void
    {
        // Le script Lua est atomique : 5 échecs d'affilée donnent 2, 1, 0 puis un NOUVEAU cycle (2, 1), jamais un compteur faussé
        $restantes = [];
        for ($i = 0; $i < 5; ++$i) {
            $restantes[] = $this->compteurs->enregistrerEchec(self::CLIENT);
        }

        self::assertSame([2, 1, 0, 2, 1], $restantes);
    }

    public function testUnBlocageSansExpirationEstTraiteCommeUnBlocageComplet(): void
    {
        $this->redis->set('fraud:block:'.self::CLIENT, '1');   // clé posée à la main, sans TTL : ne devrait pas exister

        self::assertSame(3_600, $this->compteurs->secondesDeBlocage(self::CLIENT), 'Fail Securely : bloqué, pas libre');
    }

    // ---- Entrées hostiles -----------------------------------------------------------------------------------------

    public function testAucuneCleRedisNEstConstruiteAPartirDUneValeurNonValidee(): void
    {
        foreach (["*", "x\r\nDEL *", self::CLIENT.':evil', '../fraud', ''] as $forge) {
            foreach ([
                fn () => $this->compteurs->secondesDeBlocage($forge),
                fn () => $this->compteurs->clesAcheteesSur24h($forge),
                fn () => $this->compteurs->enregistrerEchec($forge),
                fn () => $this->compteurs->reinitialiserEchecs($forge),
                fn () => $this->compteurs->enregistrerClesAchetees($forge, self::COMMANDE, 1),
            ] as $appel) {
                try {
                    $appel();
                    self::fail('Un identifiant forgé aurait dû être refusé : '.$forge);
                } catch (\InvalidArgumentException) {
                }
            }
        }
        foreach (['*', 'pas-un-uuid', ''] as $commandeForgee) {
            try {
                $this->compteurs->enregistrerClesAchetees(self::CLIENT, $commandeForgee, 1);
                self::fail('Une commande forgée aurait dû être refusée.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->compteurs->enregistrerClesAchetees(self::CLIENT, self::COMMANDE, 0);
    }
}

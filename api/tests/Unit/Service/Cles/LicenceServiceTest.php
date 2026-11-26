<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Cles;

use App\Exception\CleIntrouvableException;
use App\Exception\CleNonRevelableException;
use App\Security\Voter\CleLicenceVoter;
use App\Service\Audit\JournalAudit;
use App\Service\Cles\CleIllisibleException;
use App\Service\Cles\CleNormalisee;
use App\Service\Cles\CleRevelable;
use App\Service\Cles\LicenceService;
use App\Tests\Support\Cles\ClesLicenceEnMemoire;
use App\Tests\Support\Cles\JournalAuditEnMemoire;
use App\Tests\Support\Cles\SecretsFactices;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** US-34 : seul point de déchiffrement ; propriétaire, Voter, journal, première révélation datée. */
final class LicenceServiceTest extends TestCase
{
    private const OFFRE = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const CLE_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const ACHETEUR = '11111111-2222-4333-8444-555555555555';
    private const AUTRE_CLIENT = '66666666-7777-4888-8999-000000000000';

    private ClesLicenceEnMemoire $cles;
    private JournalAuditEnMemoire $journal;
    /** @var list<array{string, mixed}> */
    private array $questionsAuVoter = [];
    private bool $voterAccorde = true;

    protected function setUp(): void
    {
        $this->cles = new ClesLicenceEnMemoire();
        $this->journal = new JournalAuditEnMemoire();
        $cle = CleNormalisee::depuis('ABCDE-12345-FGHIJ');
        self::assertNotNull($cle);
        $this->cles->revelables[self::CLE_ID] = new CleRevelable(
            self::CLE_ID, self::OFFRE, SecretsFactices::chiffreur()->chiffrer($cle, self::OFFRE), self::ACHETEUR, 'PAYEE', 'VENDUE', null,
        );
    }

    public function testLAcheteurObtientSaCleEtLaRevelationEstJournalisee(): void
    {
        $revelee = $this->service()->reveler(self::CLE_ID, self::ACHETEUR, '203.0.113.9');

        self::assertSame('ABCDE-12345-FGHIJ', $revelee->cle);
        self::assertSame(self::CLE_ID, $revelee->id);
        self::assertSame([[
            'action' => JournalAudit::REVELATION_CLE, 'ressource' => 'cle_licence', 'ressourceId' => self::CLE_ID,
            'utilisateurId' => self::ACHETEUR, 'ip' => '203.0.113.9',
        ]], $this->journal->entrees);
        self::assertSame(CleLicenceVoter::REVELER, $this->questionsAuVoter[0][0]);
        self::assertArrayHasKey(self::CLE_ID, $this->cles->revelees, 'première révélation datée (RG-20)');
    }

    public function testChaqueAffichageEstJournaliseLaDateResteLaPremiere(): void
    {
        $premiere = $this->service()->reveler(self::CLE_ID, self::ACHETEUR, null);
        $seconde = $this->service()->reveler(self::CLE_ID, self::ACHETEUR, null);

        self::assertCount(2, $this->journal->entrees);
        self::assertEquals($premiere->reveleeLe, $seconde->reveleeLe);
    }

    public function testLaCleDUnAutreClientEstIntrouvable(): void
    {
        try {
            $this->service()->reveler(self::CLE_ID, self::AUTRE_CLIENT, null);
            self::fail('exception attendue');
        } catch (CleIntrouvableException $e) {
            self::assertSame(404, $e->codeHttp());
        }
        self::assertSame([], $this->journal->entrees);
        self::assertSame([], $this->questionsAuVoter, 'la clé d\'autrui n\'est même pas présentée au Voter');
    }

    public function testRefusDuVoterAucunDechiffrementNiJournal(): void
    {
        $this->voterAccorde = false;

        try {
            $this->service()->reveler(self::CLE_ID, self::ACHETEUR, null);
            self::fail('exception attendue');
        } catch (CleNonRevelableException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertSame([], $this->journal->entrees);
        self::assertSame([], $this->cles->revelees);
    }

    public function testChiffreAltereAucuneRevelationEnregistree(): void
    {
        $origine = $this->cles->revelables[self::CLE_ID];
        $chiffre = $origine->chiffre;
        $chiffre[-1] = \chr(\ord($chiffre[-1]) ^ 0x01);
        $this->cles->revelables[self::CLE_ID] = new CleRevelable(self::CLE_ID, self::OFFRE, $chiffre, self::ACHETEUR, 'PAYEE', 'VENDUE', null);

        try {
            $this->service()->reveler(self::CLE_ID, self::ACHETEUR, null);
            self::fail('exception attendue');
        } catch (CleIllisibleException) {
        }
        self::assertSame([], $this->journal->entrees);
        self::assertSame([], $this->cles->revelees, 'une clé illisible n\'est pas datée comme révélée');
    }

    private function service(): LicenceService
    {
        $autorisations = new class($this) implements AuthorizationCheckerInterface {
            public function __construct(private readonly LicenceServiceTest $test)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?\Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool
            {
                return $this->test->voter((string) $attribute, $subject);
            }
        };

        return new LicenceService($this->cles, SecretsFactices::chiffreur(), $autorisations, $this->journal);
    }

    /** @internal appelé par la doublure du vérificateur d'autorisations */
    public function voter(string $attribut, mixed $sujet): bool
    {
        $this->questionsAuVoter[] = [$attribut, $sujet];

        return $this->voterAccorde;
    }
}

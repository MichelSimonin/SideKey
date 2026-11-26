<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\A2f;

use App\Exception\A2fEtatIncompatibleException;
use App\Exception\CodeA2fInvalideException;
use App\Exception\TropDeTentativesException;
use App\Infrastructure\Crypto\ChiffreurSecretTotp;
use App\Infrastructure\Totp\TotpOtphp;
use App\Service\A2f\A2fService;
use App\Service\A2f\CodesSecours;
use App\Service\Audit\JournalAudit;
use App\Service\Auth\SessionService;
use App\Tests\Support\A2f\ComptesA2fEnMemoire;
use App\Tests\Support\A2f\UsageUniqueEnMemoire;
use App\Tests\Support\Auth\ComptesEnMemoire;
use App\Tests\Support\Auth\EmetteurFactice;
use App\Tests\Support\Auth\FabriqueCompte;
use App\Tests\Support\Auth\LimiteurEnMemoire;
use App\Tests\Support\Auth\RevocationEnMemoire;
use App\Tests\Support\Auth\StockRefreshEnMemoire;
use App\Tests\Support\Cles\JournalAuditEnMemoire;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/** Enrôlement, vérification, codes de secours, limitation et usage unique : service A2F avec dépôt et Redis factices, TOTP réel. */
final class A2fServiceTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const JTI = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private MockClock $horloge;
    private ComptesA2fEnMemoire $donnees;
    private UsageUniqueEnMemoire $usages;
    private LimiteurEnMemoire $limiteur;
    private RevocationEnMemoire $revocation;
    private StockRefreshEnMemoire $refresh;
    private JournalAuditEnMemoire $audit;
    private ChiffreurSecretTotp $chiffreur;
    private A2fService $service;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->horloge = new MockClock('2026-11-26 10:00:00');
        $this->donnees = new ComptesA2fEnMemoire();
        $this->usages = new UsageUniqueEnMemoire();
        $this->limiteur = new LimiteurEnMemoire();
        $this->revocation = new RevocationEnMemoire();
        $this->refresh = new StockRefreshEnMemoire();
        $this->audit = new JournalAuditEnMemoire();
        $this->chiffreur = new ChiffreurSecretTotp(base64_encode(str_repeat('k', 32)));
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
        $comptes = new ComptesEnMemoire();
        $comptes->ajouter(FabriqueCompte::creer(1, ['GESTIONNAIRE']));
        $sessions = new SessionService($this->refresh, $this->revocation, new EmetteurFactice(), $comptes, $this->horloge, $logger);
        $this->service = new A2fService(
            $this->donnees, new TotpOtphp($this->horloge), $this->chiffreur, $this->usages, $this->limiteur,
            FabriqueCompte::hashers(), $sessions, $this->revocation, $this->audit, $this->horloge, $logger,
        );
    }

    // --- Enrôlement ----------------------------------------------------------------------------

    public function testLEnrolementStockeLeSecretChiffreEtRenvoieLUri(): void
    {
        $compte = $this->compte();

        $enrolement = $this->service->demarrerEnrolement($compte);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $enrolement->secret);
        self::assertStringStartsWith('otpauth://totp/SideKey%3Acompte1%40sidekey.test', $enrolement->uri);
        $stocke = $this->donnees->secretChiffre($compte->id);
        self::assertNotNull($stocke);
        self::assertStringNotContainsString($enrolement->secret, $stocke, 'jamais en clair au repos');
        self::assertSame($enrolement->secret, $this->chiffreur->dechiffrer($stocke, $compte->id));
        self::assertFalse($this->donnees->actifs[$compte->id] ?? false, 'pas active tant qu\'un code n\'a pas été confirmé');
    }

    public function testRecommencerRemplaceLeSecretEnAttente(): void
    {
        $premier = $this->service->demarrerEnrolement($this->compte());
        $second = $this->service->demarrerEnrolement($this->compte());

        self::assertNotSame($premier->secret, $second->secret);
        self::assertSame($second->secret, $this->chiffreur->dechiffrer((string) $this->donnees->secretChiffre($this->compte()->id), $this->compte()->id));
    }

    public function testOnNEnroleJamaisUnCompteDejaProtege(): void
    {
        $actif = FabriqueCompte::creer(1, ['GESTIONNAIRE'], a2f: true);
        $this->donnees->secrets[$actif->id] = 'secret-actif';
        $this->donnees->actifs[$actif->id] = true;

        try {
            $this->service->demarrerEnrolement($actif);
            self::fail('409 attendu');
        } catch (A2fEtatIncompatibleException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertSame('secret-actif', $this->donnees->secrets[$actif->id], 'le secret actif n\'est pas écrasé');
    }

    public function testConfirmationActiveLA2fGenereDixCodesHachesEtOuvreLaSession(): void
    {
        $compte = $this->compte();
        $secret = $this->service->demarrerEnrolement($compte)->secret;

        $activation = $this->service->confirmerEnrolement($compte, $this->code($secret), self::IP, self::JTI, $this->horloge->now()->getTimestamp() + 300);

        self::assertTrue($this->donnees->actifs[$compte->id]);
        self::assertCount(10, $activation->codesSecours);
        self::assertCount(10, $this->donnees->codes[$compte->id]);
        foreach ($this->donnees->codes[$compte->id] as $stocke) {
            self::assertStringStartsWith('$argon2id$', $stocke['hache']);
            foreach ($activation->codesSecours as $clair) {
                self::assertStringNotContainsString((string) CodesSecours::normaliser($clair), $stocke['hache'], 'codes de secours stockés hachés');
            }
        }
        $acces = EmetteurFactice::lire($activation->session->jetonAcces);
        self::assertSame('access', $acces['type']);
        self::assertTrue($acces['a2f'], 'la session porte la revendication a2f');
        self::assertSame(
            [['action' => JournalAudit::A2F_ACTIVATION, 'ressource' => 'utilisateur', 'ressourceId' => $compte->id, 'utilisateurId' => $compte->id, 'ip' => self::IP]],
            $this->audit->entrees,
        );
    }

    public function testLeJetonIntermediaireEstRevoqueApresLEnrolement(): void
    {
        $compte = $this->compte();
        $secret = $this->service->demarrerEnrolement($compte)->secret;

        $this->service->confirmerEnrolement($compte, $this->code($secret), self::IP, self::JTI, $this->horloge->now()->getTimestamp() + 240);

        self::assertSame([self::JTI => 240], $this->revocation->jetons, 'usage unique, pour la durée restante du jeton');
    }

    public function testConfirmationAvecUnMauvaisCode(): void
    {
        $compte = $this->compte();
        $this->service->demarrerEnrolement($compte);

        try {
            $this->service->confirmerEnrolement($compte, '000000', self::IP, self::JTI, 0);
            self::fail('401 attendu');
        } catch (CodeA2fInvalideException $e) {
            self::assertSame(401, $e->codeHttp());
            self::assertSame(['raison' => 'code_invalide'], $e->details());
        }

        self::assertFalse($this->donnees->actifs[$compte->id] ?? false);
        self::assertSame(JournalAudit::A2F_ECHEC, $this->audit->entrees[0]['action']);
        self::assertSame([], $this->revocation->jetons, 'le jeton intermédiaire reste utilisable pour un nouvel essai');
    }

    public function testConfirmationSansEnrolementCommence(): void
    {
        $this->expectException(A2fEtatIncompatibleException::class);
        $this->service->confirmerEnrolement($this->compte(), '123456', self::IP, self::JTI, 0);
    }

    public function testConfirmationSurUnCompteDejaActif(): void
    {
        $actif = FabriqueCompte::creer(1, ['GESTIONNAIRE'], a2f: true);

        $this->expectException(A2fEtatIncompatibleException::class);
        $this->service->confirmerEnrolement($actif, '123456', self::IP, self::JTI, 0);
    }

    // --- Vérification --------------------------------------------------------------------------

    public function testUnCodeTotpValideOuvreLaSession(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(60);   // le code d'enrôlement est déjà passé

        $session = $this->service->valider($compte, $this->code($secret), self::IP, self::JTI, $this->horloge->now()->getTimestamp() + 300);

        $acces = EmetteurFactice::lire($session->jetonAcces);
        self::assertSame(['access', true], [$acces['type'], $acces['a2f']]);
        self::assertSame(1, \count($this->refresh->actifs), 'un refresh est émis seulement maintenant');
        self::assertArrayHasKey(self::JTI, $this->revocation->jetons, 'le jeton intermédiaire ne sert qu\'une fois');
    }

    public function testUnMauvaisCodeEstRefuseEtJournalise(): void
    {
        [$compte] = $this->compteActif();

        try {
            $this->service->valider($compte, '123456', self::IP, self::JTI, 0);
            self::fail('401 attendu');
        } catch (CodeA2fInvalideException) {
        }

        self::assertSame([], $this->refresh->actifs, 'aucun refresh sans second facteur');
        self::assertSame(JournalAudit::A2F_ECHEC, $this->audit->entrees[1]['action']);
        self::assertSame([], $this->revocation->jetons);
    }

    public function testUnCodeTotpNeServQuUneFois(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(60);
        $code = $this->code($secret);
        $this->service->valider($compte, $code, self::IP, self::JTI, 0);

        $this->expectException(CodeA2fInvalideException::class);
        $this->service->valider($compte, $code, self::IP, self::JTI, 0);   // rejeu dans la fenêtre de validité
    }

    public function testLeCodeDuPasSuivantEstAccepte(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(60);
        $this->service->valider($compte, $this->code($secret), self::IP, self::JTI, 0);

        $this->horloge->sleep(30);

        $this->service->valider($compte, $this->code($secret), self::IP, self::JTI, 0);
        $this->addToAssertionCount(1);
    }

    public function testLeCodeDuPasPrecedentEnToleranceDHorloge(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(120);
        $ancien = $this->codeAu($secret, $this->horloge->now()->getTimestamp() - 30);

        $this->service->valider($compte, $ancien, self::IP, self::JTI, 0);
        $this->addToAssertionCount(1);
    }

    public function testUnCodeTropAncienEstRefuse(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(300);
        $trop = $this->codeAu($secret, $this->horloge->now()->getTimestamp() - 120);

        $this->expectException(CodeA2fInvalideException::class);
        $this->service->valider($compte, $trop, self::IP, self::JTI, 0);
    }

    public function testUnCodeDeSecoursOuvreLaSessionUneSeuleFois(): void
    {
        [$compte, , $secours] = $this->compteActif();

        $this->service->valider($compte, $secours[0], self::IP, self::JTI, 0);
        self::assertContains(JournalAudit::A2F_CODE_SECOURS, array_column($this->audit->entrees, 'action'));

        $this->expectException(CodeA2fInvalideException::class);
        $this->service->valider($compte, $secours[0], self::IP, self::JTI, 0);
    }

    public function testLesCodesDeSecoursAcceptentCasseTiretEtEspace(): void
    {
        [$compte, , $secours] = $this->compteActif();

        $this->service->valider($compte, strtolower($secours[0]), self::IP, self::JTI, 0);
        $this->service->valider($compte, str_replace('-', ' ', $secours[1]), self::IP, self::JTI, 0);
        $this->service->valider($compte, str_replace('-', '', $secours[2]), self::IP, self::JTI, 0);
        $this->addToAssertionCount(1);
    }

    public function testChaqueCodeDeSecoursEstIndependant(): void
    {
        [$compte, , $secours] = $this->compteActif();
        foreach ($secours as $code) {
            $this->limiteur->compteurs = [];   // on teste les dix codes, pas la limitation
            $this->service->valider($compte, $code, self::IP, self::JTI, 0);
        }

        self::assertSame([], $this->donnees->codesSecoursDisponibles($compte->id), 'dix codes, dix usages');
    }

    public function testUnCodeDeSecoursInventeEstRefuse(): void
    {
        [$compte] = $this->compteActif();

        $this->expectException(CodeA2fInvalideException::class);
        $this->service->valider($compte, 'AAAAA-BBBBB', self::IP, self::JTI, 0);
    }

    public function testLesCodesDeSecoursDUnAutreCompteNeServentPas(): void
    {
        [$compte] = $this->compteActif();
        $autre = FabriqueCompte::creer(2, ['GESTIONNAIRE'], a2f: true);
        $this->donnees->secrets[$autre->id] = 'x';
        $this->donnees->actifs[$autre->id] = true;
        $this->donnees->codes[$autre->id] = [['id' => 'autre-1', 'hache' => FabriqueCompte::hash('ZZZZZZZZZZ'), 'utilise' => false]];

        $this->expectException(CodeA2fInvalideException::class);
        $this->service->valider($compte, 'ZZZZZ-ZZZZZ', self::IP, self::JTI, 0);
    }

    public function testUnFormatInconnuEstRefuse(): void
    {
        [$compte] = $this->compteActif();

        foreach (['', 'abc', '12345', '1234567', 'ABCDE-FGHJ', "123456\0"] as $saisie) {
            $this->limiteur->compteurs = [];
            try {
                $this->service->valider($compte, $saisie, self::IP, self::JTI, 0);
                self::fail("« $saisie » accepté");
            } catch (CodeA2fInvalideException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testVerifierUnCompteSansA2f(): void
    {
        $this->expectException(A2fEtatIncompatibleException::class);
        $this->service->valider($this->compte(), '123456', self::IP, self::JTI, 0);
    }

    public function testUnSecretIllisibleRefuseLeCodeEtNeFuitePas(): void
    {
        [$compte] = $this->compteActif();
        $this->donnees->secrets[$compte->id] = "\xAA\xBB\xCC\xDD\xEE\xFF\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99";   // donnée factice du jeu d'essai

        try {
            $this->service->valider($compte, '123456', self::IP, self::JTI, 0);
            self::fail('401 attendu : jamais de repli permissif');
        } catch (CodeA2fInvalideException) {
        }

        self::assertCount(1, $this->logs);
        self::assertSame($compte->id, $this->logs[0]['context']['utilisateurId']);
        self::assertSame([], $this->refresh->actifs);
    }

    public function testUnSecretIllisibleLaisseLesCodesDeSecoursUtilisables(): void
    {
        [$compte, , $secours] = $this->compteActif();
        $this->donnees->secrets[$compte->id] = "\xAA\xBB";

        $session = $this->service->valider($compte, $secours[0], self::IP, self::JTI, 0);

        self::assertNotSame('', $session->jetonAcces);
    }

    // --- Tentatives limitées -------------------------------------------------------------------

    public function testCinqTentativesParCompteSixiemeBloquee(): void
    {
        [$compte, $secret] = $this->compteActif();
        $this->horloge->sleep(60);
        for ($i = 0; $i < A2fService::TENTATIVES_MAX; ++$i) {
            try {
                $this->service->valider($compte, '000000', self::IP, self::JTI, 0);
            } catch (CodeA2fInvalideException) {
            }
        }

        try {
            // Bloquée AVANT toute vérification : même le bon code ne passe plus (pas de contournement par persistance).
            $this->service->valider($compte, $this->code($secret), self::IP, self::JTI, 0);
            self::fail('429 attendu');
        } catch (TropDeTentativesException $e) {
            self::assertSame(429, $e->codeHttp());
            self::assertSame(['Retry-After' => '900'], $e->entetes());
        }
        self::assertSame([], $this->refresh->actifs);
    }

    public function testLaLimiteEstParCompte(): void
    {
        [$compte] = $this->compteActif();
        $this->limiteur->compteurs['rl:a2f:'.$compte->id] = A2fService::TENTATIVES_MAX;

        $autre = FabriqueCompte::creer(2, ['GESTIONNAIRE'], a2f: true);
        $this->donnees->secrets[$autre->id] = $this->chiffreur->chiffrer('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $autre->id);
        $this->donnees->actifs[$autre->id] = true;

        try {
            $this->service->valider($autre, '000000', self::IP, self::JTI, 0);
        } catch (CodeA2fInvalideException) {
            $this->addToAssertionCount(1);   // pas de 429 pour un autre compte
        }
    }

    public function testLaConfirmationDeLEnrolementEstAussiLimitee(): void
    {
        $compte = $this->compte();
        $this->service->demarrerEnrolement($compte);
        $this->limiteur->compteurs['rl:a2f:'.$compte->id] = A2fService::TENTATIVES_MAX;

        $this->expectException(TropDeTentativesException::class);
        $this->service->confirmerEnrolement($compte, '000000', self::IP, self::JTI, 0);
    }

    // --- Aucune fuite --------------------------------------------------------------------------

    public function testNiLeJournalNiLesLogsNeContiennentDeSecretNiDeCode(): void
    {
        $compte = $this->compte();
        $secret = $this->service->demarrerEnrolement($compte)->secret;
        $activation = $this->service->confirmerEnrolement($compte, $this->code($secret), self::IP, self::JTI, 0);
        try {
            $this->service->valider(FabriqueCompte::creer(1, ['GESTIONNAIRE'], a2f: true), $activation->codesSecours[0], self::IP, 'b'.substr(self::JTI, 1), 0);
        } catch (CodeA2fInvalideException) {
        }

        $trace = json_encode([$this->audit->entrees, $this->logs], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $trace);
        foreach ($activation->codesSecours as $code) {
            self::assertStringNotContainsString($code, $trace);
            self::assertStringNotContainsString((string) CodesSecours::normaliser($code), $trace);
        }
    }

    public function testLEnrolementEtL_ActivationNeSortentJamaisDansUnVarDump(): void
    {
        $compte = $this->compte();
        $enrolement = $this->service->demarrerEnrolement($compte);
        ob_start();
        var_dump($enrolement);
        $sortie = (string) ob_get_clean();

        self::assertStringNotContainsString($enrolement->secret, $sortie);
    }

    // --- Outils --------------------------------------------------------------------------------

    private function compte(): \App\Service\Auth\Compte
    {
        return FabriqueCompte::creer(1, ['GESTIONNAIRE']);
    }

    /**
     * Compte dont l'enrôlement est terminé (A2F active).
     *
     * @return array{0: \App\Service\Auth\Compte, 1: string, 2: list<string>} compte actif, secret TOTP, codes de secours en clair
     */
    private function compteActif(): array
    {
        $compte = $this->compte();
        $secret = $this->service->demarrerEnrolement($compte)->secret;
        $activation = $this->service->confirmerEnrolement($compte, $this->code($secret), self::IP, 'c'.substr(self::JTI, 1), 0);
        $this->audit->entrees = [];
        $this->audit->entrees[] = ['action' => 'ACTIVATION', 'ressource' => 'x', 'ressourceId' => null, 'utilisateurId' => null, 'ip' => null];
        $this->refresh->actifs = [];
        $this->revocation->jetons = [];
        $this->limiteur->compteurs = [];   // l'enrôlement a déjà compté une tentative : chaque test repart de zéro

        return [FabriqueCompte::creer(1, ['GESTIONNAIRE'], a2f: true), $secret, $activation->codesSecours];
    }

    private function code(string $secret): string
    {
        return $this->codeAu($secret, $this->horloge->now()->getTimestamp());
    }

    private function codeAu(string $secret, int $instant): string
    {
        return TOTP::createFromSecret($secret, $this->horloge)->at($instant);
    }
}

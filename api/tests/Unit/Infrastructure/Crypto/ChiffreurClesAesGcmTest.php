<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Crypto;

use App\Infrastructure\Crypto\ChiffreurClesAesGcm;
use App\Service\Cles\CleIllisibleException;
use App\Service\Cles\CleNormalisee;
use App\Tests\Support\Cles\SecretsFactices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-0004 : AES-256-GCM authentifié, lié à l'offre ; HMAC-SHA256 pour l'unicité. */
final class ChiffreurClesAesGcmTest extends TestCase
{
    private const OFFRE = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const AUTRE_OFFRE = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';
    private const CLE = 'ABCDE-12345-FGHIJ-67890';

    public function testAllerRetour(): void
    {
        $chiffreur = SecretsFactices::chiffreur();

        $chiffre = $chiffreur->chiffrer(self::cle(), self::OFFRE);

        self::assertSame(self::CLE, $chiffreur->dechiffrer($chiffre, self::OFFRE));
    }

    public function testLeChiffreNeContientPasLaCleEnClair(): void
    {
        $chiffre = SecretsFactices::chiffreur()->chiffrer(self::cle(), self::OFFRE);

        self::assertStringNotContainsString(self::CLE, $chiffre);
        self::assertStringNotContainsString('ABCDE', $chiffre);
        self::assertStringNotContainsString('67890', $chiffre);
        self::assertSame(1 + 12 + 16 + \strlen(self::CLE), \strlen($chiffre), 'version + IV + étiquette + chiffré');
        self::assertSame("\x01", $chiffre[0], 'octet de version');
    }

    public function testIvAleatoireDeuxChiffresDifferentsPourLaMemeCle(): void
    {
        $chiffreur = SecretsFactices::chiffreur();

        self::assertNotSame($chiffreur->chiffrer(self::cle(), self::OFFRE), $chiffreur->chiffrer(self::cle(), self::OFFRE));
    }

    /** @return iterable<string, array{int}> */
    public static function octetsAlteres(): iterable
    {
        yield 'version' => [0];
        yield 'IV' => [5];
        yield 'étiquette GCM' => [20];
        yield 'chiffré (premier octet)' => [29];
        yield 'chiffré (dernier octet)' => [-1];
    }

    #[DataProvider('octetsAlteres')]
    public function testUnChiffreAltereEstRefuse(int $position): void
    {
        $chiffreur = SecretsFactices::chiffreur();
        $chiffre = $chiffreur->chiffrer(self::cle(), self::OFFRE);
        $position = $position < 0 ? \strlen($chiffre) + $position : $position;
        $chiffre[$position] = \chr(\ord($chiffre[$position]) ^ 0x01);

        $this->expectException(CleIllisibleException::class);
        $chiffreur->dechiffrer($chiffre, self::OFFRE);
    }

    public function testUnChiffreTronqueEstRefuse(): void
    {
        $chiffreur = SecretsFactices::chiffreur();
        $chiffre = $chiffreur->chiffrer(self::cle(), self::OFFRE);

        $this->expectException(CleIllisibleException::class);
        $chiffreur->dechiffrer(substr($chiffre, 0, 29), self::OFFRE);
    }

    public function testUnChiffreRecopieSurUneAutreOffreEstRefuse(): void
    {
        $chiffreur = SecretsFactices::chiffreur();
        $chiffre = $chiffreur->chiffrer(self::cle(), self::OFFRE);

        $this->expectException(CleIllisibleException::class);
        $chiffreur->dechiffrer($chiffre, self::AUTRE_OFFRE);
    }

    public function testUneAutreCleMaitreNeDechiffrePas(): void
    {
        $chiffre = SecretsFactices::chiffreur()->chiffrer(self::cle(), self::OFFRE);
        $autre = new ChiffreurClesAesGcm(base64_encode(str_repeat('C', 32)), SecretsFactices::empreinte());

        $this->expectException(CleIllisibleException::class);
        $autre->dechiffrer($chiffre, self::OFFRE);
    }

    public function testLeMessageDErreurNeCiteNiLaCleNiLeChiffre(): void
    {
        $chiffreur = SecretsFactices::chiffreur();
        $chiffre = $chiffreur->chiffrer(self::cle(), self::OFFRE);
        try {
            $chiffreur->dechiffrer($chiffre, self::AUTRE_OFFRE);
            self::fail('exception attendue');
        } catch (CleIllisibleException $e) {
            self::assertStringNotContainsString(self::CLE, $e->getMessage());
            self::assertStringNotContainsString(bin2hex($chiffre), $e->getMessage());
        }
    }

    public function testEmpreinteDeterministeDe32Octets(): void
    {
        $chiffreur = SecretsFactices::chiffreur();

        $empreinte = $chiffreur->empreinte(self::cle());

        self::assertSame(32, \strlen($empreinte));
        self::assertSame($empreinte, $chiffreur->empreinte(self::cle()));
        self::assertSame(hash_hmac('sha256', 'ABCDE12345FGHIJ67890', str_repeat('B', 32), true), $empreinte, 'HMAC-SHA256 de la forme canonique');
    }

    public function testLaMemeCleEcriteAutrementALaMemeEmpreinte(): void
    {
        $chiffreur = SecretsFactices::chiffreur();
        $variante = CleNormalisee::depuis('  abcde12345-fghij67890 ');
        self::assertNotNull($variante);

        self::assertSame($chiffreur->empreinte(self::cle()), $chiffreur->empreinte($variante));
    }

    public function testLEmpreinteDependDuSecretHmac(): void
    {
        $autre = new ChiffreurClesAesGcm(SecretsFactices::chiffrement(), base64_encode(str_repeat('D', 32)));

        self::assertNotSame(SecretsFactices::chiffreur()->empreinte(self::cle()), $autre->empreinte(self::cle()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function secretsInvalides(): iterable
    {
        yield 'secret de chiffrement vide' => ['', SecretsFactices::empreinte()];
        yield 'secret HMAC vide' => [SecretsFactices::chiffrement(), ''];
        yield 'secret trop court (16 octets)' => [base64_encode(str_repeat('A', 16)), SecretsFactices::empreinte()];
        yield 'secret trop long (64 octets)' => [SecretsFactices::chiffrement(), base64_encode(str_repeat('B', 64))];
        yield 'pas du base64' => ['pas-du-base64-!!', SecretsFactices::empreinte()];
        yield 'deux secrets identiques' => [SecretsFactices::chiffrement(), SecretsFactices::chiffrement()];
    }

    #[DataProvider('secretsInvalides')]
    public function testUnSecretInvalideBloqueLeService(string $chiffrement, string $empreinte): void
    {
        try {
            new ChiffreurClesAesGcm($chiffrement, $empreinte);
            self::fail('exception attendue');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('LICENSE_KEY_', $e->getMessage(), 'le message nomme la variable à corriger');
            if ('' !== $chiffrement) {
                self::assertStringNotContainsString($chiffrement, $e->getMessage(), 'le secret ne figure pas dans le message');
            }
        }
    }

    public function testLesSecretsNApparaissentPasDansUnDump(): void
    {
        ob_start();
        var_dump(SecretsFactices::chiffreur());
        $dump = (string) ob_get_clean().print_r(SecretsFactices::chiffreur(), true);

        self::assertStringNotContainsString(str_repeat('A', 32), $dump);
        self::assertStringNotContainsString(str_repeat('B', 32), $dump);
    }

    public function testLesSecretsSontMasquesDansLesTracesDException(): void
    {
        try {
            new ChiffreurClesAesGcm(base64_encode('secret-trop-court'), SecretsFactices::empreinte());
            self::fail('exception attendue');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(base64_encode('secret-trop-court'), $e->getTraceAsString());
        }
    }

    private static function cle(): CleNormalisee
    {
        $cle = CleNormalisee::depuis(self::CLE);
        self::assertNotNull($cle);

        return $cle;
    }
}

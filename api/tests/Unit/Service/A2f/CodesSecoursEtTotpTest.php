<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\A2f;

use App\Infrastructure\Totp\TotpOtphp;
use App\Service\A2f\CodesSecours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Codes de secours (format, unicité, normalisation) et TOTP (vecteurs de la RFC 6238, tolérance, réutilisation). */
final class CodesSecoursEtTotpTest extends TestCase
{
    /** Secret de la RFC 6238 (« 12345678901234567890 » en ASCII) encodé en base32. */
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    // --- Codes de secours ----------------------------------------------------------------------

    public function testOnGenereDixCodesDistinctsAuBonFormat(): void
    {
        $codes = CodesSecours::generer();

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $code);
        }
    }

    public function testDeuxLotsSontDifferents(): void
    {
        self::assertNotSame(CodesSecours::generer(), CodesSecours::generer(), '50 bits tirés d\'un générateur cryptographique');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function saisies(): iterable
    {
        yield 'avec tiret' => ['ABCDE-FGHJK', 'ABCDEFGHJK'];
        yield 'minuscules' => ['abcde-fghjk', 'ABCDEFGHJK'];
        yield 'sans tiret' => ['ABCDEFGHJK', 'ABCDEFGHJK'];
        yield 'avec espace' => ['ABCDE FGHJK', 'ABCDEFGHJK'];
        yield 'trop court' => ['ABCDE-FGHJ', null];
        yield 'trop long' => ['ABCDE-FGHJKL', null];
        yield 'hors alphabet (0 et 1)' => ['ABCD0-FGHJ1', null];
        yield 'caractère spécial' => ['ABCDE-FGHJ!', null];
        yield 'code TOTP' => ['123456', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('saisies')]
    public function testNormalisationDeLaSaisie(string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, CodesSecours::normaliser($saisie));
    }

    public function testLesCodesGeneresSeNormalisentToujours(): void
    {
        foreach (CodesSecours::generer() as $code) {
            self::assertNotNull(CodesSecours::normaliser($code));
        }
    }

    // --- TOTP : vecteurs de la RFC 6238 (annexe B, SHA-1, 6 derniers chiffres) --------------------

    /** @return iterable<string, array{int, string}> */
    public static function vecteursRfc(): iterable
    {
        yield 'T = 59' => [59, '287082'];
        yield 'T = 1111111109' => [1_111_111_109, '081804'];
        yield 'T = 1111111111' => [1_111_111_111, '050471'];
        yield 'T = 1234567890' => [1_234_567_890, '005924'];
        yield 'T = 2000000000' => [2_000_000_000, '279037'];
        yield 'T = 20000000000' => [20_000_000_000, '353130'];
    }

    #[DataProvider('vecteursRfc')]
    public function testVecteursDeLaRfc6238(int $instant, string $code): void
    {
        $totp = $this->totp($instant);

        self::assertSame(intdiv($instant, 30), $totp->verifier(self::SECRET_RFC, $code), 'le pas renvoyé est celui du code');
    }

    public function testToleranceDUnPasDeChaqueCote(): void
    {
        $code = '287082';   // pas 1 (T = 30 à 59)

        self::assertSame(1, $this->totp(59)->verifier(self::SECRET_RFC, $code), 'pas courant');
        self::assertSame(1, $this->totp(59 + 30)->verifier(self::SECRET_RFC, $code), 'un pas plus tard : accepté');
        self::assertSame(1, $this->totp(29)->verifier(self::SECRET_RFC, $code), 'horloge du téléphone en avance d\'un pas : accepté');
        self::assertNull($this->totp(59 + 60)->verifier(self::SECRET_RFC, $code), 'deux pas plus tard : refusé');
        self::assertNull($this->totp(59 + 120)->verifier(self::SECRET_RFC, $code));
    }

    /** @return iterable<string, array{string}> */
    public static function codesInvalides(): iterable
    {
        yield 'faux' => ['000000'];
        yield 'trop court' => ['28708'];
        yield 'trop long' => ['2870820'];
        yield 'lettres' => ['28708a'];
        yield 'vide' => [''];
        yield 'avec signe' => ['+287082'];
        yield 'injection' => ["287082\n"];
        yield 'espaces seuls' => ['      '];
    }

    #[DataProvider('codesInvalides')]
    public function testCodesInvalidesRefuses(string $code): void
    {
        self::assertNull($this->totp(59)->verifier(self::SECRET_RFC, $code));
    }

    public function testLesEspacesDeLaSaisieSontTolerees(): void
    {
        self::assertSame(1, $this->totp(59)->verifier(self::SECRET_RFC, '287 082'));
    }

    public function testUnAutreSecretDonneAUnAutreCode(): void
    {
        $autre = $this->totp(59)->nouveauSecret();

        self::assertNull($this->totp(59)->verifier($autre, '287082'));
    }

    public function testNouveauSecret(): void
    {
        $totp = $this->totp(0);
        $secret = $totp->nouveauSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret, '160 bits en base32');
        self::assertNotSame($secret, $totp->nouveauSecret());
    }

    public function testUriDeProvisionnement(): void
    {
        $uri = $this->totp(0)->uriProvisionnement(self::SECRET_RFC, 'lea@example.test');

        self::assertStringStartsWith('otpauth://totp/SideKey%3Alea%40example.test?', $uri);
        self::assertStringContainsString('secret='.self::SECRET_RFC, $uri);
        self::assertStringContainsString('issuer=SideKey', $uri);
    }

    public function testUnDeuxPointsDansLeLibelleNeCasseRien(): void
    {
        $uri = $this->totp(0)->uriProvisionnement(self::SECRET_RFC, 'a:b@example.test');

        self::assertStringContainsString('a_b%40example.test', $uri);
    }

    private function totp(int $instant): TotpOtphp
    {
        return new TotpOtphp(new MockClock((new \DateTimeImmutable('@'.$instant))));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Crypto;

use App\Infrastructure\Crypto\ChiffreurSecretTotp;
use App\Service\A2f\SecretTotpIllisibleException;
use PHPUnit\Framework\TestCase;

/** Secret TOTP au repos : AES-256-GCM, IV aléatoire, chiffré lié au compte, altération détectée. */
final class ChiffreurSecretTotpTest extends TestCase
{
    private const COMPTE = '11111111-2222-4333-8444-555555555555';
    private const AUTRE_COMPTE = '99999999-2222-4333-8444-555555555555';
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private ChiffreurSecretTotp $chiffreur;

    protected function setUp(): void
    {
        $this->chiffreur = new ChiffreurSecretTotp(base64_encode(str_repeat('k', 32)));
    }

    public function testAllerRetour(): void
    {
        self::assertSame(self::SECRET, $this->chiffreur->dechiffrer($this->chiffreur->chiffrer(self::SECRET, self::COMPTE), self::COMPTE));
    }

    public function testLeChiffreNeContientPasLeSecretEnClair(): void
    {
        $chiffre = $this->chiffreur->chiffrer(self::SECRET, self::COMPTE);

        self::assertStringNotContainsString(self::SECRET, $chiffre);
        self::assertStringNotContainsString(self::SECRET, bin2hex($chiffre));
        self::assertSame("\x01", $chiffre[0], 'octet de version');
        self::assertSame(1 + 12 + 16 + \strlen(self::SECRET), \strlen($chiffre), 'version, IV, étiquette GCM, chiffré');
    }

    public function testChaqueChiffrementUtiliseUnIvDifferent(): void
    {
        self::assertNotSame($this->chiffreur->chiffrer(self::SECRET, self::COMPTE), $this->chiffreur->chiffrer(self::SECRET, self::COMPTE));
    }

    public function testUnChiffreRecopieSurUnAutreCompteEstIllisible(): void
    {
        $chiffre = $this->chiffreur->chiffrer(self::SECRET, self::COMPTE);

        $this->expectException(SecretTotpIllisibleException::class);
        $this->chiffreur->dechiffrer($chiffre, self::AUTRE_COMPTE);
    }

    public function testToutOctetAltereEstDetecte(): void
    {
        $chiffre = $this->chiffreur->chiffrer(self::SECRET, self::COMPTE);

        foreach ([5, 15, 30, \strlen($chiffre) - 1] as $position) {
            $altere = $chiffre;
            $altere[$position] = \chr(\ord($altere[$position]) ^ 0x01);
            try {
                $this->chiffreur->dechiffrer($altere, self::COMPTE);
                self::fail("Altération de l'octet $position non détectée.");
            } catch (SecretTotpIllisibleException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testMauvaiseCleMaitre(): void
    {
        $chiffre = $this->chiffreur->chiffrer(self::SECRET, self::COMPTE);

        $this->expectException(SecretTotpIllisibleException::class);
        (new ChiffreurSecretTotp(base64_encode(str_repeat('z', 32))))->dechiffrer($chiffre, self::COMPTE);
    }

    public function testLaDonneeFactriceDuJeuDEssaiEstIllisibleSansEtreUneErreurTechnique(): void
    {
        // Le jeu d'essai (T-01) stocke des octets factices dans totp_secret_chiffre.
        $this->expectException(SecretTotpIllisibleException::class);
        $this->chiffreur->dechiffrer("\xAA\xBB\xCC\xDD\xEE\xFF\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99", self::COMPTE);
    }

    public function testFormatsInconnus(): void
    {
        foreach (['', "\x01", "\x02".str_repeat('a', 40), str_repeat("\x00", 29)] as $valeur) {
            try {
                $this->chiffreur->dechiffrer($valeur, self::COMPTE);
                self::fail('Format inconnu accepté.');
            } catch (SecretTotpIllisibleException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testLaCleDoitFaire32Octets(): void
    {
        foreach (['', 'pas du base64 !', base64_encode('trop court'), base64_encode(str_repeat('a', 33))] as $cle) {
            try {
                new ChiffreurSecretTotp($cle);
                self::fail('Clé invalide acceptée.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testLaCleNeSortJamaisDansUneTrace(): void
    {
        ob_start();
        var_dump($this->chiffreur);
        $sortie = (string) ob_get_clean();

        self::assertStringNotContainsString(str_repeat('k', 32), $sortie);
        self::assertStringNotContainsString(base64_encode(str_repeat('k', 32)), $sortie);
    }
}

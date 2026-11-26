<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

use App\Service\A2f\ChiffrementSecretTotp;
use App\Service\A2f\SecretTotpIllisibleException;

/**
 * AES-256-GCM (OpenSSL) du secret TOTP, colonne utilisateur.totp_secret_chiffre (CDC §9.4, ADR-0004).
 *
 * Format : version (0x01) | IV (12 octets aléatoires) | étiquette GCM (16 octets) | chiffré. Les données associées
 * (AAD) lient le chiffré au compte : recopié sur un autre compte, il devient illisible. La clé (32 octets en base64)
 * est TOTP_ENC_KEY, distincte de celles des clés de licence : compromettre l'une n'ouvre pas l'autre.
 */
final class ChiffreurSecretTotp implements ChiffrementSecretTotp
{
    private const VERSION = "\x01";
    private const ALGORITHME = 'aes-256-gcm';
    private const LONGUEUR_IV = 12;
    private const LONGUEUR_ETIQUETTE = 16;

    private readonly string $cle;

    public function __construct(#[\SensitiveParameter] string $cleBase64)
    {
        $octets = base64_decode($cleBase64, true);
        if (false === $octets || 32 !== \strlen($octets)) {
            throw new \InvalidArgumentException('TOTP_ENC_KEY doit contenir 32 octets encodés en base64 (openssl rand -base64 32).');
        }
        $this->cle = $octets;
    }

    public function chiffrer(string $secret, string $utilisateurId): string
    {
        $iv = random_bytes(self::LONGUEUR_IV);
        $etiquette = '';
        $chiffre = openssl_encrypt($secret, self::ALGORITHME, $this->cle, \OPENSSL_RAW_DATA, $iv, $etiquette, self::donneesAssociees($utilisateurId), self::LONGUEUR_ETIQUETTE);
        if (false === $chiffre || self::LONGUEUR_ETIQUETTE !== \strlen($etiquette)) {
            throw new \RuntimeException('Chiffrement AES-GCM du secret TOTP impossible.');
        }

        return self::VERSION.$iv.$etiquette.$chiffre;
    }

    public function dechiffrer(string $chiffre, string $utilisateurId): string
    {
        $entete = 1 + self::LONGUEUR_IV + self::LONGUEUR_ETIQUETTE;
        if (\strlen($chiffre) <= $entete || self::VERSION !== $chiffre[0]) {
            throw new SecretTotpIllisibleException('Secret TOTP au format inconnu.');
        }
        $clair = openssl_decrypt(
            substr($chiffre, $entete), self::ALGORITHME, $this->cle, \OPENSSL_RAW_DATA,
            substr($chiffre, 1, self::LONGUEUR_IV), substr($chiffre, 1 + self::LONGUEUR_IV, self::LONGUEUR_ETIQUETTE),
            self::donneesAssociees($utilisateurId),
        );
        if (false === $clair) {
            throw new SecretTotpIllisibleException('Secret TOTP non authentique.');
        }

        return $clair;
    }

    private static function donneesAssociees(string $utilisateurId): string
    {
        return 'sidekey/totp/v1/'.strtolower($utilisateurId);
    }

    public function __debugInfo(): array
    {
        return [];
    }
}

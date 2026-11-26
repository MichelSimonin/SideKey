<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

use App\Service\Cles\ChiffrementCles;
use App\Service\Cles\CleIllisibleException;
use App\Service\Cles\CleNormalisee;

/**
 * AES-256-GCM (OpenSSL) et HMAC-SHA256 pour les clés de licence (ADR-0004).
 *
 * Format du chiffré (colonne cle_chiffree) :
 *
 *   version (1 octet, 0x01) | IV (12 octets aléatoires) | étiquette GCM (16 octets) | chiffré
 *
 * L'octet de version prépare la rotation de la clé maître (non outillée en v1, ADR-0004) : un chiffré
 * d'une autre version est refusé au lieu d'être mal lu. Les données associées (AAD) lient le chiffré à
 * son offre : recopier le chiffré d'une clé sur une autre offre le rend illisible.
 *
 * Les deux secrets (32 octets chacun, en base64) viennent de l'environnement : LICENSE_KEY_ENC_KEY et
 * LICENSE_KEY_HMAC_KEY, distincts par environnement, jamais dans Git ni en base. Ils sont contrôlés à
 * la construction : un secret absent, trop court ou identique à l'autre bloque le service (Fail Securely).
 */
final class ChiffreurClesAesGcm implements ChiffrementCles
{
    private const VERSION = "\x01";
    private const ALGORITHME = 'aes-256-gcm';
    private const LONGUEUR_IV = 12;
    private const LONGUEUR_ETIQUETTE = 16;
    private const LONGUEUR_SECRET = 32;

    private readonly string $cleChiffrement;
    private readonly string $cleEmpreinte;

    public function __construct(
        #[\SensitiveParameter] string $cleChiffrementBase64,
        #[\SensitiveParameter] string $cleEmpreinteBase64,
    )
    {
        $this->cleChiffrement = self::secret($cleChiffrementBase64, 'LICENSE_KEY_ENC_KEY');
        $this->cleEmpreinte = self::secret($cleEmpreinteBase64, 'LICENSE_KEY_HMAC_KEY');
        if (hash_equals($this->cleChiffrement, $this->cleEmpreinte)) {
            throw new \InvalidArgumentException('LICENSE_KEY_ENC_KEY et LICENSE_KEY_HMAC_KEY doivent être deux secrets distincts.');
        }
    }

    public function chiffrer(CleNormalisee $cle, string $offreId): string
    {
        $iv = random_bytes(self::LONGUEUR_IV);
        $etiquette = '';
        $chiffre = openssl_encrypt(
            $cle->valeur, self::ALGORITHME, $this->cleChiffrement, \OPENSSL_RAW_DATA, $iv, $etiquette,
            self::donneesAssociees($offreId), self::LONGUEUR_ETIQUETTE,
        );
        if (false === $chiffre || self::LONGUEUR_ETIQUETTE !== \strlen($etiquette)) {
            throw new \RuntimeException('Chiffrement AES-GCM impossible.');
        }

        return self::VERSION.$iv.$etiquette.$chiffre;
    }

    public function dechiffrer(string $chiffre, string $offreId): string
    {
        $entete = 1 + self::LONGUEUR_IV + self::LONGUEUR_ETIQUETTE;
        if (\strlen($chiffre) <= $entete || self::VERSION !== $chiffre[0]) {
            throw new CleIllisibleException('Chiffré de clé de licence au format inconnu.');
        }
        $clair = openssl_decrypt(
            substr($chiffre, $entete), self::ALGORITHME, $this->cleChiffrement, \OPENSSL_RAW_DATA,
            substr($chiffre, 1, self::LONGUEUR_IV), substr($chiffre, 1 + self::LONGUEUR_IV, self::LONGUEUR_ETIQUETTE),
            self::donneesAssociees($offreId),
        );
        if (false === $clair) {
            // Étiquette GCM invalide : chiffré modifié, autre offre ou autre clé maître
            throw new CleIllisibleException('Chiffré de clé de licence non authentique.');
        }

        return $clair;
    }

    public function empreinte(CleNormalisee $cle): string
    {
        return hash_hmac('sha256', $cle->canonique, $this->cleEmpreinte, true);
    }

    private static function donneesAssociees(string $offreId): string
    {
        return 'sidekey/cle_licence/v1/'.strtolower($offreId);
    }

    private static function secret(#[\SensitiveParameter] string $base64, string $nom): string
    {
        $octets = base64_decode($base64, true);
        if (false === $octets || self::LONGUEUR_SECRET !== \strlen($octets)) {
            throw new \InvalidArgumentException(sprintf('%s doit contenir 32 octets encodés en base64 (openssl rand -base64 32).', $nom));
        }

        return $octets;
    }

    /** Les secrets ne sortent jamais dans un var_dump ni dans une trace. */
    public function __debugInfo(): array
    {
        return [];
    }
}

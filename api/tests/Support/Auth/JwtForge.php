<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

/**
 * Forge de JWT pour les tests de sécurité : joue le rôle de l'attaquant, indépendamment de LexikJWT.
 * Permet de fabriquer des jetons falsifiés (charge modifiée, « alg: none », confusion RS256 / HS256,
 * mauvaise clé) ou valablement signés avec des revendications hostiles (expiré, rôles gonflés).
 */
final class JwtForge
{
    /** @param array<string, mixed> $charge */
    public static function rs256(array $charge, string $clePriveePem, string $passphrase = ''): string
    {
        $segments = self::b64(self::json(['typ' => 'JWT', 'alg' => 'RS256'])).'.'.self::b64(self::json($charge));
        $cle = openssl_pkey_get_private($clePriveePem, $passphrase);
        if (false === $cle || !openssl_sign($segments, $signature, $cle, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signature RS256 impossible.');
        }

        return $segments.'.'.self::b64($signature);
    }

    /** Signé avec une paire de clés que le serveur ne connaît pas. */
    public static function rs256AvecAutreCle(array $charge): string
    {
        $paire = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $paire) {
            throw new \RuntimeException('Génération de clé impossible.');
        }
        openssl_pkey_export($paire, $pem);

        return self::rs256($charge, $pem);
    }

    /** Confusion d'algorithme : HS256 signé avec la CLÉ PUBLIQUE (connue de tous) comme secret HMAC. */
    public static function hs256(array $charge, string $secret): string
    {
        $segments = self::b64(self::json(['typ' => 'JWT', 'alg' => 'HS256'])).'.'.self::b64(self::json($charge));

        return $segments.'.'.self::b64(hash_hmac('sha256', $segments, $secret, true));
    }

    /** « alg: none » : aucune signature. */
    public static function sansSignature(array $charge, string $alg = 'none'): string
    {
        return self::b64(self::json(['typ' => 'JWT', 'alg' => $alg])).'.'.self::b64(self::json($charge)).'.';
    }

    /** Remplace la charge d'un jeton valide en gardant l'en-tête et la signature d'origine. */
    public static function chargeRemplacee(string $jwt, array $nouvelleCharge): string
    {
        [$entete, , $signature] = explode('.', $jwt);

        return $entete.'.'.self::b64(self::json($nouvelleCharge)).'.'.$signature;
    }

    /** Altère un seul caractère de la signature. */
    public static function signatureAlteree(string $jwt): string
    {
        [$entete, $charge, $signature] = explode('.', $jwt);
        $signature[10] = 'A' === $signature[10] ? 'B' : 'A';

        return $entete.'.'.$charge.'.'.$signature;
    }

    /** @return array<string, mixed> */
    public static function charge(string $jwt): array
    {
        return (array) json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/'), true), true);
    }

    public static function b64(string $donnees): string
    {
        return rtrim(strtr(base64_encode($donnees), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $donnees */
    private static function json(array $donnees): string
    {
        return json_encode($donnees, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}

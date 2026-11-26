<?php

declare(strict_types=1);

namespace App\Service\A2f;

/**
 * Codes de secours (CDC §11.4) : 10 codes à usage unique, générés en bloc à chaque activation, stockés hachés.
 *
 * Format affiché : « ABCDE-FGHJK », 10 caractères de l'alphabet base32 (A à Z, 2 à 7), soit 50 bits tirés d'un
 * générateur cryptographique (random_int). La saisie ignore la casse, les tirets et les espaces.
 */
final class CodesSecours
{
    public const NOMBRE = 10;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const LONGUEUR = 10;

    /** @return list<string> codes en clair « ABCDE-FGHJK » : à montrer une seule fois */
    public static function generer(): array
    {
        $codes = [];
        while (\count($codes) < self::NOMBRE) {
            $brut = '';
            for ($i = 0; $i < self::LONGUEUR; ++$i) {
                $brut .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $codes[$brut] = substr($brut, 0, 5).'-'.substr($brut, 5);   // clé = code brut : jamais deux codes identiques
        }

        return array_values($codes);
    }

    /** Forme normalisée d'une saisie (sans tiret ni espace, majuscules), null si ce n'est pas un code de secours. */
    public static function normaliser(string $saisie): ?string
    {
        $code = strtoupper(str_replace(['-', ' '], '', $saisie));

        return 1 === preg_match('/^['.self::ALPHABET.']{'.self::LONGUEUR.'}$/', $code) ? $code : null;
    }
}

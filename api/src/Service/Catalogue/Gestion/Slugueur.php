<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Service\Catalogue\RechercheTitre;

/** Slug d'URL d'un jeu (jeu.slug : minuscules, chiffres, tirets, 200 caractères au plus) : accents repliés, séparateurs en tirets. */
final class Slugueur
{
    public const FORMAT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const MAX = 200;

    public static function depuis(string $titre): string
    {
        $slug = RechercheTitre::normaliser($titre);                       // minuscules sans accent
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = rtrim(substr($slug, 0, self::MAX), '-');

        return '' === $slug ? 'jeu' : $slug;
    }

    public static function valide(string $slug): bool
    {
        return \strlen($slug) <= self::MAX && 1 === preg_match(self::FORMAT, $slug);
    }

    /** Variante numérotée (« titre-2 ») qui tient dans la limite de longueur. */
    public static function variante(string $slug, int $numero): string
    {
        $suffixe = '-'.$numero;

        return rtrim(substr($slug, 0, self::MAX - \strlen($suffixe)), '-').$suffixe;
    }
}

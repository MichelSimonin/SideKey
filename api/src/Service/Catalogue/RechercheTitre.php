<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/**
 * Recherche par titre insensible à la casse et aux accents (US-03), sans extension PostgreSQL : le terme et le titre
 * passent par la même table de correspondance (translate côté SQL, strtr côté PHP), puis un LIKE.
 * Les caractères joker du client (%, _) et le caractère d'échappement (\) sont échappés : « % » cherche un
 * pourcentage, il ne renvoie pas tout le catalogue.
 */
final class RechercheTitre
{
    /** Lettres accentuées (majuscules comprises : lower() de PostgreSQL dépend de la locale de la base). */
    public const ACCENTUEES = 'ÀÁÂÃÄÅàáâãäåÇçÈÉÊËèéêëÌÍÎÏìíîïÑñÒÓÔÕÖòóôõöÙÚÛÜùúûüÝŸýÿ';
    public const SANS_ACCENT = 'AAAAAAaaaaaaCcEEEEeeeeIIIIiiiiNnOOOOOoooooUUUUuuuuYYyy';

    /** Terme normalisé (minuscules, sans accent) pour comparer avec le titre normalisé par la base. */
    public static function normaliser(string $texte): string
    {
        return mb_strtolower(strtr($texte, self::table()));
    }

    /** Motif LIKE « contient » : le terme normalisé, jokers échappés. À employer avec ESCAPE '\'. */
    public static function motifContient(string $terme): string
    {
        return '%'.addcslashes(self::normaliser($terme), '%_\\').'%';
    }

    /** @return array<string, string> */
    private static function table(): array
    {
        return array_combine(mb_str_split(self::ACCENTUEES), mb_str_split(self::SANS_ACCENT));
    }
}

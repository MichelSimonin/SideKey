<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/**
 * Tris autorisés (US-05). LISTE BLANCHE : le client envoie un mot-clé (la valeur de la case), jamais une colonne ni
 * un fragment SQL. La clause ORDER BY est une constante de ce fichier ; une valeur inconnue est refusée en 422 avant
 * d'atteindre la base. Chaque tri se termine par l'identifiant pour un ordre total (pagination stable).
 */
enum TriCatalogue: string
{
    case Titre = 'titre';
    case Nouveaute = 'nouveaute';
    case PrixCroissant = 'prix_asc';
    case PrixDecroissant = 'prix_desc';
    case Note = 'note';

    /** Fragment ORDER BY sur l'alias « b » de la requête du catalogue. Jamais construit à partir d'une entrée. */
    public function clauseSql(): string
    {
        return match ($this) {
            self::Titre => 'b.titre ASC, b.id ASC',
            self::Nouveaute => 'b.date_sortie DESC NULLS LAST, b.titre ASC, b.id ASC',
            self::PrixCroissant => 'b.prix_min ASC, b.titre ASC, b.id ASC',
            self::PrixDecroissant => 'b.prix_min DESC, b.titre ASC, b.id ASC',
            self::Note => 'b.note_moyenne DESC NULLS LAST, b.nombre_avis DESC, b.titre ASC, b.id ASC',
        };
    }

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}

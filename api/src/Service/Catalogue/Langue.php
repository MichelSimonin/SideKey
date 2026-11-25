<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Langues du catalogue (CDC §12.4) : les descriptions et les genres sont traduits en base (jeu_traduction, genre_traduction). */
enum Langue: string
{
    case Fr = 'fr';
    case En = 'en';

    /** Toute valeur inattendue retombe sur le français : la langue ne devient jamais une clé libre. */
    public static function depuis(string $valeur): self
    {
        return self::tryFrom(strtolower(substr($valeur, 0, 2))) ?? self::Fr;
    }

    /** Langue de repli quand la traduction demandée n'existe pas. */
    public function repli(): self
    {
        return self::Fr === $this ? self::En : self::Fr;
    }
}

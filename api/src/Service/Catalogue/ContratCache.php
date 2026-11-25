<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Contrôle de la forme d'une entrée relue dans le cache : une entrée incomplète est refusée proprement (puis relue en base). */
final class ContratCache
{
    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $cles
     */
    public static function exiger(array $donnees, array $cles): void
    {
        foreach ($cles as $cle) {
            if (!\array_key_exists($cle, $donnees)) {
                throw new \UnexpectedValueException('Entrée de cache incomplète.');
            }
        }
    }
}

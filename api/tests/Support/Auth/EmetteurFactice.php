<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Service\Auth\Compte;
use App\Service\Auth\EmetteurJetonAcces;
use App\Service\Auth\TypeJeton;

/** Doublure de l'émetteur JWT : « type.jti.famille.compte.durée.a2f », lisible dans les assertions. */
final class EmetteurFactice implements EmetteurJetonAcces
{
    public function emettre(Compte $compte, TypeJeton $type, string $jti, string $familleId, int $dureeSecondes, bool $a2fValide = false): string
    {
        return implode('.', [$type->value, $jti, $familleId, $compte->id, $dureeSecondes, $a2fValide ? 'a2f' : 'sans-a2f']);
    }

    /** @return array{type: string, jti: string, famille: string, compte: string, duree: int, a2f: bool} */
    public static function lire(string $jeton): array
    {
        [$type, $jti, $famille, $compte, $duree, $a2f] = explode('.', $jeton);

        return ['type' => $type, 'jti' => $jti, 'famille' => $famille, 'compte' => $compte, 'duree' => (int) $duree, 'a2f' => 'a2f' === $a2f];
    }
}

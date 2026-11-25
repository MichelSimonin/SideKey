<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Service\Auth\Compte;
use App\Service\Auth\EmetteurJetonAcces;
use App\Service\Auth\TypeJeton;

/** Doublure de l'émetteur JWT : « type.jti.famille.compte.durée », lisible dans les assertions. */
final class EmetteurFactice implements EmetteurJetonAcces
{
    public function emettre(Compte $compte, TypeJeton $type, string $jti, string $familleId, int $dureeSecondes): string
    {
        return implode('.', [$type->value, $jti, $familleId, $compte->id, $dureeSecondes]);
    }

    /** @return array{type: string, jti: string, famille: string, compte: string, duree: int} */
    public static function lire(string $jeton): array
    {
        [$type, $jti, $famille, $compte, $duree] = explode('.', $jeton);

        return ['type' => $type, 'jti' => $jti, 'famille' => $famille, 'compte' => $compte, 'duree' => (int) $duree];
    }
}

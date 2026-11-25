<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** Émission des JWT (RS256, Lexik). Interface côté métier : les services ne connaissent pas Lexik. */
interface EmetteurJetonAcces
{
    /**
     * @param string $jti       identifiant unique du jeton, base de la révocation
     * @param string $familleId session à laquelle le jeton appartient
     */
    public function emettre(Compte $compte, TypeJeton $type, string $jti, string $familleId, int $dureeSecondes): string;
}

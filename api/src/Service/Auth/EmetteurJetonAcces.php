<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** Émission des JWT (RS256, Lexik). Interface côté métier : les services ne connaissent pas Lexik. */
interface EmetteurJetonAcces
{
    /**
     * @param string $jti       identifiant unique du jeton, base de la révocation
     * @param string $familleId session à laquelle le jeton appartient
     * @param bool   $a2fValide revendication « a2f » : le second facteur a été vérifié pour cette session (seule elle ouvre le back-office)
     */
    public function emettre(Compte $compte, TypeJeton $type, string $jti, string $familleId, int $dureeSecondes, bool $a2fValide = false): string;
}

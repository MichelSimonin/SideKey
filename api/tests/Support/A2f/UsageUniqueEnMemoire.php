<?php

declare(strict_types=1);

namespace App\Tests\Support\A2f;

use App\Service\A2f\UsageUniqueTotp;

/** Doublure de la réservation de pas TOTP (SET NX de Redis). */
final class UsageUniqueEnMemoire implements UsageUniqueTotp
{
    /** @var array<string, true> */
    public array $pris = [];

    public function reserver(string $utilisateurId, int $pas): bool
    {
        $cle = $utilisateurId.':'.$pas;
        if (isset($this->pris[$cle])) {
            return false;
        }
        $this->pris[$cle] = true;

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\A2f;

/**
 * Un code TOTP ne sert qu'une fois : un code intercepté et rejoué dans sa fenêtre de validité (jusqu'à 90 s) est refusé.
 * Implémentation : Redis, a2f:used:{userId}:{pas} posé avec SET NX (atomique), TTL 120 s.
 */
interface UsageUniqueTotp
{
    /** @return bool true si ce pas n'avait pas encore servi pour ce compte (il est désormais réservé) */
    public function reserver(string $utilisateurId, int $pas): bool;
}

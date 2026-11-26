<?php

declare(strict_types=1);

namespace App\Tests\Support\Paiement;

use App\Service\Paiement\Idempotence;

/** Doublure du verrou Redis SET NX. */
final class IdempotenceEnMemoire implements Idempotence
{
    /** @var array<string, true> */
    public array $cles = [];
    public bool $indisponible = false;

    public function reserver(string $empreinte): bool
    {
        if ($this->indisponible) {
            throw new \RedisException('Connection refused');
        }
        if (isset($this->cles[$empreinte])) {
            return false;
        }
        $this->cles[$empreinte] = true;

        return true;
    }

    public function liberer(string $empreinte): void
    {
        unset($this->cles[$empreinte]);
    }
}

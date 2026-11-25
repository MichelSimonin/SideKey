<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Exception\TropDeTentativesException;
use App\Service\Auth\LimiteurDebit;

/** Doublure du compteur Redis : compte les tentatives par clé, sans notion de temps. */
final class LimiteurEnMemoire implements LimiteurDebit
{
    /** @var array<string, int> */
    public array $compteurs = [];

    public function enregistrerTentative(string $cle, int $max, int $fenetreSecondes): void
    {
        $this->compteurs[$cle] = ($this->compteurs[$cle] ?? 0) + 1;
        if ($this->compteurs[$cle] > $max) {
            throw new TropDeTentativesException($fenetreSecondes);
        }
    }
}

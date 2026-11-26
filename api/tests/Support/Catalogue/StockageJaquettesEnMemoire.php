<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalogue;

use App\Service\Catalogue\Gestion\StockageJaquettes;

/** Doublure du stockage de jaquettes : garde les contenus écrits pour vérifier ce qui est écrit puis supprimé. */
final class StockageJaquettesEnMemoire implements StockageJaquettes
{
    /** @var array<string, string> chemin → contenu */
    public array $fichiers = [];
    /** @var list<string> */
    public array $suppressions = [];

    public function ecrire(string $contenu): string
    {
        $chemin = 'jaquettes/'.bin2hex(random_bytes(16)).'.webp';
        $this->fichiers[$chemin] = $contenu;

        return $chemin;
    }

    public function supprimer(string $chemin): void
    {
        $this->suppressions[] = $chemin;
        unset($this->fichiers[$chemin]);
    }
}

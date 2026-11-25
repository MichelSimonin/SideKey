<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalogue;

use App\Service\Catalogue\CatalogueCache;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\ValeursFiltres;

/** Doublure du cache Redis : mêmes règles d'invalidation (fiche par slug, listes par version). */
final class CacheEnMemoire implements CatalogueCache
{
    /** @var array<string, array<string, mixed>> */
    public array $entrees = [];
    public int $version = 0;

    public function fiche(string $slug, Langue $langue): ?JeuFiche
    {
        $d = $this->entrees['jeu:'.$slug.':'.$langue->value] ?? null;

        return null === $d ? null : JeuFiche::depuis($d);
    }

    public function memoriserFiche(string $slug, Langue $langue, JeuFiche $fiche): void
    {
        $this->entrees['jeu:'.$slug.':'.$langue->value] = $fiche->enTableau();
    }

    public function liste(FiltresCatalogue $filtres, Langue $langue): ?PageJeux
    {
        $d = $this->entrees[$this->cleListe($filtres, $langue)] ?? null;

        return null === $d ? null : PageJeux::depuis($d);
    }

    public function memoriserListe(FiltresCatalogue $filtres, Langue $langue, PageJeux $page): void
    {
        $this->entrees[$this->cleListe($filtres, $langue)] = $page->enTableau();
    }

    public function valeursFiltres(Langue $langue): ?ValeursFiltres
    {
        $d = $this->entrees['filtres:v'.$this->version.':'.$langue->value] ?? null;

        return null === $d ? null : ValeursFiltres::depuis($d);
    }

    public function memoriserValeursFiltres(Langue $langue, ValeursFiltres $valeurs): void
    {
        $this->entrees['filtres:v'.$this->version.':'.$langue->value] = $valeurs->enTableau();
    }

    public function invaliderJeu(string $slug): void
    {
        foreach (Langue::cases() as $langue) {
            unset($this->entrees['jeu:'.$slug.':'.$langue->value]);
        }
    }

    public function invaliderListes(): void
    {
        ++$this->version;
    }

    private function cleListe(FiltresCatalogue $filtres, Langue $langue): string
    {
        return 'jeux:v'.$this->version.':'.$langue->value.':'.$filtres->empreinte();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalogue;

use App\Service\Catalogue\CatalogueCache;
use App\Service\Catalogue\CatalogueRecherche;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\JeuResume;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\ValeursFiltres;

/** Doublure de la lecture SQL : 25 jeux « jeu-NN », compte les appels pour prouver le cache. */
final class CatalogueEnMemoire implements CatalogueRecherche
{
    public int $appelsListe = 0;
    public int $appelsFiche = 0;
    public int $appelsFiltres = 0;

    /** @var list<string> */
    public array $slugsDemandes = [];

    public function __construct(private readonly int $nombreJeux = 25)
    {
    }

    public function rechercher(FiltresCatalogue $filtres, Langue $langue): PageJeux
    {
        ++$this->appelsListe;
        $tous = [];
        for ($i = 1; $i <= $this->nombreJeux; ++$i) {
            $tous[] = new JeuResume(\sprintf('jeu-%02d', $i), 'Jeu '.$i, 12, null, ['STEAM'], 999 + $i, true, null, 0);
        }

        return new PageJeux(\array_slice($tous, $filtres->decalage(), FiltresCatalogue::PAR_PAGE), $this->nombreJeux, $filtres->page);
    }

    public function fiche(string $slug, Langue $langue): ?JeuFiche
    {
        ++$this->appelsFiche;
        $this->slugsDemandes[] = $slug;
        if ('jeu-01' !== $slug) {
            return null;
        }

        return new JeuFiche('jeu-01', 'Jeu 1', 'Description en '.$langue->value, 12, null, '2024-01-02', 'Éditeur',
            [['slug' => 'action', 'libelle' => 'Action']],
            [['offreId' => '00000000-0000-4000-8000-000000000001', 'plateforme' => 'STEAM', 'plateformeLibelle' => 'Steam', 'prixCentimes' => 1999, 'stock' => 3]],
            4.5, 2);
    }

    public function valeursFiltres(Langue $langue): ValeursFiltres
    {
        ++$this->appelsFiltres;

        return new ValeursFiltres([['code' => 'STEAM', 'libelle' => 'Steam']], [['slug' => 'action', 'libelle' => 'Action']], 999, 5499);
    }
}

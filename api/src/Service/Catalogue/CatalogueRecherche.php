<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Lecture du catalogue en base (implémentation : Repository/CatalogueRepository). Aucune autorisation ici : le catalogue est public. */
interface CatalogueRecherche
{
    public function rechercher(FiltresCatalogue $filtres, Langue $langue): PageJeux;

    /** Fiche d'un jeu actif ; null si le slug est inconnu ou le jeu retiré du catalogue. */
    public function fiche(string $slug, Langue $langue): ?JeuFiche;

    public function valeursFiltres(Langue $langue): ValeursFiltres;
}

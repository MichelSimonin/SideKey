<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\JeuResume;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\TriCatalogue;
use App\Service\Catalogue\ValeursFiltres;

/**
 * Contrats de sortie du catalogue. Les prix sont des chaînes décimales (« 29.99 ») venues de la base : le front
 * affiche, il ne calcule pas. Aucune entité, aucun identifiant de clé de licence.
 */
final class CatalogueResponse
{
    /** @return array<string, mixed> */
    public static function liste(PageJeux $page): array
    {
        return [
            'jeux' => array_map(static fn (JeuResume $j): array => [
                'slug' => $j->slug,
                'titre' => $j->titre,
                'pegi' => $j->pegi,
                'jaquette' => $j->jaquette,
                'plateformes' => $j->plateformes,
                'prixMin' => PanierResponse::montant($j->prixMinCentimes),
                'disponible' => $j->disponible,
                'noteMoyenne' => $j->noteMoyenne,
                'nombreAvis' => $j->nombreAvis,
            ], $page->jeux),
            'total' => $page->total,
            'page' => $page->page,
            'parPage' => FiltresCatalogue::PAR_PAGE,
            'nombrePages' => $page->nombrePages(),
        ];
    }

    /** @return array<string, mixed> */
    public static function fiche(JeuFiche $f): array
    {
        return [
            'slug' => $f->slug,
            'titre' => $f->titre,
            'description' => $f->description,
            'pegi' => $f->pegi,
            'jaquette' => $f->jaquette,
            'dateSortie' => $f->dateSortie,
            'editeur' => $f->editeur,
            'genres' => $f->genres,
            'offres' => array_map(static fn (array $o): array => [
                'offreId' => $o['offreId'],
                'plateforme' => $o['plateforme'],
                'plateformeLibelle' => $o['plateformeLibelle'],
                'prix' => PanierResponse::montant($o['prixCentimes']),
                'stock' => $o['stock'],
            ], $f->offres),
            'noteMoyenne' => $f->noteMoyenne,
            'nombreAvis' => $f->nombreAvis,
        ];
    }

    /** @return array<string, mixed> */
    public static function filtres(ValeursFiltres $v): array
    {
        return [
            'plateformes' => $v->plateformes,
            'genres' => $v->genres,
            'prixMin' => PanierResponse::montant($v->prixMinCentimes),
            'prixMax' => PanierResponse::montant($v->prixMaxCentimes),
            'pegi' => FiltresCatalogue::PEGI,
            'tris' => TriCatalogue::valeurs(),
        ];
    }
}

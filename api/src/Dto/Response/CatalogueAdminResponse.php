<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Catalogue\Gestion\EditeurAdmin;
use App\Service\Catalogue\Gestion\JeuAdmin;
use App\Service\Catalogue\Gestion\OffreAdmin;
use App\Service\Catalogue\Gestion\PageJeuxAdmin;
use App\Service\Catalogue\Gestion\PlateformeAdmin;

/**
 * Représentations du back-office catalogue (F-09). Champs listés un à un (Limited View) : le chemin de la jaquette est relatif
 * (le front le préfixe de l'URL des médias), aucune clé de licence ni donnée personnelle n'apparaît, le stock est un nombre.
 */
final class CatalogueAdminResponse
{
    /** @return array{id: string, nom: string, nombreJeux: int} */
    public static function editeur(EditeurAdmin $e): array
    {
        return ['id' => $e->id, 'nom' => $e->nom, 'nombreJeux' => $e->nombreJeux];
    }

    /** @return array{id: string, code: string, libelle: string, nombreOffres: int} */
    public static function plateforme(PlateformeAdmin $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'libelle' => $p->libelle, 'nombreOffres' => $p->nombreOffres];
    }

    /** @return array<string, mixed> */
    public static function offre(OffreAdmin $o): array
    {
        return [
            'id' => $o->id,
            'plateforme' => ['id' => $o->plateformeId, 'code' => $o->plateformeCode, 'libelle' => $o->plateformeLibelle],
            'prix' => $o->prix,
            'seuilAlerte' => $o->seuilAlerte,
            'actif' => $o->actif,
            'version' => $o->version,
            'stock' => $o->stock,
        ];
    }

    /** @return array<string, mixed> */
    public static function jeu(JeuAdmin $j): array
    {
        return [
            'id' => $j->id,
            'slug' => $j->slug,
            'titre' => $j->titre,
            'dateSortie' => $j->dateSortie,
            'pegi' => $j->pegi,
            'actif' => $j->actif,
            'jaquette' => $j->jaquette,
            'editeur' => ['id' => $j->editeurId, 'nom' => $j->editeur],
            'descriptions' => $j->descriptions,
            'genres' => $j->genres,
            'offres' => array_map(self::offre(...), $j->offres),
        ];
    }

    /** @return array<string, mixed> */
    public static function pageJeux(PageJeuxAdmin $page): array
    {
        return [
            'jeux' => array_map(static fn ($j): array => [
                'id' => $j->id,
                'slug' => $j->slug,
                'titre' => $j->titre,
                'editeur' => $j->editeur,
                'pegi' => $j->pegi,
                'actif' => $j->actif,
                'jaquette' => $j->jaquette,
                'nombreOffres' => $j->nombreOffres,
            ], $page->jeux),
            'page' => $page->page,
            'nombrePages' => $page->nombrePages(),
            'total' => $page->total,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Exception\JeuIntrouvableException;
use App\Exception\PageIntrouvableException;

/**
 * Lecture publique du catalogue (US-01 à US-05) avec cache Redis en lecture directe (cache-aside) :
 * lecture du cache, sinon PostgreSQL puis mise en cache. Aucune connaissance de HTTP.
 */
final class CatalogueService
{
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const SLUG_MAX = 200;   // jeu.slug varchar(200)

    public function __construct(
        private readonly CatalogueRecherche $recherche,
        private readonly CatalogueCache $cache,
    ) {
    }

    /**
     * @throws PageIntrouvableException la page demandée dépasse la dernière page de résultats (404)
     */
    public function liste(FiltresCatalogue $filtres, Langue $langue): PageJeux
    {
        $page = $this->cache->liste($filtres, $langue);
        if (null === $page) {
            $page = $this->recherche->rechercher($filtres, $langue);
            $this->cache->memoriserListe($filtres, $langue, $page);
        }

        // Aucun résultat : la page 1 est valide (« aucun jeu »), pas les suivantes.
        if ($filtres->page > $page->nombrePages()) {
            throw new PageIntrouvableException();
        }

        return $page;
    }

    /**
     * @throws JeuIntrouvableException slug mal formé, inconnu ou jeu retiré du catalogue (404)
     */
    public function fiche(string $slug, Langue $langue): JeuFiche
    {
        // Un slug hors format ne touche ni le cache ni la base : pas de clé Redis fabriquée par le client.
        if (\strlen($slug) > self::SLUG_MAX || 1 !== preg_match(self::SLUG, $slug)) {
            throw new JeuIntrouvableException();
        }

        $fiche = $this->cache->fiche($slug, $langue);
        if (null === $fiche) {
            $fiche = $this->recherche->fiche($slug, $langue) ?? throw new JeuIntrouvableException();   // un 404 n'est jamais mis en cache
            $this->cache->memoriserFiche($slug, $langue, $fiche);
        }

        return $fiche;
    }

    public function valeursFiltres(Langue $langue): ValeursFiltres
    {
        $valeurs = $this->cache->valeursFiltres($langue);
        if (null === $valeurs) {
            $valeurs = $this->recherche->valeursFiltres($langue);
            $this->cache->memoriserValeursFiltres($langue, $valeurs);
        }

        return $valeurs;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Panier;

/** Lecture des offres et de leur stock (implémentation : Repository/OffreDisponibiliteRepository). */
interface CatalogueOffres
{
    /**
     * @param list<string> $offreIds
     *
     * @return array<string, OffreDisponibilite> indexé par identifiant d'offre ; une offre inconnue est absente
     */
    public function disponibilites(array $offreIds): array;
}

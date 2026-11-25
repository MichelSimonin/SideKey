<?php

declare(strict_types=1);

namespace App\Service\Panier;

/** Données d'une offre relues en base au moment de l'affichage ou de la modification du panier. */
final class OffreDisponibilite
{
    public function __construct(
        public readonly string $offreId,
        public readonly string $titre,
        public readonly string $plateforme,
        public readonly int $prixCentimes,
        public readonly int $stockDisponible,
        public readonly bool $active,
    ) {
    }
}

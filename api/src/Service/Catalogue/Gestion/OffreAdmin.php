<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/**
 * Une offre (jeu × plateforme, prix) vue du back-office. `version` sert au verrouillage optimiste : elle est renvoyée avec
 * l'offre et doit être rendue à la modification. Le stock est le nombre de clés DISPONIBLE (RG-02), jamais saisi.
 */
final class OffreAdmin
{
    public function __construct(
        public readonly string $id,
        public readonly string $plateformeId,
        public readonly string $plateformeCode,
        public readonly string $plateformeLibelle,
        /** Prix TTC en chaîne décimale (« 14.99 »), tel que stocké. */
        public readonly string $prix,
        public readonly int $seuilAlerte,
        public readonly bool $actif,
        public readonly int $version,
        public readonly int $stock,
    ) {
    }
}

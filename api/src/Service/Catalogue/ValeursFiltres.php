<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Valeurs proposées par les filtres de E02 : plateformes et genres réellement au catalogue, fourchette de prix, PEGI. */
final class ValeursFiltres
{
    /**
     * @param list<array{code: string, libelle: string}> $plateformes
     * @param list<array{slug: string, libelle: string}> $genres
     */
    public function __construct(
        public readonly array $plateformes,
        public readonly array $genres,
        public readonly int $prixMinCentimes,
        public readonly int $prixMaxCentimes,
    ) {
    }

    /** @return array<string, mixed> */
    public function enTableau(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $d */
    public static function depuis(array $d): self
    {
        ContratCache::exiger($d, ['plateformes','genres','prixMinCentimes','prixMaxCentimes']);
        return new self(array_values((array) $d['plateformes']), array_values((array) $d['genres']), (int) $d['prixMinCentimes'], (int) $d['prixMaxCentimes']);
    }
}

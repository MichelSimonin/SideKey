<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Un jeu dans une liste (E02, US-01) : jaquette, titre, plateformes, prix minimum, disponibilité. Prix en centimes. */
final class JeuResume
{
    /** @param list<string> $plateformes codes des plateformes proposées (selon les filtres appliqués) */
    public function __construct(
        public readonly string $slug,
        public readonly string $titre,
        public readonly int $pegi,
        public readonly ?string $jaquette,
        public readonly array $plateformes,
        public readonly int $prixMinCentimes,
        public readonly bool $disponible,
        public readonly ?float $noteMoyenne,
        public readonly int $nombreAvis,
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
        ContratCache::exiger($d, ['slug','titre','pegi','jaquette','plateformes','prixMinCentimes','disponible','noteMoyenne','nombreAvis']);
        return new self((string) $d['slug'], (string) $d['titre'], (int) $d['pegi'], null === $d['jaquette'] ? null : (string) $d['jaquette'],
            array_map('strval', (array) $d['plateformes']), (int) $d['prixMinCentimes'], (bool) $d['disponible'],
            null === $d['noteMoyenne'] ? null : (float) $d['noteMoyenne'], (int) $d['nombreAvis']);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/**
 * Fiche d'un jeu (E03, US-04) : description dans la langue demandée (repli sur l'autre langue), éditeur, PEGI,
 * genres traduits, offres actives par plateforme avec prix et stock, note moyenne. Aucun champ sensible :
 * jamais d'identifiant de clé, seulement un nombre de clés disponibles.
 */
final class JeuFiche
{
    /**
     * @param list<array{slug: string, libelle: string}>                                                              $genres
     * @param list<array{offreId: string, plateforme: string, plateformeLibelle: string, prixCentimes: int, stock: int}> $offres
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titre,
        public readonly string $description,
        public readonly int $pegi,
        public readonly ?string $jaquette,
        public readonly ?string $dateSortie,
        public readonly string $editeur,
        public readonly array $genres,
        public readonly array $offres,
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
        ContratCache::exiger($d, ['slug','titre','description','pegi','jaquette','dateSortie','editeur','genres','offres','noteMoyenne','nombreAvis']);
        return new self((string) $d['slug'], (string) $d['titre'], (string) $d['description'], (int) $d['pegi'],
            null === $d['jaquette'] ? null : (string) $d['jaquette'], null === $d['dateSortie'] ? null : (string) $d['dateSortie'],
            (string) $d['editeur'], array_values((array) $d['genres']), array_values((array) $d['offres']),
            null === $d['noteMoyenne'] ? null : (float) $d['noteMoyenne'], (int) $d['nombreAvis']);
    }
}

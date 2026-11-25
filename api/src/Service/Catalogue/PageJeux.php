<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Une page de résultats (20 jeux au plus) et le nombre total de résultats pour les critères. */
final class PageJeux
{
    /** @param list<JeuResume> $jeux */
    public function __construct(
        public readonly array $jeux,
        public readonly int $total,
        public readonly int $page,
    ) {
    }

    public function nombrePages(): int
    {
        return max(1, (int) ceil($this->total / FiltresCatalogue::PAR_PAGE));
    }

    /** @return array<string, mixed> */
    public function enTableau(): array
    {
        return ['jeux' => array_map(static fn (JeuResume $j): array => $j->enTableau(), $this->jeux), 'total' => $this->total, 'page' => $this->page];
    }

    /** @param array<string, mixed> $d */
    public static function depuis(array $d): self
    {
        ContratCache::exiger($d, ['jeux','total','page']);
        return new self(array_map(static fn (array $j): JeuResume => JeuResume::depuis($j), array_values((array) $d['jeux'])), (int) $d['total'], (int) $d['page']);
    }
}

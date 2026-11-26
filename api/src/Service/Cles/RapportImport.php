<?php

declare(strict_types=1);

namespace App\Service\Cles;

/**
 * Rapport d'un import de clés (US-52, BX-11) : compteurs et motif de chaque ligne refusée.
 * Une ligne refusée est désignée par son numéro dans le fichier, jamais par sa valeur (BX-13).
 */
final class RapportImport
{
    public const MOTIF_FORMAT = 'format';
    public const MOTIF_DOUBLON_FICHIER = 'doublon_fichier';
    public const MOTIF_DOUBLON_STOCK = 'doublon_stock';

    /** @var list<array{ligne: int, motif: string}> */
    private array $refus = [];
    private int $acceptees = 0;
    private int $lignes = 0;
    private string $statut = 'EN_COURS';

    public function __construct(
        public readonly string $id,
        public readonly string $nomFichier,
    ) {
    }

    public function compterLigne(): void
    {
        ++$this->lignes;
    }

    public function accepter(int $nombre): void
    {
        $this->acceptees += $nombre;
    }

    public function refuser(int $ligne, string $motif): void
    {
        $this->refus[] = ['ligne' => $ligne, 'motif' => $motif];
    }

    public function terminer(bool $succes): void
    {
        $this->statut = $succes ? 'TERMINE' : 'ECHEC';
        usort($this->refus, static fn (array $a, array $b): int => $a['ligne'] <=> $b['ligne']);
    }

    public function statut(): string
    {
        return $this->statut;
    }

    public function nbLignes(): int
    {
        return $this->lignes;
    }

    public function nbAcceptees(): int
    {
        return $this->acceptees;
    }

    public function nbDoublons(): int
    {
        return \count(array_filter($this->refus, static fn (array $r): bool => self::MOTIF_FORMAT !== $r['motif']));
    }

    public function nbErreurs(): int
    {
        return \count(array_filter($this->refus, static fn (array $r): bool => self::MOTIF_FORMAT === $r['motif']));
    }

    /** @return list<array{ligne: int, motif: string}> */
    public function refus(): array
    {
        return $this->refus;
    }
}

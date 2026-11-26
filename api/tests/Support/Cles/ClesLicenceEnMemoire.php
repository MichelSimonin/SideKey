<?php

declare(strict_types=1);

namespace App\Tests\Support\Cles;

use App\Service\Cles\CleAImporter;
use App\Service\Cles\CleRevelable;
use App\Service\Cles\ClesLicence;
use App\Service\Cles\RapportImport;

/** Doublure de ClesLicenceRepository : même règle d'unicité (empreinte), lots comptés, échec simulable. */
final class ClesLicenceEnMemoire implements ClesLicence
{
    /** @var array<string, string> offreId => slug du jeu (offres en vente) */
    public array $offres = [];
    /** @var array<string, CleAImporter> empreinte (hex) => clé stockée */
    public array $stock = [];
    /** @var list<int> taille de chaque lot reçu */
    public array $lots = [];
    /** @var array<string, RapportImport> */
    public array $imports = [];
    /** @var array<string, CleRevelable> */
    public array $revelables = [];
    /** @var array<string, \DateTimeImmutable> */
    public array $revelees = [];
    /** Numéro du lot (1 = premier) qui lève une erreur, pour simuler une panne en cours d'import. */
    public ?int $lotEnPanne = null;

    public function offreActive(string $offreId): ?string
    {
        return $this->offres[$offreId] ?? null;
    }

    public function ouvrirImport(string $nomFichier, string $utilisateurId): string
    {
        return 'import-'.(\count($this->imports) + 1);
    }

    public function insererLot(string $offreId, string $importId, array $cles): array
    {
        $this->lots[] = \count($cles);
        if (\count($this->lots) === $this->lotEnPanne) {
            throw new \RuntimeException('panne simulée de la base');
        }
        $inserees = [];
        foreach ($cles as $cle) {
            $cleHex = bin2hex($cle->empreinte);
            if (!isset($this->stock[$cleHex])) {
                $this->stock[$cleHex] = $cle;
                $inserees[] = $cle->ligne;
            }
        }

        return $inserees;
    }

    public function cloreImport(RapportImport $rapport): void
    {
        $this->imports[$rapport->id] = $rapport;
    }

    public function cleDuProprietaire(string $cleId, string $utilisateurId): ?CleRevelable
    {
        $cle = $this->revelables[$cleId] ?? null;

        return null !== $cle && $cle->proprietaireId === $utilisateurId ? $cle : null;
    }

    public function marquerRevelee(string $cleId): \DateTimeImmutable
    {
        return $this->revelees[$cleId] ??= new \DateTimeImmutable('2026-11-26T10:00:00+00:00');
    }
}

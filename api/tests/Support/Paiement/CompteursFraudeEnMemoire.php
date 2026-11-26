<?php

declare(strict_types=1);

namespace App\Tests\Support\Paiement;

use App\Service\Paiement\CompteursFraude;
use App\Service\Paiement\ConfigurationFraude;

/** Doublure des compteurs Redis : mêmes règles (blocage au seuil, clés par commande), et une panne simulable. */
final class CompteursFraudeEnMemoire implements CompteursFraude
{
    /** @var array<string, int> */
    public array $echecs = [];
    /** @var array<string, int> secondes de blocage restantes */
    public array $blocages = [];
    /** @var array<string, array<string, true>> */
    public array $cles = [];
    /** Redis injoignable : chaque appel lève \RedisException. */
    public bool $indisponible = false;
    /** Panne seulement pour les écritures (après un débit), les lectures restant possibles. */
    public bool $ecrituresIndisponibles = false;

    public function __construct(private readonly ConfigurationFraude $configuration)
    {
    }

    public function secondesDeBlocage(string $utilisateurId): ?int
    {
        $this->panne();

        return $this->blocages[$utilisateurId] ?? null;
    }

    public function clesAcheteesSur24h(string $utilisateurId): int
    {
        $this->panne();

        return \count($this->cles[$utilisateurId] ?? []);
    }

    public function enregistrerClesAchetees(string $utilisateurId, string $commandeId, int $nombre): void
    {
        $this->panne(true);
        for ($i = 1; $i <= $nombre; ++$i) {
            $this->cles[$utilisateurId][$commandeId.':'.$i] = true;
        }
    }

    public function enregistrerEchec(string $utilisateurId): int
    {
        $this->panne(true);
        $this->echecs[$utilisateurId] = ($this->echecs[$utilisateurId] ?? 0) + 1;
        if ($this->echecs[$utilisateurId] >= $this->configuration->echecsMax) {
            $this->blocages[$utilisateurId] = $this->configuration->blocageSecondes;
            unset($this->echecs[$utilisateurId]);

            return 0;
        }

        return $this->configuration->echecsMax - $this->echecs[$utilisateurId];
    }

    public function reinitialiserEchecs(string $utilisateurId): void
    {
        $this->panne(true);
        unset($this->echecs[$utilisateurId]);
    }

    private function panne(bool $ecriture = false): void
    {
        if ($this->indisponible || ($ecriture && $this->ecrituresIndisponibles)) {
            throw new \RedisException('Connection refused');
        }
    }
}

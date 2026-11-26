<?php

declare(strict_types=1);

namespace App\Tests\Support\A2f;

use App\Service\A2f\ComptesA2f;
use App\Service\A2f\UsageUniqueTotp;

/** Doublure du dépôt de l'A2F : mêmes garanties (secret actif jamais écrasé, activation conditionnelle, code de secours à usage unique). */
final class ComptesA2fEnMemoire implements ComptesA2f
{
    /** @var array<string, string> */
    public array $secrets = [];

    /** @var array<string, bool> */
    public array $actifs = [];

    /** @var array<string, list<array{id: string, hache: string, utilise: bool}>> */
    public array $codes = [];

    private int $sequence = 0;

    public function secretChiffre(string $utilisateurId): ?string
    {
        return $this->secrets[$utilisateurId] ?? null;
    }

    public function enregistrerSecretEnAttente(string $utilisateurId, string $secretChiffre): bool
    {
        if ($this->actifs[$utilisateurId] ?? false) {
            return false;
        }
        $this->secrets[$utilisateurId] = $secretChiffre;

        return true;
    }

    public function activer(string $utilisateurId, array $hachages): bool
    {
        if (($this->actifs[$utilisateurId] ?? false) || !isset($this->secrets[$utilisateurId])) {
            return false;
        }
        $this->actifs[$utilisateurId] = true;
        $this->codes[$utilisateurId] = array_map(fn (string $h): array => ['id' => 'code-'.++$this->sequence, 'hache' => $h, 'utilise' => false], $hachages);

        return true;
    }

    public function codesSecoursDisponibles(string $utilisateurId): array
    {
        return array_values(array_map(
            static fn (array $c): array => ['id' => $c['id'], 'hache' => $c['hache']],
            array_filter($this->codes[$utilisateurId] ?? [], static fn (array $c): bool => !$c['utilise']),
        ));
    }

    public function consommerCodeSecours(string $codeId): bool
    {
        foreach ($this->codes as &$codes) {
            foreach ($codes as &$code) {
                if ($code['id'] === $codeId) {
                    if ($code['utilise']) {
                        return false;
                    }
                    $code['utilise'] = true;

                    return true;
                }
            }
        }

        return false;
    }
}

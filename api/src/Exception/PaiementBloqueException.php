<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Trop d'échecs de paiement : le client est bloqué pour une durée (RG-14, 429 avec Retry-After). Le message dit combien de
 * temps attendre, pas la règle exacte : le seuil n'a pas à être connu d'un fraudeur.
 */
final class PaiementBloqueException extends ExceptionMetier
{
    public function __construct(private readonly int $secondes)
    {
        parent::__construct('error.paiement_bloque', ['%minutes%' => max(1, (int) ceil($secondes / 60))]);
    }

    public function codeHttp(): int
    {
        return 429;
    }

    public function entetes(): array
    {
        return ['Retry-After' => (string) max(1, $this->secondes)];
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Les compteurs anti-fraude ou l'idempotence ne sont pas joignables (Redis, 503). On REFUSE le paiement plutôt que de le
 * laisser passer sans contrôle (Fail Securely, ADR-0008) : aucun débit n'a eu lieu, le client peut réessayer.
 */
final class PaiementIndisponibleException extends ExceptionMetier
{
    public function __construct(?\Throwable $precedente = null)
    {
        parent::__construct('error.paiement_indisponible', [], $precedente);
    }

    public function codeHttp(): int
    {
        return 503;
    }

    public function entetes(): array
    {
        return ['Retry-After' => '30'];
    }
}

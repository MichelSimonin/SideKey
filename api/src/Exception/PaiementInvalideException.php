<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Données de paiement invalides (422) : numéro de carte, date d'expiration, cryptogramme ou clé d'idempotence. Une erreur
 * par champ, jamais la valeur saisie : un numéro de carte ne doit figurer ni dans une réponse, ni dans un journal.
 */
final class PaiementInvalideException extends ExceptionMetier
{
    /** @param array<string, string> $erreurs champ → clé de message traduite (domaine « errors ») */
    public function __construct(private readonly array $erreurs)
    {
        parent::__construct('error.unprocessable');
    }

    public function codeHttp(): int
    {
        return 422;
    }

    public function erreursChamps(): array
    {
        return $this->erreurs;
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

/** RG-14 (anti-fraude « Équilibré ») : 5 clés au maximum par offre et par commande (409). */
final class QuantiteMaximaleException extends ExceptionMetier
{
    public function __construct(private readonly int $maximum)
    {
        parent::__construct('error.quantite_maximale', ['%maximum%' => $maximum]);
    }

    public function codeHttp(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['maximum' => $this->maximum];
    }
}

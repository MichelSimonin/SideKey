<?php

declare(strict_types=1);

namespace App\Exception;

/** Nombre maximal d'offres différentes dans un panier atteint (409). */
final class PanierPleinException extends ExceptionMetier
{
    public function __construct(private readonly int $maximum)
    {
        parent::__construct('error.panier_plein', ['%maximum%' => $maximum]);
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

<?php

declare(strict_types=1);

namespace App\Exception;

/** Limitation de débit dépassée (429) : le client apprend quand réessayer par Retry-After. */
final class TropDeTentativesException extends ExceptionMetier
{
    public function __construct(private readonly int $reessayerDansSecondes)
    {
        parent::__construct('error.too_many_requests');
    }

    public function codeHttp(): int
    {
        return 429;
    }

    public function reessayerDans(): int
    {
        return $this->reessayerDansSecondes;
    }

    public function entetes(): array
    {
        return ['Retry-After' => (string) max(1, $this->reessayerDansSecondes)];
    }
}

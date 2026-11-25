<?php

declare(strict_types=1);

namespace App\Exception;

/** Quantité demandée supérieure au stock de clés disponibles (409). */
final class StockInsuffisantException extends ExceptionMetier
{
    public function __construct(private readonly int $disponible, ?\Throwable $precedente = null)
    {
        parent::__construct('error.stock_insuffisant', ['%disponible%' => $disponible], $precedente);
    }

    public function codeHttp(): int
    {
        return 409;
    }

    public function disponible(): int
    {
        return $this->disponible;
    }

    public function details(): array
    {
        return ['disponible' => $this->disponible];
    }
}

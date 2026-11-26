<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/** Une page de la liste des jeux du back-office (20 par page). */
final class PageJeuxAdmin
{
    public const PAR_PAGE = 20;

    /** @param list<JeuResumeAdmin> $jeux */
    public function __construct(
        public readonly array $jeux,
        public readonly int $total,
        public readonly int $page,
    ) {
    }

    public function nombrePages(): int
    {
        return max(1, (int) ceil($this->total / self::PAR_PAGE));
    }
}

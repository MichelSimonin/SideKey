<?php

declare(strict_types=1);

namespace App\Tests\Support\Cles;

use App\Service\Audit\JournalAudit;

/** Doublure du journal d'audit : garde les entrées pour les vérifier. */
final class JournalAuditEnMemoire implements JournalAudit
{
    /** @var list<array{action: string, ressource: string, ressourceId: ?string, utilisateurId: ?string, ip: ?string}> */
    public array $entrees = [];

    public function tracer(string $action, string $ressource, ?string $ressourceId, ?string $utilisateurId, ?string $adresseIp): void
    {
        $this->entrees[] = ['action' => $action, 'ressource' => $ressource, 'ressourceId' => $ressourceId, 'utilisateurId' => $utilisateurId, 'ip' => $adresseIp];
    }
}

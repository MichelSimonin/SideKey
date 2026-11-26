<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Journal d'audit (BX-16, ENF-12) : table journal_audit, en ajout seul (droits SGBD et trigger immuable).
 * On trace l'action et la ressource, jamais une valeur sensible : ni clé de licence, ni adresse IP en clair.
 * Implémentation : Repository/JournalAuditRepository.
 */
interface JournalAudit
{
    public const REVELATION_CLE = 'REVELATION_CLE';
    public const IMPORT_CLES = 'IMPORT_CLES';

    public function tracer(string $action, string $ressource, ?string $ressourceId, ?string $utilisateurId, ?string $adresseIp): void;
}

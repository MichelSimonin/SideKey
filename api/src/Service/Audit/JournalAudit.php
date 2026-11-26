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
    public const A2F_ACTIVATION = 'A2F_ACTIVATION';
    public const A2F_CODE_SECOURS = 'A2F_CODE_SECOURS_UTILISE';
    public const A2F_ECHEC = 'A2F_ECHEC';
    public const CATALOGUE_CREATION = 'CATALOGUE_CREATION';
    public const CATALOGUE_MODIFICATION = 'CATALOGUE_MODIFICATION';
    public const CATALOGUE_SUPPRESSION = 'CATALOGUE_SUPPRESSION';

    public function tracer(string $action, string $ressource, ?string $ressourceId, ?string $utilisateurId, ?string $adresseIp): void;
}

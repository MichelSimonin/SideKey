<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Audit\JournalAudit;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Écriture du journal d'audit (INSERT seul : sk_app n'a ni UPDATE ni DELETE sur journal_audit).
 *
 * L'adresse IP n'est jamais stockée en clair (RGPD, minimisation) : seulement HMAC-SHA256(IP, k), où k est
 * dérivé d'APP_SECRET par HKDF. Deux actions de la même adresse restent rapprochables, l'adresse ne se lit pas.
 */
final class JournalAuditRepository implements JournalAudit
{
    private readonly string $cleIp;

    public function __construct(
        private readonly Connection $connexion,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] string $secretApplication,
    ) {
        $this->cleIp = hash_hkdf('sha256', $secretApplication, 32, 'sidekey/journal_audit/ip');
    }

    public function tracer(string $action, string $ressource, ?string $ressourceId, ?string $utilisateurId, ?string $adresseIp): void
    {
        $this->connexion->executeStatement(
            <<<'SQL'
                INSERT INTO journal_audit (action, ressource, ressource_id, ip_hachee, utilisateur_id)
                VALUES (:action, :ressource, CAST(:ressource_id AS uuid), :ip_hachee, CAST(:utilisateur_id AS uuid))
                SQL,
            [
                'action' => $action,
                'ressource' => $ressource,
                'ressource_id' => $ressourceId,
                'ip_hachee' => null === $adresseIp || '' === $adresseIp ? null : hash_hmac('sha256', $adresseIp, $this->cleIp, true),
                'utilisateur_id' => $utilisateurId,
            ],
            ['ip_hachee' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    public function __debugInfo(): array
    {
        return [];
    }
}

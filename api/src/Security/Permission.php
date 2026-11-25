<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Matrice RBAC du CDC §11.5, une permission par ligne. Les rôles viennent de la table role (CLIENT,
 * GESTIONNAIRE, MODERATEUR, ADMIN). Pas de hiérarchie de rôles : l'administrateur ne commande pas et le
 * modérateur n'accède ni aux commandes ni aux clés (séparation des devoirs, security-patterns.md §1).
 *
 * « Consulter le catalogue » est ouvert à tous, visiteur compris : c'est une route publique, pas une permission.
 *
 * La valeur de chaque case est l'attribut passé à #[IsGranted] et à isGranted() ; RbacVoter la décide.
 */
enum Permission: string
{
    case Commander = 'PERMISSION_COMMANDER';                       // commander, révéler ses clés
    case DeposerAvis = 'PERMISSION_DEPOSER_AVIS';
    case GererCatalogue = 'PERMISSION_GERER_CATALOGUE';            // catalogue, offres, import de clés
    case Moderer = 'PERMISSION_MODERER';
    case ConsulterCommandes = 'PERMISSION_CONSULTER_COMMANDES';
    case ValiderCommandeSuspecte = 'PERMISSION_VALIDER_COMMANDE_SUSPECTE';
    case GererRolesEtAudit = 'PERMISSION_GERER_ROLES_ET_AUDIT';

    /** @return list<string> codes de rôle (table role) autorisés */
    public function rolesAutorises(): array
    {
        return match ($this) {
            self::Commander, self::DeposerAvis => ['CLIENT'],
            self::GererCatalogue, self::ConsulterCommandes => ['GESTIONNAIRE', 'ADMIN'],
            self::Moderer => ['MODERATEUR', 'ADMIN'],
            self::ValiderCommandeSuspecte, self::GererRolesEtAudit => ['ADMIN'],
        };
    }

    /** Nom court exposé au front (GET /api/moi) pour masquer ce qui n'est pas permis : jamais une autorisation. */
    public function nom(): string
    {
        return strtolower(substr($this->value, \strlen('PERMISSION_')));
    }
}

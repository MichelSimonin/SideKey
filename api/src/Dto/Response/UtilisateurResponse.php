<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Security\Permission;
use App\Service\Auth\Compte;

/**
 * Contrat de sortie d'un compte (Limited View) : jamais de hachage, de secret TOTP ni de code de secours.
 * « permissions » sert au front à masquer ce qui n'est pas permis ; l'API refuse de toute façon (403).
 */
final class UtilisateurResponse
{
    /**
     * @return array{id: string, email: string, prenom: string, nom: string, langue: string, roles: list<string>,
     *               permissions: list<string>, a2fActive: bool, emailVerifie: bool}
     */
    public static function depuis(Compte $compte): array
    {
        $permissions = [];
        foreach (Permission::cases() as $permission) {
            if ([] !== array_intersect($compte->roles, $permission->rolesAutorises())) {
                $permissions[] = $permission->nom();
            }
        }

        return [
            'id' => $compte->id,
            'email' => $compte->email,
            'prenom' => $compte->prenom,
            'nom' => $compte->nom,
            'langue' => $compte->langue,
            'roles' => $compte->roles,
            'permissions' => $permissions,
            'a2fActive' => $compte->a2fActive,
            'emailVerifie' => $compte->emailVerifie,
        ];
    }
}

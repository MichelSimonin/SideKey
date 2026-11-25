<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\Permission;
use App\Security\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization Enforcer : décide d'une permission de la matrice RBAC (Permission) d'après les rôles du
 * compte, relus en base à chaque requête. Les voters de propriété (commande, clé, avis : anti-IDOR)
 * arrivent avec F-06 et F-10 ; celui-ci couvre le « qui a le droit de faire quoi ».
 *
 * @extends Voter<string, null>
 */
final class RbacVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== Permission::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        $permission = Permission::tryFrom($attribute);
        if (!$utilisateur instanceof Utilisateur || null === $permission) {
            return false;   // refus par défaut
        }

        return [] !== array_intersect($utilisateur->compte->roles, $permission->rolesAutorises());
    }
}

<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Cles\CleRevelable;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter de propriété d'une clé de licence (anti-IDOR, CDC §9.4 et §11.5, OWASP A01).
 *
 * Révéler une clé exige les trois conditions : être un CLIENT (permission « commander »), être l'acheteur
 * de la commande, et que la commande soit PAYEE avec la clé VENDUE. Une commande « à vérifier » (anti-fraude)
 * ou remboursée ne révèle rien. Aucun rôle du back-office n'y a accès, l'administrateur compris (BX-13).
 *
 * @extends Voter<string, CleRevelable>
 */
final class CleLicenceVoter extends Voter
{
    public const REVELER = 'CLE_REVELER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::REVELER === $attribute && $subject instanceof CleRevelable;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur || !$subject instanceof CleRevelable) {
            return false;   // refus par défaut
        }

        return [] !== array_intersect($utilisateur->compte->roles, Permission::Commander->rolesAutorises())
            && hash_equals($subject->proprietaireId, $utilisateur->compte->id)
            && 'PAYEE' === $subject->statutCommande
            && 'VENDUE' === $subject->statutCle;
    }
}

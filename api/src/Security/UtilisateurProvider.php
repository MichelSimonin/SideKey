<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Auth\ComptesUtilisateurs;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Fournisseur d'utilisateurs du pare-feu JWT. L'identifiant du jeton (revendication « sub ») est l'UUID du
 * compte ; le compte est relu en base à chaque requête : un compte désactivé ou supprimé perd son accès
 * immédiatement, sans attendre l'expiration du jeton (15 min).
 *
 * Les revendications du jeton (jti, famille, expiration) sont rattachées à l'utilisateur pour la
 * déconnexion ; elles ne servent jamais à décider d'un droit : les rôles viennent de la base.
 */
final class UtilisateurProvider implements PayloadAwareUserProviderInterface
{
    public function __construct(private readonly ComptesUtilisateurs $comptes)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->charger($identifier);
    }

    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): UserInterface
    {
        return $this->charger($identifier, \is_string($payload['jti'] ?? null) ? $payload['jti'] : null,
            \is_string($payload['fam'] ?? null) ? $payload['fam'] : null, \is_int($payload['exp'] ?? null) ? $payload['exp'] : null);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException();
        }

        return $this->charger($user->getUserIdentifier(), $user->jti, $user->familleId, $user->expiration);
    }

    public function supportsClass(string $class): bool
    {
        return Utilisateur::class === $class || is_subclass_of($class, Utilisateur::class);
    }

    private function charger(string $id, ?string $jti = null, ?string $familleId = null, ?int $expiration = null): Utilisateur
    {
        $compte = $this->comptes->parId($id);
        if (null === $compte || !$compte->actif) {
            throw new UserNotFoundException();   // message générique : rien ne distingue inconnu et désactivé
        }

        return new Utilisateur($compte, $jti, $familleId, $expiration);
    }
}

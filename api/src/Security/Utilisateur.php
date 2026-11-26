<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Auth\Compte;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utilisateur authentifié. Ce n'est pas une entité Doctrine : il est construit à partir du Compte lu en base
 * (tables utilisateur, utilisateur_role, role) par un composant DBAL, puis rechargé à chaque requête.
 *
 * Il ne porte ni secret TOTP ni code de secours, et n'est jamais sérialisé dans une réponse : l'API passe
 * toujours par un DTO (Limited View).
 */
final class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @var list<string> */
    private readonly array $roles;

    /**
     * @param string|null $jti        identifiant du jeton d'accès présenté (null hors requête authentifiée par JWT)
     * @param string|null $familleId  session du jeton d'accès
     * @param int|null    $expiration expiration du jeton d'accès (timestamp)
     * @param bool        $a2fValide  le jeton porte la revendication « a2f » : le second facteur a été vérifié pour cette session
     */
    public function __construct(
        public readonly Compte $compte,
        public readonly ?string $jti = null,
        public readonly ?string $familleId = null,
        public readonly ?int $expiration = null,
        public readonly bool $a2fValide = false,
    ) {
        // Rôles issus de la table role, préfixés pour Symfony. Aucun ROLE_USER implicite : un compte
        // sans rôle n'a aucun droit (Fail Securely).
        $this->roles = array_values(array_unique(array_map(static fn (string $code): string => 'ROLE_'.$code, $compte->roles)));
    }

    public function getUserIdentifier(): string
    {
        return $this->compte->id;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->compte->motDePasseHash;
    }

    public function eraseCredentials(): void
    {
        // Rien à effacer : l'objet est reconstruit à chaque requête et ne contient aucun secret en clair.
    }
}

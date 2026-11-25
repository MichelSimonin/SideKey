<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Compte utilisateur tel que le voit la couche métier : lecture seule, sans secret TOTP ni code de secours
 * (Limited View). Le hachage du mot de passe n'y figure que pour être vérifié par AuthentificationService.
 */
final class Compte
{
    /** Rôles du back-office : l'A2F leur sera imposée par F-04 (CDC §11.4). */
    public const ROLES_BACK_OFFICE = ['GESTIONNAIRE', 'MODERATEUR', 'ADMIN'];

    /** @param list<string> $roles codes de la table role (CLIENT, GESTIONNAIRE, MODERATEUR, ADMIN) */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $motDePasseHash,
        public readonly string $prenom,
        public readonly string $nom,
        public readonly string $langue,
        public readonly array $roles,
        public readonly bool $a2fActive,
        public readonly bool $actif,
        public readonly bool $emailVerifie,
    ) {
    }
}

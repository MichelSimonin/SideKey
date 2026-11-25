<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Security\Utilisateur;
use App\Service\Auth\Compte;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Fabrique de comptes et de hasher (argon2id au coût minimal) pour les tests. */
final class FabriqueCompte
{
    public const MOT_DE_PASSE = 'une phrase de passe de test';

    public static function hashers(): PasswordHasherFactoryInterface
    {
        return new PasswordHasherFactory([
            Utilisateur::class => ['algorithm' => 'argon2id', 'time_cost' => 3, 'memory_cost' => 10],
        ]);
    }

    public static function hash(string $motDePasse = self::MOT_DE_PASSE): string
    {
        return self::hashers()->getPasswordHasher(Utilisateur::class)->hash($motDePasse);
    }

    /** @param list<string> $roles */
    public static function creer(
        int $numero = 1,
        array $roles = ['CLIENT'],
        bool $actif = true,
        bool $a2f = false,
        ?string $hash = null,
        string $email = '',
    ): Compte {
        return new Compte(
            self::uuid($numero),
            '' !== $email ? $email : 'compte'.$numero.'@sidekey.test',
            $hash ?? self::hash(),
            'Prénom',
            'Nom',
            'fr',
            $roles,
            $a2f,
            $actif,
            true,
        );
    }

    public static function uuid(int $numero): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $numero);
    }
}

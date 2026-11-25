<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\EmailDejaUtiliseException;
use App\Service\Auth\Compte;
use App\Service\Auth\ComptesUtilisateurs;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Comptes utilisateurs (SQL paramétré, DBAL). Lecture en projection explicite : ni totp_secret_chiffre ni
 * code de secours ne sortent de la base (Limited View). Aucune décision d'autorisation ici.
 */
final class UtilisateurRepository implements ComptesUtilisateurs
{
    private const SELECT = <<<'SQL'
        SELECT u.id::text, u.email, u.mot_de_passe_hash, u.prenom, u.nom, u.langue_code,
               u.a2f_active, u.actif, u.email_verifie,
               coalesce(string_agg(ur.role_code, ',' ORDER BY ur.role_code), '') AS roles
          FROM utilisateur u
          LEFT JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
        SQL;

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function parEmail(string $email): ?Compte
    {
        // Index unique uq_utilisateur_email (lower(email)) : la recherche ignore la casse.
        $ligne = $this->connexion->fetchAssociative(
            self::SELECT.' WHERE lower(u.email) = lower(:email) AND u.anonymise_le IS NULL GROUP BY u.id',
            ['email' => $email],
        );

        return false === $ligne ? null : self::compte($ligne);
    }

    public function parId(string $id): ?Compte
    {
        if (!PanierId::estUuid($id)) {
            return null;   // jamais d'erreur de conversion uuid côté base pour une valeur forgée
        }
        $ligne = $this->connexion->fetchAssociative(
            self::SELECT.' WHERE u.id = :id::uuid AND u.anonymise_le IS NULL GROUP BY u.id',
            ['id' => strtolower($id)],
        );

        return false === $ligne ? null : self::compte($ligne);
    }

    public function creerClient(string $email, string $motDePasseHash, string $prenom, string $nom, string $langue): string
    {
        try {
            $id = $this->connexion->fetchOne(
                <<<'SQL'
                    WITH nouveau AS (
                        INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, langue_code)
                        VALUES (:email, :hash, :prenom, :nom, :langue)
                        RETURNING id
                    )
                    INSERT INTO utilisateur_role (utilisateur_id, role_code)
                    SELECT id, 'CLIENT' FROM nouveau
                    RETURNING utilisateur_id::text
                    SQL,
                ['email' => $email, 'hash' => $motDePasseHash, 'prenom' => $prenom, 'nom' => $nom, 'langue' => $langue],
            );
        } catch (UniqueConstraintViolationException) {
            throw new EmailDejaUtiliseException();   // index uq_utilisateur_email : la base fait foi, pas un SELECT préalable (course)
        }

        return (string) $id;
    }

    public function enregistrerConnexion(string $id, ?string $nouveauHash = null): void
    {
        $this->connexion->executeStatement(
            'UPDATE utilisateur SET derniere_connexion = now(), mot_de_passe_hash = coalesce(:hash, mot_de_passe_hash) WHERE id = :id::uuid',
            ['id' => strtolower($id), 'hash' => $nouveauHash],
        );
    }

    /** @param array<string, mixed> $ligne */
    private static function compte(array $ligne): Compte
    {
        return new Compte(
            (string) $ligne['id'],
            (string) $ligne['email'],
            (string) $ligne['mot_de_passe_hash'],
            (string) $ligne['prenom'],
            (string) $ligne['nom'],
            (string) $ligne['langue_code'],
            '' === $ligne['roles'] ? [] : explode(',', (string) $ligne['roles']),
            (bool) $ligne['a2f_active'],
            (bool) $ligne['actif'],
            (bool) $ligne['email_verifie'],
        );
    }
}

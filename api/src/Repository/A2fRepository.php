<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\A2f\ComptesA2f;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Données de l'A2F (SQL paramétré, DBAL) : utilisateur.totp_secret_chiffre / a2f_active et code_secours.
 * Projections explicites ; seuls des chiffrés et des hachages sortent. Aucune décision d'autorisation ici.
 * Les identifiants sont validés (UUID) avant la requête.
 */
final class A2fRepository implements ComptesA2f
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function secretChiffre(string $utilisateurId): ?string
    {
        $this->verifier($utilisateurId);
        $secret = $this->connexion->fetchOne('SELECT totp_secret_chiffre FROM utilisateur WHERE id = :id::uuid', ['id' => strtolower($utilisateurId)]);
        if (false === $secret || null === $secret) {
            return null;
        }

        // PDO renvoie un BYTEA sous forme de flux de ressource
        return \is_resource($secret) ? (string) stream_get_contents($secret) : (string) $secret;
    }

    public function enregistrerSecretEnAttente(string $utilisateurId, string $secretChiffre): bool
    {
        $this->verifier($utilisateurId);

        return 1 === $this->connexion->executeStatement(
            'UPDATE utilisateur SET totp_secret_chiffre = :secret, modifie_le = now() WHERE id = :id::uuid AND NOT a2f_active',
            ['secret' => $secretChiffre, 'id' => strtolower($utilisateurId)],
            ['secret' => ParameterType::BINARY],
        );
    }

    public function activer(string $utilisateurId, array $hachages): bool
    {
        $this->verifier($utilisateurId);
        $id = strtolower($utilisateurId);

        return $this->connexion->transactional(function (Connection $c) use ($id, $hachages): bool {
            // L'activation d'abord : elle ne réussit que s'il y a un secret en attente et que l'A2F n'est pas déjà active.
            $active = $c->executeStatement(
                'UPDATE utilisateur SET a2f_active = true, modifie_le = now() WHERE id = :id::uuid AND NOT a2f_active AND totp_secret_chiffre IS NOT NULL',
                ['id' => $id],
            );
            if (1 !== $active) {
                return false;
            }
            // Les anciens codes sont supprimés : un lot de 10 codes en remplace toujours un autre (décision du 24/11/2026).
            $c->executeStatement('DELETE FROM code_secours WHERE utilisateur_id = :id::uuid', ['id' => $id]);
            foreach ($hachages as $hache) {
                $c->executeStatement('INSERT INTO code_secours (code_hash, utilisateur_id) VALUES (:hache, :id::uuid)', ['hache' => $hache, 'id' => $id]);
            }

            return true;
        });
    }

    public function codesSecoursDisponibles(string $utilisateurId): array
    {
        $this->verifier($utilisateurId);

        return array_map(
            static fn (array $l): array => ['id' => (string) $l['id'], 'hache' => (string) $l['code_hash']],
            $this->connexion->fetchAllAssociative(
                'SELECT id::text AS id, code_hash FROM code_secours WHERE utilisateur_id = :id::uuid AND utilise_le IS NULL ORDER BY cree_le, id',
                ['id' => strtolower($utilisateurId)],
            ),
        );
    }

    public function consommerCodeSecours(string $codeId): bool
    {
        $this->verifier($codeId);

        // Un seul UPDATE conditionnel : deux requêtes simultanées avec le même code, une seule obtient « 1 ligne ».
        return 1 === $this->connexion->executeStatement(
            'UPDATE code_secours SET utilise_le = now() WHERE id = :id::uuid AND utilise_le IS NULL',
            ['id' => strtolower($codeId)],
        );
    }

    private function verifier(string $id): void
    {
        if (!PanierId::estUuid($id)) {
            throw new \InvalidArgumentException('Identifiant invalide.');
        }
    }
}

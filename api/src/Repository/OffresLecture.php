<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Catalogue\Gestion\OffreAdmin;
use Doctrine\DBAL\Connection;

/** Lecture des offres du back-office, partagée par les dépôts des jeux et des offres : une seule projection, sans clé de licence. */
final class OffresLecture
{
    private const SELECT = <<<'SQL'
        SELECT o.id::text AS id, o.plateforme_id::text AS plateforme_id, p.code, p.libelle, o.prix_ttc::text AS prix,
               o.seuil_alerte, o.actif, o.version,
               (SELECT count(*)::int FROM cle_licence k WHERE k.offre_id = o.id AND k.statut = 'DISPONIBLE') AS stock
          FROM offre o
          JOIN plateforme p ON p.id = o.plateforme_id
        SQL;

    /** @return list<OffreAdmin> */
    public static function pourJeu(Connection $connexion, string $jeuId): array
    {
        return array_map(self::offre(...), $connexion->fetchAllAssociative(
            self::SELECT.' WHERE o.jeu_id = :jeu::uuid ORDER BY p.code, o.actif DESC, o.cree_le, o.id',
            ['jeu' => $jeuId],
        ));
    }

    public static function parId(Connection $connexion, string $offreId): ?OffreAdmin
    {
        $ligne = $connexion->fetchAssociative(self::SELECT.' WHERE o.id = :id::uuid', ['id' => $offreId]);

        return false === $ligne ? null : self::offre($ligne);
    }

    /** @param array<string, mixed> $ligne */
    private static function offre(array $ligne): OffreAdmin
    {
        return new OffreAdmin(
            (string) $ligne['id'], (string) $ligne['plateforme_id'], (string) $ligne['code'], (string) $ligne['libelle'],
            (string) $ligne['prix'], (int) $ligne['seuil_alerte'], (bool) $ligne['actif'], (int) $ligne['version'], (int) $ligne['stock'],
        );
    }
}

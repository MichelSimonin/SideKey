<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CatalogueException;
use App\Service\Catalogue\Gestion\Identifiant;
use App\Service\Catalogue\Gestion\PlateformeAdmin;
use App\Service\Catalogue\Gestion\PlateformesAdmin;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Plateformes du back-office (SQL paramétré, DBAL). Code unique (uq_plateforme_code) et format (ck_plateforme_code) par la base. */
final class PlateformesRepository implements PlateformesAdmin
{
    private const SELECT = <<<'SQL'
        SELECT p.id::text AS id, p.code, p.libelle, (SELECT count(*) FROM offre o WHERE o.plateforme_id = p.id)::int AS nombre_offres
          FROM plateforme p
        SQL;

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function lister(): array
    {
        return array_map(self::plateforme(...), $this->connexion->fetchAllAssociative(self::SELECT.' ORDER BY p.libelle, p.code'));
    }

    public function trouver(string $id): PlateformeAdmin
    {
        $ligne = $this->connexion->fetchAssociative(self::SELECT.' WHERE p.id = :id::uuid', ['id' => Identifiant::exiger($id)]);

        return false === $ligne ? throw CatalogueException::introuvable() : self::plateforme($ligne);
    }

    public function creer(string $code, string $libelle): PlateformeAdmin
    {
        try {
            $id = (string) $this->connexion->fetchOne('INSERT INTO plateforme (code, libelle) VALUES (:code, :libelle) RETURNING id::text', ['code' => $code, 'libelle' => $libelle]);
        } catch (UniqueConstraintViolationException) {
            throw CatalogueException::doublon('error.plateforme_doublon');
        }

        return $this->trouver($id);
    }

    public function modifier(string $id, string $libelle): PlateformeAdmin
    {
        $id = Identifiant::exiger($id);
        $modifies = $this->connexion->executeStatement('UPDATE plateforme SET libelle = :libelle, modifie_le = now() WHERE id = :id::uuid', ['libelle' => $libelle, 'id' => $id]);

        return 1 === $modifies ? $this->trouver($id) : throw CatalogueException::introuvable();
    }

    public function supprimer(string $id): void
    {
        try {
            $supprimes = $this->connexion->executeStatement('DELETE FROM plateforme WHERE id = :id::uuid', ['id' => Identifiant::exiger($id)]);
        } catch (ForeignKeyConstraintViolationException) {
            throw CatalogueException::utilisee();   // des offres s'y rattachent : la base refuse (RESTRICT)
        }
        if (1 !== $supprimes) {
            throw CatalogueException::introuvable();
        }
    }

    public function slugsDesJeux(string $id): array
    {
        return array_map('strval', $this->connexion->fetchFirstColumn(
            'SELECT DISTINCT j.slug FROM jeu j JOIN offre o ON o.jeu_id = j.id WHERE o.plateforme_id = :id::uuid ORDER BY j.slug',
            ['id' => Identifiant::exiger($id)],
        ));
    }

    /** @param array<string, mixed> $ligne */
    private static function plateforme(array $ligne): PlateformeAdmin
    {
        return new PlateformeAdmin((string) $ligne['id'], (string) $ligne['code'], (string) $ligne['libelle'], (int) $ligne['nombre_offres']);
    }
}

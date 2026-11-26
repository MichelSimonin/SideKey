<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CatalogueException;
use App\Service\Catalogue\Gestion\EditeurAdmin;
use App\Service\Catalogue\Gestion\EditeursAdmin;
use App\Service\Catalogue\Gestion\Identifiant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Éditeurs du back-office (SQL paramétré, DBAL). Les contraintes de la base (nom unique, clé étrangère RESTRICT) font foi. */
final class EditeursRepository implements EditeursAdmin
{
    private const SELECT = <<<'SQL'
        SELECT e.id::text AS id, e.nom, (SELECT count(*) FROM jeu j WHERE j.editeur_id = e.id)::int AS nombre_jeux
          FROM editeur e
        SQL;

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function lister(): array
    {
        return array_map(self::editeur(...), $this->connexion->fetchAllAssociative(self::SELECT.' ORDER BY e.nom, e.id'));
    }

    public function trouver(string $id): EditeurAdmin
    {
        $ligne = $this->connexion->fetchAssociative(self::SELECT.' WHERE e.id = :id::uuid', ['id' => Identifiant::exiger($id)]);

        return false === $ligne ? throw CatalogueException::introuvable() : self::editeur($ligne);
    }

    public function creer(string $nom): EditeurAdmin
    {
        try {
            $id = (string) $this->connexion->fetchOne('INSERT INTO editeur (nom) VALUES (:nom) RETURNING id::text', ['nom' => $nom]);
        } catch (UniqueConstraintViolationException) {
            throw CatalogueException::doublon('error.editeur_doublon');
        }

        return $this->trouver($id);
    }

    public function modifier(string $id, string $nom): EditeurAdmin
    {
        $id = Identifiant::exiger($id);
        try {
            $modifies = $this->connexion->executeStatement('UPDATE editeur SET nom = :nom, modifie_le = now() WHERE id = :id::uuid', ['nom' => $nom, 'id' => $id]);
        } catch (UniqueConstraintViolationException) {
            throw CatalogueException::doublon('error.editeur_doublon');
        }

        return 1 === $modifies ? $this->trouver($id) : throw CatalogueException::introuvable();
    }

    public function supprimer(string $id): void
    {
        try {
            $supprimes = $this->connexion->executeStatement('DELETE FROM editeur WHERE id = :id::uuid', ['id' => Identifiant::exiger($id)]);
        } catch (ForeignKeyConstraintViolationException) {
            throw CatalogueException::utilisee();   // des jeux s'y rattachent : la base refuse (RESTRICT)
        }
        if (1 !== $supprimes) {
            throw CatalogueException::introuvable();
        }
    }

    public function slugsDesJeux(string $id): array
    {
        return array_map('strval', $this->connexion->fetchFirstColumn('SELECT slug FROM jeu WHERE editeur_id = :id::uuid ORDER BY slug', ['id' => Identifiant::exiger($id)]));
    }

    /** @param array<string, mixed> $ligne */
    private static function editeur(array $ligne): EditeurAdmin
    {
        return new EditeurAdmin((string) $ligne['id'], (string) $ligne['nom'], (int) $ligne['nombre_jeux']);
    }
}

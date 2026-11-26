<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F-06 — Fonction fn_annuler_commande : annulation par le client et remise en stock des clés.
 *
 * Le SQL vit dans api/sql/functions/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 * Le fichier contient plusieurs commandes (et un corps PL/pgSQL) : il est envoyé d'un bloc par
 * executeStatement(), car addSql() passe par une requête préparée, que PostgreSQL limite à une seule commande.
 */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fonction fn_annuler_commande : annulation et remise en stock des clés (F-06)';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('002_annulation.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('002_annulation.down.sql'));
    }

    private function sql(string $fichier): string
    {
        $contenu = file_get_contents(__DIR__ . '/../sql/functions/' . $fichier);
        if (false === $contenu) {
            throw new \RuntimeException(sprintf('Fichier SQL introuvable : %s', $fichier));
        }

        return $contenu;
    }
}

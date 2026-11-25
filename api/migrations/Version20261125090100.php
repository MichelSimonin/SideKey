<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-02 — Triggers défensifs : transitions et historique des commandes, protection des clés vendues, traces immuables.
 *
 * Le SQL vit dans api/sql/triggers/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 *
 * Le fichier contient plusieurs commandes (et des corps PL/pgSQL) : il est envoyé d'un bloc par
 * executeStatement(), car addSql() passe par une requête préparée, que PostgreSQL limite à une seule
 * commande. Conséquence : --dry-run et --write-sql ne s'appliquent pas à cette migration.
 */
final class Version20261125090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Triggers défensifs trg_* (T-02)';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('001_triggers.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('001_triggers.down.sql'));
    }

    private function sql(string $fichier): string
    {
        $contenu = file_get_contents(__DIR__ . '/../sql/triggers/' . $fichier);
        if (false === $contenu) {
            throw new \RuntimeException(sprintf('Fichier SQL introuvable : %s', $fichier));
        }

        return $contenu;
    }
}

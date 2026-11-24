<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-01 — Schéma relationnel : tables, clés primaires et étrangères, données de référence.
 *
 * Le SQL vit dans api/sql/schema/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma relationnel : tables, clés primaires et étrangères, données de référence (T-01)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->sql('001_schema.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->sql('001_schema.down.sql'));
    }

    private function sql(string $fichier): string
    {
        $contenu = file_get_contents(__DIR__ . '/../sql/schema/' . $fichier);
        if (false === $contenu) {
            throw new \RuntimeException(sprintf('Fichier SQL introuvable : %s', $fichier));
        }

        return $contenu;
    }
}

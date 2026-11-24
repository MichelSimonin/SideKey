<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-01 — Contraintes d'intégrité (CHECK, unicités dont l'empreinte des clés) et index.
 *
 * Le SQL vit dans api/sql/schema/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 */
final class Version20261124100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contraintes d\'intégrité (CHECK, unicités, empreinte des clés) et index (T-01)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->sql('002_contraintes.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->sql('002_contraintes.down.sql'));
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

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F-08 — Protection des clés vendues étendue à la colonne suffixe (F-08).
 *
 * Le SQL vit dans api/sql/triggers/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 * Le fichier contient plusieurs commandes : il est envoyé d'un bloc par executeStatement(), car addSql()
 * passe par une requête préparée, que PostgreSQL limite à une seule commande.
 */
final class Version20261126090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Protection des clés vendues étendue à la colonne suffixe (F-08)';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('002_cle_suffixe.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('002_cle_suffixe.down.sql'));
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

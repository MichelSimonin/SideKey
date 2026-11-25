<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-01 — GRANT / REVOKE. Prérequis : api/sql/roles/01_roles.sql exécuté par un super-utilisateur (les comptes
 * PostgreSQL sont globaux au serveur, une migration exécutée par sk_owner ne peut pas les créer).
 *
 * Le SQL vit dans api/sql/roles/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 *
 * Le fichier contient plusieurs commandes (et des corps PL/pgSQL) : il est envoyé d'un bloc par
 * executeStatement(), car addSql() passe par une requête préparée, que PostgreSQL limite à une seule
 * commande. Conséquence : --dry-run et --write-sql ne s'appliquent pas à cette migration.
 */
final class Version20261124100300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droits des comptes sk_app et sk_reporting : GRANT / REVOKE (T-01)';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('02_privileges.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('02_privileges.down.sql'));
    }

    private function sql(string $fichier): string
    {
        $contenu = file_get_contents(__DIR__ . '/../sql/roles/' . $fichier);
        if (false === $contenu) {
            throw new \RuntimeException(sprintf('Fichier SQL introuvable : %s', $fichier));
        }

        return $contenu;
    }
}

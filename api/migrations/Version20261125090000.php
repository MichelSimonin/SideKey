<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-02 — Fonctions métier PL/pgSQL (fn_reserver_cles, fn_valider_commande, fn_liberer_reservations_expirees).
 *
 * Le SQL vit dans api/sql/functions/ (source unique, relue en PR et jouée aussi par la CI avec psql).
 *
 * Le fichier contient plusieurs commandes (et des corps PL/pgSQL) : il est envoyé d'un bloc par
 * executeStatement(), car addSql() passe par une requête préparée, que PostgreSQL limite à une seule
 * commande. Conséquence : --dry-run et --write-sql ne s'appliquent pas à cette migration.
 */
final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fonctions métier défensives fn_* (T-02)';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('001_fonctions.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement($this->sql('001_fonctions.down.sql'));
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

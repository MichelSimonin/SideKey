<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F-09 — Les plateformes sont gérées par le back-office : sk_app reçoit INSERT, UPDATE et DELETE sur plateforme (jusque-là
 * lecture seule, T-01). Une plateforme utilisée par une offre ne se supprime pas : la clé étrangère est en RESTRICT.
 *
 * Le droit est aussi dans api/sql/roles/02_privileges.sql (source des installations neuves, jouée par db-apply.sh) ;
 * cette migration l'applique aux bases déjà créées. GRANT est idempotent.
 */
final class Version20261126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droits de sk_app sur plateforme : INSERT, UPDATE, DELETE (F-09)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON plateforme TO sk_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON plateforme FROM sk_app');
    }
}

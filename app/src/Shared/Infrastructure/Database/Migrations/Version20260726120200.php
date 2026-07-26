<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create coating_system_compliance index table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system_compliance (
                system_id  UUID        NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
                standard   VARCHAR(32) NOT NULL,
                category   VARCHAR(16) NOT NULL,
                durability VARCHAR(16) NOT NULL,
                PRIMARY KEY (system_id, standard, category, durability)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_csc_search ON coating_system_compliance (standard, category, durability, system_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS coating_system_compliance');
    }
}

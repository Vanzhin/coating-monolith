<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create coating_system and coating_system_layer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system (
                id                    UUID PRIMARY KEY,
                title                 VARCHAR(100)  NOT NULL,
                description           TEXT          NOT NULL DEFAULT '',
                substrate             VARCHAR(32)   NOT NULL,
                surface_preparation   JSONB         NOT NULL,
                created_at            TIMESTAMPTZ   NOT NULL,
                updated_at            TIMESTAMPTZ   NOT NULL
            )
        SQL);
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS coating_system_layer (
                id          UUID PRIMARY KEY,
                system_id   UUID NOT NULL REFERENCES coating_system(id) ON DELETE CASCADE,
                coating_id  UUID NOT NULL REFERENCES coatings_coating(id) ON DELETE RESTRICT,
                position    INT  NOT NULL CHECK (position >= 1),
                dft         INT  NOT NULL CHECK (dft >= 1),
                CONSTRAINT uniq_csl_system_position UNIQUE (system_id, position)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_csl_system ON coating_system_layer (system_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS coating_system_layer');
        $this->addSql('DROP TABLE IF EXISTS coating_system');
    }
}

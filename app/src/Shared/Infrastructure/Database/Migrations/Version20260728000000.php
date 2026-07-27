<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create surface_treatment table; replace surface_preparation columns with surface_treatment_id FK in coating_system.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS surface_treatment (
                id             UUID          PRIMARY KEY,
                description    TEXT          NOT NULL,
                code           VARCHAR(30),
                standard_code  VARCHAR(100),
                substrate_scope JSONB        NOT NULL,
                created_at     TIMESTAMPTZ   NOT NULL,
                updated_at     TIMESTAMPTZ   NOT NULL
            )
        SQL);

        $this->addSql(<<<SQL
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_surface_treatment_code_std
              ON surface_treatment (code, standard_code)
              WHERE code IS NOT NULL AND standard_code IS NOT NULL
        SQL);

        $this->addSql('ALTER TABLE coating_system DROP COLUMN IF EXISTS surface_preparation');

        $this->addSql(<<<SQL
            ALTER TABLE coating_system
              ADD COLUMN IF NOT EXISTS surface_treatment_id UUID NOT NULL
              REFERENCES surface_treatment(id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating_system DROP COLUMN IF EXISTS surface_treatment_id');

        $this->addSql('ALTER TABLE coating_system ADD COLUMN IF NOT EXISTS surface_preparation JSONB');

        $this->addSql('DROP INDEX IF EXISTS uniq_surface_treatment_code_std');
        $this->addSql('DROP TABLE IF EXISTS surface_treatment');
    }
}

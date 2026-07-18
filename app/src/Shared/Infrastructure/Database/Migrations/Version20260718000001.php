<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chemical_resistance_{substance,note,assessment} tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_substance (
                id UUID PRIMARY KEY,
                canonical_name VARCHAR(200) NOT NULL,
                canonical_name_key VARCHAR(200) NOT NULL,
                cas VARCHAR(15) NULL,
                aliases JSONB NOT NULL DEFAULT '[]'::jsonb
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_substance_canonical_key ON chemical_resistance_substance (canonical_name_key)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_substance_cas ON chemical_resistance_substance (cas) WHERE cas IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_substance_aliases_gin ON chemical_resistance_substance USING gin (aliases jsonb_path_ops)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_note (
                id UUID PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chemical_resistance_assessment (
                id UUID PRIMARY KEY,
                coating_id UUID NOT NULL REFERENCES coatings_coating(id) ON DELETE CASCADE,
                substance_id UUID NOT NULL REFERENCES chemical_resistance_substance(id) ON DELETE RESTRICT,
                grade VARCHAR(2) NOT NULL,
                max_temperature_celsius SMALLINT NOT NULL DEFAULT 40,
                note_ids JSONB NOT NULL DEFAULT '[]'::jsonb
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_assessment_coating_substance ON chemical_resistance_assessment (coating_id, substance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_coating ON chemical_resistance_assessment (coating_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_substance ON chemical_resistance_assessment (substance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_assessment_coating_grade ON chemical_resistance_assessment (coating_id, grade)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_assessment');
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_note');
        $this->addSql('DROP TABLE IF EXISTS chemical_resistance_substance');
    }
}

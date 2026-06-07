<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Конвертирует столбцы dry_to_touch и full_cure в coatings_coating
 * из FLOAT в JSONB. Существующие значения переносятся как одна точка
 * при +20°C с признаком is_calculated=false.
 */
final class Version20260606170726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert coatings_coating.dry_to_touch and full_cure from FLOAT to JSONB drying-time series.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coatings_coating
            ALTER COLUMN dry_to_touch TYPE JSONB
            USING jsonb_build_array(
                jsonb_build_object(
                    'temperature_at', 20,
                    'time_in_minutes', dry_to_touch::double precision,
                    'is_calculated', false
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE coatings_coating
            ALTER COLUMN full_cure TYPE JSONB
            USING jsonb_build_array(
                jsonb_build_object(
                    'temperature_at', 20,
                    'time_in_minutes', full_cure::double precision,
                    'is_calculated', false
                )
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coatings_coating
            ALTER COLUMN dry_to_touch TYPE DOUBLE PRECISION
            USING (dry_to_touch->0->>'time_in_minutes')::double precision
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE coatings_coating
            ALTER COLUMN full_cure TYPE DOUBLE PRECISION
            USING (full_cure->0->>'time_in_minutes')::double precision
        SQL);
    }
}

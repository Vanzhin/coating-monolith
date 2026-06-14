<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614072729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert min/max recoating interval (hours, separate columns) into recoating_interval JSONB array of points (minutes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN recoating_interval JSONB');

        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET recoating_interval = jsonb_build_array(
                jsonb_build_object(
                    'temperature_at', 20,
                    'min_minutes',    (min_recoating_interval * 60)::int,
                    'max_minutes',    CASE WHEN max_recoating_interval IS NULL THEN NULL ELSE (max_recoating_interval * 60)::int END
                )
            )
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN recoating_interval SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN min_recoating_interval');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN max_recoating_interval');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN min_recoating_interval DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN max_recoating_interval DOUBLE PRECISION');

        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET
                min_recoating_interval = ((recoating_interval->0->>'min_minutes')::numeric / 60),
                max_recoating_interval = CASE
                    WHEN recoating_interval->0->>'max_minutes' IS NULL THEN NULL
                    ELSE ((recoating_interval->0->>'max_minutes')::numeric / 60)
                END
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN min_recoating_interval SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN recoating_interval');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Заменяет три отдельных столбца (min_dft, tds_dft, max_dft) на единый JSONB-столбец dft_range,
 * чтобы Coating мог хранить DftRange как Value Object.
 */
final class Version20260606171357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace min_dft / tds_dft / max_dft with single JSONB column dft_range in coatings_coating.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN dft_range JSONB');

        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET dft_range = jsonb_build_object(
                'min', min_dft,
                'max', max_dft,
                'tds_dft', tds_dft,
                'type', 'мкм'
            )
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN dft_range SET NOT NULL');

        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN min_dft');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN max_dft');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN tds_dft');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN min_dft INTEGER');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN max_dft INTEGER');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN tds_dft INTEGER');

        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET min_dft = (dft_range->>'min')::integer,
                max_dft = (dft_range->>'max')::integer,
                tds_dft = (dft_range->>'tds_dft')::integer
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN min_dft SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN max_dft SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN tds_dft SET NOT NULL');

        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN dft_range');
    }
}

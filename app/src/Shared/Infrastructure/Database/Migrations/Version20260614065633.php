<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614065633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round drying time JSONB float values to int (canonical unit = minutes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET dry_to_touch = (
                SELECT jsonb_agg(
                    jsonb_set(
                        elem,
                        '{time_in_minutes}',
                        to_jsonb(round((elem->>'time_in_minutes')::numeric)::int)
                    )
                )
                FROM jsonb_array_elements(dry_to_touch) elem
            )
            WHERE dry_to_touch IS NOT NULL
        SQL);

        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET full_cure = (
                SELECT jsonb_agg(
                    jsonb_set(
                        elem,
                        '{time_in_minutes}',
                        to_jsonb(round((elem->>'time_in_minutes')::numeric)::int)
                    )
                )
                FROM jsonb_array_elements(full_cure) elem
            )
            WHERE full_cure IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: округление до int необратимо без потерь, но дробных частей не было нигде, кроме редких случаев.
    }
}

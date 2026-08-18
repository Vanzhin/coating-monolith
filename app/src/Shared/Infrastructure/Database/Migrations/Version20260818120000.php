<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Справочник цветов coatings_coating_color: id (uuid) + name + ral (nullable) + hex.
 * Уникальность — по паре (lower(name), hex): одинаковое имя с разным hex допускается,
 * полный дубль — нет. Идемпотентно (IF NOT EXISTS).
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coatings_coating_color reference table with unique (lower(name), hex) index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coatings_coating_color (
                id uuid NOT NULL,
                name varchar(100) NOT NULL,
                ral varchar(20) DEFAULT NULL,
                hex varchar(7) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_coating_color_name_hex
                ON coatings_coating_color (LOWER(name), hex)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_coating_color_name_hex');
        $this->addSql('DROP TABLE IF EXISTS coatings_coating_color');
    }
}

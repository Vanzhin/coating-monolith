<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Соотношение смешивания компонентов покрытия: nullable JSONB-колонка mixing_ratio.
 * Хранит объёмное и/или массовое соотношение (VO MixingRatio). null = однокомпонентное
 * покрытие. Идемпотентно (ADD/DROP COLUMN IF [NOT] EXISTS).
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable jsonb column coatings_coating.mixing_ratio for component mixing ratio (volume and/or mass).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS mixing_ratio JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS mixing_ratio');
    }
}

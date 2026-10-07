<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personnel: у профиля сотрудника появляется необязательная дата рождения (birth_date). Подставляется в журналы
 * инструктажа ({{log.birth_date}}). Nullable: пусто → в документе пусто. Существующие профили не затронуты.
 */
final class Version20261015150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personnel: personnel_profile.birth_date (nullable) — дата рождения сотрудника.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personnel_profile ADD COLUMN IF NOT EXISTS birth_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personnel_profile DROP COLUMN IF EXISTS birth_date');
    }
}

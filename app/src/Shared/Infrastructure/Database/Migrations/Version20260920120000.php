<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Реквизиты отчёта: адрес объекта (текст) и период проведения работ (интервал дат, JSON {from,to}).
 * Идемпотентно: ADD COLUMN IF NOT EXISTS.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report: add address (text) and work_period (date interval, JSON).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS work_period JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report DROP COLUMN IF EXISTS address');
        $this->addSql('ALTER TABLE report DROP COLUMN IF EXISTS work_period');
    }
}

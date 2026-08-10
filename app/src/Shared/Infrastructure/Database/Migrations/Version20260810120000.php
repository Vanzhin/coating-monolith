<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * coating_system: добавить колонку environment (среда эксплуатации системы) — измерение
 * интервалов перекрытия. Идемпотентно: ADD COLUMN IF NOT EXISTS с дефолтом на случай данных,
 * затем снимаем дефолт, чтобы схема совпадала с ORM-маппингом (без server-default).
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coating_system: add environment column (recoating environment dimension)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE coating_system ADD COLUMN IF NOT EXISTS environment VARCHAR(32) NOT NULL DEFAULT 'atmospheric'");
        $this->addSql('ALTER TABLE coating_system ALTER COLUMN environment DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating_system DROP COLUMN IF EXISTS environment');
    }
}

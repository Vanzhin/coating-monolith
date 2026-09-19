<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Причина отклонения отчёта ревьюером — текст для автора. Идемпотентно: ADD COLUMN IF NOT EXISTS.
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rejection_reason to report.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report DROP COLUMN IF EXISTS rejection_reason');
    }
}

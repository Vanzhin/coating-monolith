<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * inquiry: content_hash — отпечаток собранного входа (тело+вложения) для кэша точного дубликата
 * (одинаковый текст → копируем распознанные параметры, модель не зовём). Nullable: у старых строк
 * NULL = без отпечатка (кэш их за близнецов не считает). Индекс — для резолва по хешу. Идемпотентно.
 */
final class Version20261120000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inquiry: колонка content_hash — отпечаток входа для кэша точного дубликата';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_inquiry_content_hash ON inquiry (content_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_inquiry_content_hash');
        $this->addSql('ALTER TABLE inquiry DROP COLUMN IF EXISTS content_hash');
    }
}

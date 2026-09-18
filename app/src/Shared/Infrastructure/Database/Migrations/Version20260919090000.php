<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Центральный реестр файлов (Shared/File). Одна таблица на все загрузки: tmp-стадия и привязанные.
 * Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260919090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create stored_file registry table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stored_file (
                id VARCHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL,
                purpose VARCHAR(64) DEFAULT NULL,
                owner_id VARCHAR(64) DEFAULT NULL,
                uploader_id VARCHAR(64) DEFAULT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(255) NOT NULL,
                extension VARCHAR(16) NOT NULL,
                size INT NOT NULL,
                storage_key VARCHAR(512) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_stored_file_owner ON stored_file (purpose, owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_stored_file_expires ON stored_file (status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS stored_file');
    }
}

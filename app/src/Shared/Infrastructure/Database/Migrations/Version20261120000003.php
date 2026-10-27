<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица запросов (Inquiries). Слепое типизированное хранилище: вход (текст + ссылки на файлы),
 * распознанные параметры и провенанс, снимок ответа, проверка — непрозрачные VO в jsonb-колонках.
 * Отпечаток/эмбеддинг кэша тут НЕ живут — это отдельная инфра-проекция (добавится задачей кэша).
 */
final class Version20261120000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inquiry: хранилище запросов (вход, распознанные параметры, снимок ответа, проверка)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS inquiry (
                id                      UUID         NOT NULL,
                type                    VARCHAR(30)  NOT NULL,
                owner_id                VARCHAR(26)  NOT NULL,
                raw_text                TEXT         NOT NULL,
                attachment_file_ids     JSONB        NOT NULL,
                status                  VARCHAR(20)  NOT NULL,
                recognized_parameters   JSONB        DEFAULT NULL,
                parameter_source        JSONB        DEFAULT NULL,
                answer                  JSONB        DEFAULT NULL,
                verification            JSONB        DEFAULT NULL,
                failure_reason          TEXT         DEFAULT NULL,
                warnings                JSONB        NOT NULL,
                created_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                version                 INT          NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_inquiry_type_status ON inquiry (type, status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_inquiry_owner ON inquiry (owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_inquiry_created_at ON inquiry (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS inquiry');
    }
}

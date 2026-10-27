<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица технического аудита исходящих HTTP-запросов (http_audit_log). Append-only
 * телеметрия: одна строка на КАЖДУЮ физическую попытку (ретраи — отдельными строками,
 * связанными correlation_id). Без FK и связей с доменом — это инфраструктурный след,
 * а не бизнес-данные. Пишется best-effort из декоратора HttpClientInterface.
 */
final class Version20261120000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'http_audit_log: таблица аудита исходящих HTTP-запросов (по попытке, correlation_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS http_audit_log (
                id                       BIGSERIAL   PRIMARY KEY,
                correlation_id           UUID        NOT NULL,
                attempt_number           SMALLINT    NOT NULL,
                created_at               TIMESTAMPTZ NOT NULL,
                client_scope             VARCHAR(64),
                method                   VARCHAR(10) NOT NULL,
                host                     VARCHAR(255) NOT NULL,
                path                     TEXT        NOT NULL,
                query                    TEXT,
                request_headers          JSONB,
                request_body             TEXT,
                request_body_truncated   BOOLEAN     NOT NULL DEFAULT FALSE,
                status_code              SMALLINT,
                response_headers         JSONB,
                response_body            TEXT,
                response_body_truncated  BOOLEAN     NOT NULL DEFAULT FALSE,
                duration_ms              INTEGER,
                error_class              VARCHAR(255),
                error_message            TEXT
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_http_audit_correlation ON http_audit_log (correlation_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_http_audit_created_at ON http_audit_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS http_audit_log');
    }
}

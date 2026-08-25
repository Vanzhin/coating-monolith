<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Контекст Certificates: таблица документов (заключения/сертификаты/протоколы).
 * Ссылки на владельцев — jsonb-коллекция owner_refs (GIN для containment).
 * Полнотекст — generated-колонка search_tsvector (GIN) по title/subject/description.
 * Идемпотентно.
 */
final class Version20260824130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificates_document (jsonb owner_refs + generated tsvector) with GIN indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS certificates_document (
                id UUID PRIMARY KEY,
                owner_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
                kind VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                issuer_id UUID NOT NULL,
                issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                subject TEXT NOT NULL,
                description TEXT DEFAULT NULL,
                test_standard VARCHAR(255) DEFAULT NULL,
                file VARCHAR(512) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                search_tsvector TSVECTOR GENERATED ALWAYS AS (
                    to_tsvector('russian',
                        coalesce(title, '') || ' ' || coalesce(subject, '') || ' ' || coalesce(description, ''))
                ) STORED
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_certificates_document_tsv
                ON certificates_document USING GIN (search_tsvector)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_certificates_document_refs
                ON certificates_document USING GIN (owner_refs jsonb_path_ops)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_certificates_document_issuer
                ON certificates_document (issuer_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_certificates_document_kind
                ON certificates_document (kind)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS certificates_document');
    }
}

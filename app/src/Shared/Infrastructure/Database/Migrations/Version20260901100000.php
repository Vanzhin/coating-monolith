<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Фикс полнотекстового поиска: нормализуем разделители в generated-векторе документа.
 *
 * Баг: `to_tsvector('russian', 'X-123')` трактует `-123` как ЗНАКОВОЕ число → лексема
 * '-123', тогда как поисковый билдер (PrefixTsQueryBuilder) бьёт строку по `[\s\-.,;]`
 * и ищет '123:*'. Для title вида «слово-цифры» (и sci-notation вроде '5e6') стороны
 * расходятся → документ не находится (флака в тестах, реальный баг в проде).
 *
 * Решение: перед to_tsvector переводим `-.,;` в пробелы (тот же набор, что режет билдер).
 * search_tsvector — STORED generated-колонка, менять выражение можно только через
 * пересоздание колонки (Postgres не даёт ALTER выражения). Идемпотентно.
 */
final class Version20260901100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FTS fix: normalize separators (-.,;) before to_tsvector in certificates_document.search_tsvector generated column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'certificates_document' AND column_name = 'search_tsvector'
                      AND generation_expression ILIKE '%translate%'
                ) THEN
                    DROP INDEX IF EXISTS idx_certificates_document_tsv;
                    ALTER TABLE certificates_document DROP COLUMN IF EXISTS search_tsvector;
                    ALTER TABLE certificates_document ADD COLUMN search_tsvector TSVECTOR GENERATED ALWAYS AS (
                        to_tsvector('russian', translate(
                            coalesce(title, '') || ' ' || coalesce(subject, '') || ' ' || coalesce(description, ''),
                            '-.,;', '    '))
                    ) STORED;
                    CREATE INDEX idx_certificates_document_tsv ON certificates_document USING GIN (search_tsvector);
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'certificates_document' AND column_name = 'search_tsvector'
                      AND generation_expression ILIKE '%translate%'
                ) THEN
                    DROP INDEX IF EXISTS idx_certificates_document_tsv;
                    ALTER TABLE certificates_document DROP COLUMN IF EXISTS search_tsvector;
                    ALTER TABLE certificates_document ADD COLUMN search_tsvector TSVECTOR GENERATED ALWAYS AS (
                        to_tsvector('russian',
                            coalesce(title, '') || ' ' || coalesce(subject, '') || ' ' || coalesce(description, ''))
                    ) STORED;
                    CREATE INDEX idx_certificates_document_tsv ON certificates_document USING GIN (search_tsvector);
                END IF;
            END $$;
        SQL);
    }
}

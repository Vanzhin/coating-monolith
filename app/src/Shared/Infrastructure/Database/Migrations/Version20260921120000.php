<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * JSON-колонки отчёта (ссылки-снимки + период работ) → jsonb, как content. json хранил текст как есть
 * (кириллица уезжала в \uXXXX от json_encode), jsonb нормализует значение (сырой UTF-8) и быстрее/
 * индексируем. Каст json::jsonb декодирует существующие escape'ы. Doctrine видит jsonb как тип json
 * (mapping_types), поэтому схема-diff не шумит.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report reference/interval JSON columns → jsonb (normalize unicode, match content).';
    }

    public function up(Schema $schema): void
    {
        foreach (['project', 'customer', 'contractor', 'system', 'work_period'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report ALTER COLUMN %1$s TYPE jsonb USING %1$s::jsonb', $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['project', 'customer', 'contractor', 'system', 'work_period'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report ALTER COLUMN %1$s TYPE json USING %1$s::json', $column));
        }
    }
}

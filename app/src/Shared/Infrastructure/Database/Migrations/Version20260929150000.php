<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Агрегат `Requirement` (context Compliance) — требование: имя, тип (материальное/нематериальное),
 * покрытые должности (jsonb-массив id, GIN-индекс под containment для Д3) и позиции (jsonb; тип позиции
 * не хранится — класс восстанавливается по колонке `type`). Идемпотентно; убирает прежнюю
 * `compliance_requirement_set` (модель до пересмотра: тип на строке).
 */
final class Version20260929150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create compliance_requirement table (typed requirement: name + type + positions + items).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement_set');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_requirement (
                id UUID NOT NULL,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(32) NOT NULL,
                position_ids JSONB NOT NULL,
                items JSONB NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_compliance_requirement_positions ON compliance_requirement USING GIN (position_ids)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: наименования позиций СИЗ (ГОСТ-названия) бывают длиннее 255 — расширяем проекцию, чтобы пересборка
 * не падала truncation'ом и не откатывалась. Домен ограничивает label 1000 символов; хранилище под это.
 * label/requirement_name → VARCHAR(1000); obligation_key (= requirementId|label) → VARCHAR(1100). Идемпотентно.
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: расширить label/requirement_name (1000) и obligation_key (1100) под длинные названия СИЗ.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ALTER COLUMN label TYPE VARCHAR(1000)');
        $this->addSql('ALTER TABLE compliance_tracked_obligation ALTER COLUMN requirement_name TYPE VARCHAR(1000)');
        $this->addSql('ALTER TABLE compliance_fulfillment_record ALTER COLUMN obligation_key TYPE VARCHAR(1100)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ALTER COLUMN label TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE compliance_tracked_obligation ALTER COLUMN requirement_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE compliance_fulfillment_record ALTER COLUMN obligation_key TYPE VARCHAR(320)');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: у факта выдачи появляются структурные поля инструктажа (instruction_details, jsonb) — заполняются
 * для не материального требования по схеме журнала. Nullable: у материальных СИЗ-фактов пусто. Идемпотентно.
 */
final class Version20261015140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_fulfillment_record.instruction_details (jsonb, nullable) — поля инструктажа.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS instruction_details JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS instruction_details');
    }
}

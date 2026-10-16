<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance T10: № карточки СИЗ становится свойством журнала — одна карточка на человека, хранится на
 * `ProfileCompliance` (`card_number`), не на акте (актов-документов больше нет). Nullable: на проде уже есть
 * карточки без номера — снятие nullable (NOT NULL) выделено в отдельный поздний деплой. Идемпотентно.
 */
final class Version20261020120700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance T10: card_number на compliance_profile_compliance (nullable) — № карточки СИЗ как свойство журнала.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_profile_compliance ADD COLUMN IF NOT EXISTS card_number VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_profile_compliance DROP COLUMN IF EXISTS card_number');
    }
}

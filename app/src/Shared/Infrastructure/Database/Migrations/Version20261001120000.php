<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д3 (T7): карточка = АКТ выдачи — у пары (человек × требование) их несколько (первичка +
 * продления). Снимаем уникальный индекс (profile_compliance_id, requirement_id). Идемпотентно.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д3 T7: per-act карточки (снятие UNIQUE профиль×требование).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_requirement_document_profile_requirement');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_requirement_document_profile_requirement ON compliance_requirement_document (profile_compliance_id, requirement_id)');
    }
}

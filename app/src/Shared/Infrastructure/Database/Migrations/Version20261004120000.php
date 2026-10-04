<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д7: у акта выдачи (личной карточки) появляются № карточки и ответственное лицо — вводятся при
 * оформлении, печатаются во вьюхе карточки (как № и комиссия у акта списания). Идемпотентно.
 */
final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_requirement_document.act_number + responsible_fio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_document ADD COLUMN IF NOT EXISTS act_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_requirement_document ADD COLUMN IF NOT EXISTS responsible_fio VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_document DROP COLUMN IF EXISTS responsible_fio');
        $this->addSql('ALTER TABLE compliance_requirement_document DROP COLUMN IF EXISTS act_number');
    }
}

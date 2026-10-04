<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: у акта списания появляются реквизиты приказа (о создании комиссии) и представитель отдела ОТ и ПБ
 * — для вьюхи-документа. Все nullable, заполняются при сохранении/оформлении. Идемпотентно.
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_write_off_act — order_number/order_date + representative_position/fio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_write_off_act ADD COLUMN IF NOT EXISTS order_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_write_off_act ADD COLUMN IF NOT EXISTS order_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_write_off_act ADD COLUMN IF NOT EXISTS representative_position VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_write_off_act ADD COLUMN IF NOT EXISTS representative_fio VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_write_off_act DROP COLUMN IF EXISTS representative_fio');
        $this->addSql('ALTER TABLE compliance_write_off_act DROP COLUMN IF EXISTS representative_position');
        $this->addSql('ALTER TABLE compliance_write_off_act DROP COLUMN IF EXISTS order_date');
        $this->addSql('ALTER TABLE compliance_write_off_act DROP COLUMN IF EXISTS order_number');
    }
}

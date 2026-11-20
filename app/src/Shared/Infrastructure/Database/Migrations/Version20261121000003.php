<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: один черновик путевого листа на автомобиль';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_vehicle_draft ON waybills_waybill (vehicle_id) WHERE locked = FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_waybills_vehicle_draft');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261121000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: утверждение использует журнал топлива и одну сверку на лист';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waybills_waybill DROP CONSTRAINT IF EXISTS chk_waybills_waybill_sealed');
        $this->addSql(<<<'SQL'
            ALTER TABLE waybills_waybill ADD CONSTRAINT chk_waybills_waybill_sealed CHECK (
                (NOT locked AND approved_driver IS NULL AND approved_base IS NULL
                    AND approved_vehicle_model IS NULL AND approved_registration_number IS NULL
                    AND approved_consumption_rate IS NULL)
                OR (locked AND route_present AND odometer_end IS NOT NULL AND fuel IS NOT NULL AND fuel->>'remaining' IS NOT NULL
                    AND approved_driver IS NOT NULL AND approved_base IS NOT NULL
                    AND approved_vehicle_model IS NOT NULL AND approved_registration_number IS NOT NULL)
            )
            SQL);
        $this->addSql('ALTER TABLE waybills_fuel_operation DROP CONSTRAINT IF EXISTS chk_waybills_fuel_operation_type');
        $this->addSql(<<<'SQL'
            ALTER TABLE waybills_fuel_operation ADD CONSTRAINT chk_waybills_fuel_operation_type CHECK (
                (type = 'refueling' AND amount > 0 AND waybill_id IS NULL)
                OR (type = 'reconciliation' AND waybill_id IS NOT NULL AND draft_waybill_id IS NULL)
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_fuel_operation_reconciliation ON waybills_fuel_operation (waybill_id) WHERE type = 'reconciliation'");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Нельзя восстановить прежнее ограничение без учёта журнала топливных операций.');
    }
}

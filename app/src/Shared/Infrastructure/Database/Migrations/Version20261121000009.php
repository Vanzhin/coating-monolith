<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261121000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: журнал топливных операций автомобиля';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_fuel_operation (
                id UUID PRIMARY KEY,
                vehicle_id UUID NOT NULL REFERENCES waybills_vehicle(id),
                occurred_at TEXT NOT NULL CHECK (strpos(occurred_at, '|') > 0),
                fuel_type TEXT NOT NULL,
                amount NUMERIC NOT NULL CHECK (amount > '-Infinity'::numeric AND amount < 'Infinity'::numeric),
                type TEXT NOT NULL CHECK (type IN ('refueling', 'reconciliation')),
                waybill_id UUID DEFAULT NULL,
                draft_waybill_id UUID DEFAULT NULL,
                position INTEGER NOT NULL DEFAULT 0 CHECK (position >= 0),
                CONSTRAINT chk_waybills_fuel_operation_type CHECK (
                    (type = 'refueling' AND amount > 0)
                    OR (type = 'reconciliation' AND waybill_id IS NOT NULL AND draft_waybill_id IS NULL)
                ),
                CONSTRAINT uniq_waybills_fuel_operation_draft_position UNIQUE (draft_waybill_id, position) DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT fk_waybills_fuel_operation_waybill FOREIGN KEY (waybill_id, vehicle_id)
                    REFERENCES waybills_waybill (id, vehicle_id) DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT fk_waybills_fuel_operation_draft FOREIGN KEY (draft_waybill_id, vehicle_id)
                    REFERENCES waybills_waybill (id, vehicle_id) DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_fuel_operation_vehicle_date ON waybills_fuel_operation (vehicle_id, occurred_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_fuel_operation_draft ON waybills_fuel_operation (draft_waybill_id) WHERE draft_waybill_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Удаление журнала топливных операций привело бы к потере данных.');
    }
}

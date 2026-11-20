<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261121000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: виды топлива хранятся только в автомобиле';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                LOCK TABLE waybills_waybill IN ACCESS EXCLUSIVE MODE;
                IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'waybills_waybill'::regclass AND attname = 'approved_fuel_types' AND NOT attisdropped) THEN
                    IF EXISTS (SELECT 1 FROM waybills_waybill WHERE approved_fuel_types IS DISTINCT FROM '[]'::jsonb) THEN
                        RAISE EXCEPTION 'Найдены сохранённые виды топлива утверждённых листов: требуется отдельное решение о переносе данных';
                    END IF;
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE waybills_waybill DROP CONSTRAINT IF EXISTS chk_waybills_waybill_sealed');
        $this->addSql(<<<'SQL'
            ALTER TABLE waybills_waybill ADD CONSTRAINT chk_waybills_waybill_sealed CHECK (
                (NOT locked AND approved_driver IS NULL AND approved_base IS NULL
                    AND approved_vehicle_model IS NULL AND approved_registration_number IS NULL
                    AND approved_consumption_rate IS NULL)
                OR (locked AND route_present AND odometer_end IS NOT NULL AND fuel IS NOT NULL AND fuel->>'remaining' IS NOT NULL AND fuel->'refuelings' IS NOT NULL AND fuel->'refuelings' <> 'null'::jsonb
                    AND approved_driver IS NOT NULL AND approved_base IS NOT NULL
                    AND approved_vehicle_model IS NOT NULL AND approved_registration_number IS NOT NULL)
            )
            SQL);
        $this->addSql('ALTER TABLE waybills_waybill DROP COLUMN IF EXISTS approved_fuel_types');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Нельзя восстановить ранее не фиксировавшиеся виды топлива утверждённых листов.');
    }
}

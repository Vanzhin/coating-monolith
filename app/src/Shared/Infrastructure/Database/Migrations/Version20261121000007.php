<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261121000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: ORM-хранение листа и точных дат журнала поездок';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                old_waybill_format BOOLEAN;
                old_trip_date BOOLEAN;
            BEGIN
                LOCK TABLE waybills_waybill, waybills_route_log IN ACCESS EXCLUSIVE MODE;

                SELECT EXISTS (
                    SELECT 1 FROM pg_attribute
                    WHERE attrelid = 'waybills_waybill'::regclass
                        AND attname = 'snapshot' AND NOT attisdropped
                ) OR EXISTS (
                    SELECT 1 FROM pg_attribute
                    WHERE attrelid = 'waybills_waybill'::regclass
                        AND attname IN ('period_from', 'period_to') AND NOT attisdropped
                        AND atttypid <> 'text'::regtype
                ) INTO old_waybill_format;

                SELECT EXISTS (
                    SELECT 1 FROM pg_attribute
                    WHERE attrelid = 'waybills_route_log'::regclass
                        AND attname = 'occurred_at' AND NOT attisdropped
                        AND atttypid <> 'text'::regtype
                ) INTO old_trip_date;

                IF old_waybill_format AND EXISTS (SELECT 1 FROM waybills_waybill) THEN
                    RAISE EXCEPTION 'Найдены старые путевые листы: требуется отдельный перенос данных';
                END IF;
                IF old_trip_date AND EXISTS (SELECT 1 FROM waybills_route_log) THEN
                    RAISE EXCEPTION 'Найдены поездки без исходной часовой зоны: требуется отдельный перенос данных';
                END IF;
            END $$
            SQL);

        $this->addSql('ALTER TABLE waybills_waybill DROP CONSTRAINT IF EXISTS waybills_waybill_check');
        $this->addSql('ALTER TABLE waybills_driver_profile ADD COLUMN IF NOT EXISTS next_waybill_number BIGINT NOT NULL DEFAULT 1 CHECK (next_waybill_number > 0)');
        $this->addSql('ALTER TABLE waybills_waybill ALTER COLUMN period_from TYPE TEXT USING period_from::text');
        $this->addSql('ALTER TABLE waybills_waybill ALTER COLUMN period_to TYPE TEXT USING period_to::text');
        $this->addSql('ALTER TABLE waybills_waybill DROP COLUMN IF EXISTS owner_id');
        $this->addSql('ALTER TABLE waybills_waybill DROP COLUMN IF EXISTS snapshot');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS number INTEGER NOT NULL CHECK (number > 0)');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS route_present BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS odometer_start BIGINT NOT NULL CHECK (odometer_start >= 0)');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS odometer_end BIGINT DEFAULT NULL CHECK (odometer_end >= 0)');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS fuel_start NUMERIC NOT NULL CHECK (fuel_start >= 0)');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS fuel JSONB DEFAULT NULL');
        $this->addSql("ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS absences JSONB NOT NULL DEFAULT '[]'::jsonb");
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_driver JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_base JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_vehicle_model TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_registration_number TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_fuel_types JSONB NOT NULL DEFAULT '[]'::jsonb");
        $this->addSql('ALTER TABLE waybills_waybill ADD COLUMN IF NOT EXISTS approved_consumption_rate JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE waybills_waybill DROP CONSTRAINT IF EXISTS chk_waybills_waybill_period');
        $this->addSql(<<<'SQL'
            ALTER TABLE waybills_waybill ADD CONSTRAINT chk_waybills_waybill_period CHECK (
                strpos(period_from, '|') > 0 AND strpos(period_to, '|') > 0
                AND split_part(period_from, '|', 1)::timestamptz <= split_part(period_to, '|', 1)::timestamptz
            )
            SQL);
        $this->addSql('ALTER TABLE waybills_waybill DROP CONSTRAINT IF EXISTS chk_waybills_waybill_sealed');
        $this->addSql(<<<'SQL'
            ALTER TABLE waybills_waybill ADD CONSTRAINT chk_waybills_waybill_sealed CHECK (
                (NOT locked AND approved_driver IS NULL AND approved_base IS NULL
                    AND approved_vehicle_model IS NULL AND approved_registration_number IS NULL
                    AND approved_fuel_types = '[]'::jsonb AND approved_consumption_rate IS NULL)
                OR (locked AND route_present AND odometer_end IS NOT NULL AND fuel IS NOT NULL AND fuel->>'remaining' IS NOT NULL AND fuel->'refuelings' IS NOT NULL AND fuel->'refuelings' <> 'null'::jsonb
                    AND approved_driver IS NOT NULL AND approved_base IS NOT NULL
                    AND approved_vehicle_model IS NOT NULL AND approved_registration_number IS NOT NULL
                    AND jsonb_typeof(approved_fuel_types) = 'array' AND jsonb_array_length(approved_fuel_types) > 0)
            )
            SQL);

        $this->addSql('ALTER TABLE waybills_route_log DROP CONSTRAINT IF EXISTS waybills_route_log_occurred_at_check');
        $this->addSql('ALTER TABLE waybills_route_log ALTER COLUMN occurred_at TYPE TEXT USING occurred_at::text');
        $this->addSql('ALTER TABLE waybills_route_log DROP CONSTRAINT IF EXISTS chk_waybills_route_log_exact_date');
        $this->addSql("ALTER TABLE waybills_route_log ADD CONSTRAINT chk_waybills_route_log_exact_date CHECK (strpos(occurred_at, '|') > 0)");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Новый формат листов нельзя преобразовать в старый JSON без потери данных.');
    }
}

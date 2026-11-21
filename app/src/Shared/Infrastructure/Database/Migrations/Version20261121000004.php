<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: журнал поездок автомобилей';
    }

    public function up(Schema $schema): void
    {
        // Составной ключ проверяет автомобиль; статус листа проверяется при операции.
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_waybill_id_vehicle ON waybills_waybill (id, vehicle_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_route_log (
                id UUID PRIMARY KEY,
                vehicle_id UUID NOT NULL REFERENCES waybills_vehicle(id),
                previous_id UUID DEFAULT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL CHECK (isfinite(occurred_at)),
                location JSONB NOT NULL,
                enterprise TEXT DEFAULT NULL,
                distance DOUBLE PRECISION NOT NULL CHECK (distance > 0 AND distance < 'Infinity'::double precision),
                origin TEXT NOT NULL CHECK (origin IN ('manual', 'automatic')),
                draft_waybill_id UUID DEFAULT NULL,
                CONSTRAINT uniq_waybills_route_log_id_vehicle UNIQUE (id, vehicle_id),
                CONSTRAINT uniq_waybills_route_log_previous UNIQUE NULLS NOT DISTINCT (vehicle_id, previous_id) DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT fk_waybills_route_log_previous FOREIGN KEY (previous_id, vehicle_id)
                    REFERENCES waybills_route_log (id, vehicle_id) DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT fk_waybills_route_log_draft FOREIGN KEY (draft_waybill_id, vehicle_id)
                    REFERENCES waybills_waybill (id, vehicle_id) DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT chk_waybills_route_log_not_self CHECK (previous_id IS NULL OR previous_id <> id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_route_log_previous ON waybills_route_log (previous_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_route_log_vehicle_date ON waybills_route_log (vehicle_id, occurred_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_route_log_draft ON waybills_route_log (draft_waybill_id) WHERE draft_waybill_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waybills_route_log');
        $this->addSql('DROP INDEX IF EXISTS uniq_waybills_waybill_id_vehicle');
    }
}

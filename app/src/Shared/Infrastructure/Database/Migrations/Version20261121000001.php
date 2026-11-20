<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: хранение самостоятельных путевых листов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_waybill (
                id UUID PRIMARY KEY,
                owner_id TEXT NOT NULL,
                vehicle_id UUID NOT NULL REFERENCES waybills_vehicle(id),
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                locked BOOLEAN NOT NULL,
                version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
                snapshot JSONB NOT NULL,
                CHECK (period_from <= period_to)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_waybill_vehicle_period ON waybills_waybill (vehicle_id, period_from, period_to)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_waybill_owner_locked ON waybills_waybill (owner_id, locked)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waybills_waybill');
    }
}

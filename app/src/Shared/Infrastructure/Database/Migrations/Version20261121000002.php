<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: карточка водителя и текущий водитель автомобиля';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_driver_profile (
                id UUID PRIMARY KEY,
                owner_id TEXT NOT NULL CONSTRAINT uniq_waybills_driver_profile_owner UNIQUE,
                driver_details JSONB NOT NULL,
                route_settings JSONB NOT NULL
            )
            SQL);
        $this->addSql('ALTER TABLE waybills_vehicle ADD COLUMN IF NOT EXISTS driver_id UUID DEFAULT NULL REFERENCES waybills_driver_profile(id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_vehicle_driver ON waybills_vehicle (driver_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waybills_vehicle DROP COLUMN IF EXISTS driver_id');
        $this->addSql('DROP TABLE IF EXISTS waybills_driver_profile');
    }
}

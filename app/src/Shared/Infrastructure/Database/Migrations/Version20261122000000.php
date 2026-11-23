<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: справочник точек маршрута с предприятием, типом населённого пункта и координатами';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_location (
                id UUID NOT NULL PRIMARY KEY,
                owner_id TEXT NOT NULL,
                country TEXT NOT NULL,
                city TEXT NOT NULL,
                city_type TEXT NULL CHECK (city_type IN ('village', 'selo', 'settlement', 'khutor', 'urban_settlement', 'city')),
                street TEXT NOT NULL,
                house TEXT NOT NULL,
                enterprise TEXT NULL,
                latitude DOUBLE PRECISION NULL CHECK (latitude BETWEEN -90 AND 90),
                longitude DOUBLE PRECISION NULL CHECK (longitude BETWEEN -180 AND 180),
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                CHECK ((latitude IS NULL) = (longitude IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_waybills_location_owner ON waybills_location (owner_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_location_address ON waybills_location (
                owner_id, country, city, COALESCE(city_type, ''), street, house,
                COALESCE(enterprise, ''), COALESCE(latitude, 999::double precision), COALESCE(longitude, 999::double precision)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waybills_location');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: самостоятельный автомобиль с уникальным госномером и последними показаниями';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waybills_vehicle (
                id UUID NOT NULL PRIMARY KEY,
                model TEXT NOT NULL,
                registration_number TEXT NOT NULL CHECK (
                    registration_number ~ '^[abekmhopctyx][0-9]{3}[abekmhopctyx]{2}[0-9]{2,3}$'
                    AND length(registration_number) IN (8, 9)
                    AND substring(registration_number from 2 for 3) <> '000'
                ),
                fuel_types JSONB NOT NULL,
                default_fuel_type TEXT NOT NULL,
                initial_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                initial_location JSONB NOT NULL,
                initial_odometer DOUBLE PRECISION NOT NULL CHECK (initial_odometer >= 0),
                initial_fuel_remaining NUMERIC NOT NULL CHECK (initial_fuel_remaining >= 0),
                current_odometer DOUBLE PRECISION NOT NULL CHECK (current_odometer >= 0),
                fuel_remaining NUMERIC NOT NULL CHECK (fuel_remaining >= 0),
                consumption_rate JSONB DEFAULT NULL,
                CHECK (jsonb_exists(fuel_types, default_fuel_type))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_waybills_vehicle_registration_number ON waybills_vehicle (registration_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waybills_vehicle');
    }
}

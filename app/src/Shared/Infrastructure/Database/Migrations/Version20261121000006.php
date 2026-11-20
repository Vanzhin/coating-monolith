<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: целые показания одометра автомобиля';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM waybills_vehicle
                    WHERE initial_odometer <> trunc(initial_odometer)
                        OR current_odometer <> trunc(current_odometer)
                        OR initial_odometer >= 9223372036854775808
                        OR current_odometer >= 9223372036854775808
                ) THEN
                    RAISE EXCEPTION 'Нельзя преобразовать дробное или слишком большое показание одометра в целые километры';
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE waybills_vehicle ALTER COLUMN initial_odometer TYPE BIGINT USING initial_odometer::bigint');
        $this->addSql('ALTER TABLE waybills_vehicle ALTER COLUMN current_odometer TYPE BIGINT USING current_odometer::bigint');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM waybills_vehicle
                    WHERE initial_odometer > 9007199254740992
                        OR current_odometer > 9007199254740992
                ) THEN
                    RAISE EXCEPTION 'Нельзя откатить целые показания одометра без риска потери точности';
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE waybills_vehicle ALTER COLUMN initial_odometer TYPE DOUBLE PRECISION USING initial_odometer::double precision');
        $this->addSql('ALTER TABLE waybills_vehicle ALTER COLUMN current_odometer TYPE DOUBLE PRECISION USING current_odometer::double precision');
    }
}

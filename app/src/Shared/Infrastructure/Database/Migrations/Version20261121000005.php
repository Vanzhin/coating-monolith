<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: целые километры поездок';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM waybills_route_log
                    WHERE distance <> trunc(distance) OR distance > 2147483647
                ) THEN
                    RAISE EXCEPTION 'Нельзя преобразовать дробное или слишком большое расстояние поездки в целые километры';
                END IF;
            END $$
            SQL);
        $this->addSql('ALTER TABLE waybills_route_log DROP CONSTRAINT IF EXISTS waybills_route_log_distance_check');
        $this->addSql('ALTER TABLE waybills_route_log ALTER COLUMN distance TYPE INTEGER USING distance::integer');
        $this->addSql('ALTER TABLE waybills_route_log ADD CONSTRAINT waybills_route_log_distance_check CHECK (distance > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waybills_route_log DROP CONSTRAINT IF EXISTS waybills_route_log_distance_check');
        $this->addSql('ALTER TABLE waybills_route_log ALTER COLUMN distance TYPE DOUBLE PRECISION USING distance::double precision');
        $this->addSql("ALTER TABLE waybills_route_log ADD CONSTRAINT waybills_route_log_distance_check CHECK (distance > 0 AND distance < 'Infinity'::double precision)");
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make uniq_csl_system_position deferrable to allow position shifts during insert/move operations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE coating_system_layer
                DROP CONSTRAINT IF EXISTS uniq_csl_system_position
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE coating_system_layer
                ADD CONSTRAINT uniq_csl_system_position
                UNIQUE (system_id, position)
                DEFERRABLE INITIALLY DEFERRED
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            ALTER TABLE coating_system_layer
                DROP CONSTRAINT IF EXISTS uniq_csl_system_position
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE coating_system_layer
                ADD CONSTRAINT uniq_csl_system_position
                UNIQUE (system_id, position)
        SQL);
    }
}

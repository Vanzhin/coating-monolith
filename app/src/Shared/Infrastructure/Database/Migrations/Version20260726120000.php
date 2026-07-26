<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coating.is_zinc_rich column for ISO 12944 compliance evaluation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS is_zinc_rich BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS is_zinc_rich');
    }
}

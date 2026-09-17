<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Оптимистичная блокировка покрытий (Doctrine @Version). Идемпотентно. */
final class Version20260916122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optimistic-lock version to coatings_coating.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS version');
    }
}

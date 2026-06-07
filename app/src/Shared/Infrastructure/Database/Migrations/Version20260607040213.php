<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Делает coatings_coating.max_recoating_interval опциональным.
 * null означает «верхней границы перекрытия нет» (специальная подготовка
 * перед нанесением следующего слоя не требуется).
 */
final class Version20260607040213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow NULL in coatings_coating.max_recoating_interval (no upper recoating boundary).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN max_recoating_interval DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE coatings_coating SET max_recoating_interval = 0 WHERE max_recoating_interval IS NULL');
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN max_recoating_interval SET NOT NULL');
    }
}

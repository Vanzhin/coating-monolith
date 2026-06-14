<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608171633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize coatings_coating.max_recoating_interval = 0 to NULL (no upper bound).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE coatings_coating SET max_recoating_interval = NULL WHERE max_recoating_interval = 0');
    }

    public function down(Schema $schema): void
    {
        // Без обратной миграции: NULL ↔ 0 не различимы для бизнеса, восстанавливать нечего.
    }
}

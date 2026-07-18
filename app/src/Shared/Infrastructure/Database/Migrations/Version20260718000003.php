<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260718000003 extends AbstractChemicalResistanceSeedMigration
{
    public function getDescription(): string
    {
        return 'Seed chemical resistance: Литатанк Классик';
    }

    protected function seedFileName(): string
    {
        return 'litatank_classic.json';
    }
}

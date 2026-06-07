<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Расширяет fuzzy-fallback на coatings_coating.description — иначе опечатки внутри слов,
 * которые лежат в описании («полеуретан» вместо «полиуретан»), не находятся.
 */
final class Version20260607081726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pg_trgm GIN index on coatings_coating.description for fuzzy fallback.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX coatings_coating_description_trgm_idx ON coatings_coating USING gin (description gin_trgm_ops)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS coatings_coating_description_trgm_idx');
    }
}

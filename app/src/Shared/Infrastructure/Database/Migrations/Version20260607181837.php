<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607181837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increase coatings_coating.description length from 750 to 1500.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER coatings_coating_search_upsert_trigger ON coatings_coating');
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN description TYPE VARCHAR(1500)');
        $this->addSql(
            <<<'SQL'
            CREATE TRIGGER coatings_coating_search_upsert_trigger
            AFTER INSERT OR UPDATE OF title, description ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_upsert()
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER coatings_coating_search_upsert_trigger ON coatings_coating');
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN description TYPE VARCHAR(750)');
        $this->addSql(
            <<<'SQL'
            CREATE TRIGGER coatings_coating_search_upsert_trigger
            AFTER INSERT OR UPDATE OF title, description ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_upsert()
            SQL
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241120154649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coatings_coating ALTER mass_density TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ALTER dry_to_touch TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ALTER min_recoating_interval TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ALTER max_recoating_interval TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ALTER full_cure TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE coatings_coating ALTER pack TYPE DOUBLE PRECISION');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE coatings_coating ALTER mass_density TYPE INT');
        $this->addSql('ALTER TABLE coatings_coating ALTER dry_to_touch TYPE INT');
        $this->addSql('ALTER TABLE coatings_coating ALTER min_recoating_interval TYPE INT');
        $this->addSql('ALTER TABLE coatings_coating ALTER max_recoating_interval TYPE INT');
        $this->addSql('ALTER TABLE coatings_coating ALTER full_cure TYPE INT');
        $this->addSql('ALTER TABLE coatings_coating ALTER pack TYPE INT');
    }
}

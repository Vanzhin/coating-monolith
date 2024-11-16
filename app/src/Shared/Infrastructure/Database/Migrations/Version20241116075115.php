<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241116075115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coatings_coating_coating_tag (coating_id VARCHAR(36) NOT NULL, tag_id VARCHAR(36) NOT NULL, PRIMARY KEY(coating_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_E56FDFB768EE894B ON coatings_coating_coating_tag (coating_id)');
        $this->addSql('CREATE INDEX IDX_E56FDFB7BAD26311 ON coatings_coating_coating_tag (tag_id)');
        $this->addSql('CREATE TABLE coatings_coating_tag (id VARCHAR(36) NOT NULL, title VARCHAR(100) NOT NULL, type VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B1448BD92B36786B8CDE5729 ON coatings_coating_tag (title, type)');
        $this->addSql('ALTER TABLE coatings_coating_coating_tag ADD CONSTRAINT FK_E56FDFB768EE894B FOREIGN KEY (coating_id) REFERENCES coatings_coating (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coatings_coating_coating_tag ADD CONSTRAINT FK_E56FDFB7BAD26311 FOREIGN KEY (tag_id) REFERENCES coatings_coating_tag (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coatings_coating ADD mass_density INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD tds_dft INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD min_dft INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD max_dft INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD application_min_temp INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD dry_to_touch INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD min_recoating_interval INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD max_recoating_interval INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD full_cure INT NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP protection_type');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coatings_coating_coating_tag DROP CONSTRAINT FK_E56FDFB768EE894B');
        $this->addSql('ALTER TABLE coatings_coating_coating_tag DROP CONSTRAINT FK_E56FDFB7BAD26311');
        $this->addSql('DROP TABLE coatings_coating_coating_tag');
        $this->addSql('DROP TABLE coatings_coating_tag');
        $this->addSql('ALTER TABLE coatings_coating ADD protection_type VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP mass_density');
        $this->addSql('ALTER TABLE coatings_coating DROP tds_dft');
        $this->addSql('ALTER TABLE coatings_coating DROP min_dft');
        $this->addSql('ALTER TABLE coatings_coating DROP max_dft');
        $this->addSql('ALTER TABLE coatings_coating DROP application_min_temp');
        $this->addSql('ALTER TABLE coatings_coating DROP dry_to_touch');
        $this->addSql('ALTER TABLE coatings_coating DROP min_recoating_interval');
        $this->addSql('ALTER TABLE coatings_coating DROP max_recoating_interval');
        $this->addSql('ALTER TABLE coatings_coating DROP full_cure');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241103092831 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coatings_coating (id VARCHAR(36) NOT NULL, manufacturer_id VARCHAR(36) NOT NULL, title VARCHAR(100) NOT NULL, description VARCHAR(750) DEFAULT NULL, volume_solid INT NOT NULL, protection_type VARCHAR(50) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2C0CA67B2B36786B ON coatings_coating (title)');
        $this->addSql('CREATE INDEX IDX_2C0CA67BA23B42D ON coatings_coating (manufacturer_id)');
        $this->addSql('CREATE TABLE coatings_manufacturer (id VARCHAR(36) NOT NULL, title VARCHAR(50) NOT NULL, description VARCHAR(750) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_588240382B36786B ON coatings_manufacturer (title)');
        $this->addSql('ALTER TABLE coatings_coating ADD CONSTRAINT FK_2C0CA67BA23B42D FOREIGN KEY (manufacturer_id) REFERENCES coatings_manufacturer (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coatings_coating DROP CONSTRAINT FK_2C0CA67BA23B42D');
        $this->addSql('DROP TABLE coatings_coating');
        $this->addSql('DROP TABLE coatings_manufacturer');
    }
}

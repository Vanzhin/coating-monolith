<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241201081050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE proposal_general_proposal (id VARCHAR(36) NOT NULL, number VARCHAR(100) NOT NULL, description VARCHAR(750) DEFAULT NULL, basis VARCHAR(750) DEFAULT NULL, owner_id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, unit VARCHAR(25) NOT NULL, project_title VARCHAR(750) NOT NULL, project_area DOUBLE PRECISION NOT NULL, project_structure_description VARCHAR(750) NOT NULL, loss INT NOT NULL, durability VARCHAR(50) DEFAULT NULL, category VARCHAR(100) DEFAULT NULL, treatment VARCHAR(500) DEFAULT NULL, method VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DE51A16496901F54 ON proposal_general_proposal (number)');
        $this->addSql('CREATE TABLE proposal_general_proposal_item (id VARCHAR(36) NOT NULL, proposal_id VARCHAR(36) DEFAULT NULL, coat_id VARCHAR(36) NOT NULL, coat_number INT NOT NULL, coat_price DOUBLE PRECISION NOT NULL, coat_dft INT NOT NULL, coat_color VARCHAR(50) NOT NULL, thinner_price DOUBLE PRECISION NOT NULL, thinner_consumption INT NOT NULL, loss INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A99E56FEF4792058 ON proposal_general_proposal_item (proposal_id)');
        $this->addSql('ALTER TABLE proposal_general_proposal_item ADD CONSTRAINT FK_A99E56FEF4792058 FOREIGN KEY (proposal_id) REFERENCES proposal_general_proposal (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE proposal_general_proposal_item DROP CONSTRAINT FK_A99E56FEF4792058');
        $this->addSql('DROP TABLE proposal_general_proposal');
        $this->addSql('DROP TABLE proposal_general_proposal_item');
    }
}

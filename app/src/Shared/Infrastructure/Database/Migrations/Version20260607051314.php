<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Переводит coatings_coating.id и связанный FK coatings_coating_coating_tag.coating_id
 * с varchar(36) на нативный PostgreSQL-тип uuid.
 */
final class Version20260607051314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert coatings_coating.id (and FK coating_id in join table) from varchar(36) to native uuid.';
    }

    public function up(Schema $schema): void
    {
        // FK не даёт менять типы по отдельности — снимаем, меняем оба, восстанавливаем
        $this->addSql('ALTER TABLE coatings_coating_coating_tag DROP CONSTRAINT fk_e56fdfb768ee894b');

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN id TYPE uuid USING id::uuid');
        $this->addSql('ALTER TABLE coatings_coating_coating_tag ALTER COLUMN coating_id TYPE uuid USING coating_id::uuid');

        $this->addSql(
            'ALTER TABLE coatings_coating_coating_tag
             ADD CONSTRAINT fk_e56fdfb768ee894b FOREIGN KEY (coating_id) REFERENCES coatings_coating(id) NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating_coating_tag DROP CONSTRAINT fk_e56fdfb768ee894b');

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN id TYPE varchar(36) USING id::text');
        $this->addSql('ALTER TABLE coatings_coating_coating_tag ALTER COLUMN coating_id TYPE varchar(36) USING coating_id::text');

        $this->addSql(
            'ALTER TABLE coatings_coating_coating_tag
             ADD CONSTRAINT fk_e56fdfb768ee894b FOREIGN KEY (coating_id) REFERENCES coatings_coating(id) NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
    }
}

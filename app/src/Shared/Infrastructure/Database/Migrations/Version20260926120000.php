<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FK user_channel.owner_id → user_user.ulid: NO ACTION → ON DELETE CASCADE. Канал принадлежит юзеру —
 * удаление юзера должно сносить его каналы уведомлений на уровне БД (иначе FK-violation при удалении
 * юзера, в т.ч. шум в tearDown функциональных тестов). Идемпотентно.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_channel.owner_id FK → ON DELETE CASCADE (канал следует за удалением юзера).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_channel DROP CONSTRAINT IF EXISTS fk_faf4904d7e3c61f9');
        $this->addSql('ALTER TABLE user_channel ADD CONSTRAINT fk_faf4904d7e3c61f9 FOREIGN KEY (owner_id) REFERENCES user_user (ulid) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_channel DROP CONSTRAINT IF EXISTS fk_faf4904d7e3c61f9');
        $this->addSql('ALTER TABLE user_channel ADD CONSTRAINT fk_faf4904d7e3c61f9 FOREIGN KEY (owner_id) REFERENCES user_user (ulid)');
    }
}

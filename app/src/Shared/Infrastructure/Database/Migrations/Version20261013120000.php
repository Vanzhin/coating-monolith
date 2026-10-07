<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Уведомления: таблица подписок пользователя на типы событий по каналам (user×type×channel→enabled).
 * Идемпотентно (IF NOT EXISTS). Уникальность по тройке (пользователь, тип, канал).
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications: notification_subscription (user_ulid, type, channel, enabled) + uniq.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS notification_subscription (
            id UUID NOT NULL,
            user_ulid VARCHAR(64) NOT NULL,
            type VARCHAR(64) NOT NULL,
            channel VARCHAR(16) NOT NULL,
            enabled BOOLEAN NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_notif_sub ON notification_subscription (user_ulid, type, channel)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notification_subscription');
    }
}

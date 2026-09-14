<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Инбокс уведомлений: таблица notification (уведомления приложения на пользователя со статусом
 * прочтения). owner_id — ulid юзера (FK на user_user.ulid, без ORM-связи). Индекс (owner_id, is_read)
 * — под подсчёт непрочитанного для бейджа. Идемпотентно (IF NOT EXISTS).
 */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification table (app notifications inbox with read state).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notification (
                id UUID NOT NULL,
                owner_id VARCHAR(26) NOT NULL,
                message TEXT NOT NULL,
                is_read BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_notification_owner_unread ON notification (owner_id, is_read)');
        $this->addSql("COMMENT ON COLUMN notification.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN notification.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN notification.read_at IS '(DC2Type:datetime_immutable)'");
        // FK-владелец на ulid (как user_channel.owner_id). Отдельно, чтобы не падать, если constraint уже есть.
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_notification_owner') THEN
                    ALTER TABLE notification ADD CONSTRAINT fk_notification_owner
                        FOREIGN KEY (owner_id) REFERENCES user_user (ulid) NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE IF EXISTS notification DROP CONSTRAINT IF EXISTS fk_notification_owner');
        $this->addSql('DROP TABLE IF EXISTS notification');
    }
}

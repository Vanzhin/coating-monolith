<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: убрана система антифлуда прохода уведомлений (маркер состояния) в этом исполнении — удаляем
 * таблицу compliance_due_notification_state. Проход теперь отдаёт текущее состояние без маркера; антифлуд
 * вернём отдельной реализацией, когда определимся с политикой.
 */
final class Version20261016130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: удалить compliance_due_notification_state (антифлуд-маркер снят).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_due_notification_state');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS compliance_due_notification_state (
            id UUID NOT NULL,
            profile_id VARCHAR(36) NOT NULL,
            obligation_key VARCHAR(1100) NOT NULL,
            bucket VARCHAR(16) NOT NULL,
            last_notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_due_notify_state ON compliance_due_notification_state (profile_id, obligation_key)');
    }
}

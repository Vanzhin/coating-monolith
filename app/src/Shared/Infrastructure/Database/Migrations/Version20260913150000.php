<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Web push: канал WEB_PUSH хранит в user_channel.value JSON подписки браузера
 * ({endpoint, keys}), который не влезает в VARCHAR(255). Расширяем колонку до TEXT.
 * Идемпотентно: ALTER ... TYPE TEXT безопасно при повторном прогоне.
 */
final class Version20260913150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen user_channel.value to TEXT to fit web push subscription JSON.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_channel ALTER COLUMN value TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_channel ALTER COLUMN value TYPE VARCHAR(255)');
    }
}

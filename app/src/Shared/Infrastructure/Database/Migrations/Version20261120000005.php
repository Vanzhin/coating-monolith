<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * inquiry: свободная заметка инженера (comment). Редактируется в любой момент, к решению не относится.
 * Идемпотентно (IF NOT EXISTS), дефолт '' — у старых строк пустая.
 */
final class Version20261120000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inquiry: колонка comment — свободная заметка инженера';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT '' бэкфиллит старые строки; затем снимаем default — ORM-маппинг его не несёт
        // (как raw_text: NOT NULL без default), значение всегда шлёт приложение. Без этого
        // schema:validate ругается на рассинхрон.
        $this->addSql("ALTER TABLE inquiry ADD COLUMN IF NOT EXISTS comment TEXT NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE inquiry ALTER COLUMN comment DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry DROP COLUMN IF EXISTS comment');
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д8 (T8): акты выдачи (compliance_requirement_document) переехали в журнал фактов — домен актов
 * снесён (T7). Факты подписанных актов остаются строками журнала (document_id → NULL, провенанс не нужен).
 * Формед-черновики — брошенные корзины, никогда не подписывались, в «на руках» не входили — их факты
 * отбрасываем целиком. Таблица актов больше не читается и не пишется — дропаем. Идемпотентно.
 */
final class Version20261020120600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д8 T8: миграция актов выдачи в журнал (formed → отброс, signed → NULL), drop compliance_requirement_document.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM compliance_fulfillment_record WHERE document_id IN (SELECT id::varchar FROM compliance_requirement_document WHERE status = 'formed')");
        $this->addSql('UPDATE compliance_fulfillment_record SET document_id = NULL WHERE document_id IS NOT NULL');
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement_document');
    }

    public function down(Schema $schema): void
    {
        // Необратимо: таблица актов выдачи снесена, домен актов удалён в T7.
    }
}

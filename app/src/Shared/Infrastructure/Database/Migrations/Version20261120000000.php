<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: пер-организационный счётчик номеров актов списания. Таблица compliance_writeoff_act_counter
 * (organization_id → last_number): номер акта присваивается при СОЗДАНИИ черновика из счётчика СВОЕЙ организации —
 * у каждой свой ряд 1, 2, 3… Атомарная выдача — INSERT ... ON CONFLICT DO UPDATE ... RETURNING (блокировка строки,
 * гонки исключены). Существующие (пре-лонч тестовые) act_number счётчик НЕ сеют — старт всегда с 1 на организацию.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_writeoff_act_counter: пер-организационный счётчик номеров актов списания (старт с 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_writeoff_act_counter (
                organization_id VARCHAR(64) NOT NULL,
                last_number BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (organization_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_writeoff_act_counter');
    }
}

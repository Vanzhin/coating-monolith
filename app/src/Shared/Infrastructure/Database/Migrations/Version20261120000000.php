<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: сквозной авто-номер акта списания. Postgres SEQUENCE compliance_writeoff_act_number_seq — номер
 * присваивается при подписании (как PK). Создаётся и сеется ИДЕМПОТЕНТНО: старт = max существующих числовых
 * act_number + 1 (легаси-строки с прода не перенумеровываем, новые с ними не столкнутся), seed выполняется
 * только при первом создании sequence — повторный прогон миграции номер не сбрасывает.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_writeoff_act_number_seq: сквозной авто-номер акта списания (seed = max числовых act_number + 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'S' AND relname = 'compliance_writeoff_act_number_seq') THEN
                    CREATE SEQUENCE compliance_writeoff_act_number_seq;
                    PERFORM setval(
                        'compliance_writeoff_act_number_seq',
                        GREATEST(COALESCE((SELECT MAX(act_number::bigint) FROM compliance_writeoff_act WHERE act_number ~ '^[0-9]+$'), 0) + 1, 1),
                        false
                    );
                END IF;
            END $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE IF EXISTS compliance_writeoff_act_number_seq');
    }
}

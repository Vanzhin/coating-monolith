<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: бэкфилл номера уже СОЗДАННЫМ черновикам актов списания (status='formed') без номера — теперь номер
 * присваивается при создании (чтобы печатать акт до подписи), а существующие черновики его не имеют. Берём из того
 * же сквозного счётчика {@see Version20261120000000}, по порядку created_at. Подписанные акты не трогаем (их номер
 * финализирован). Идемпотентно: только строки с act_number IS NULL.
 */
final class Version20261120000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_writeoff_act: бэкфилл сквозного номера существующим черновикам (formed) без номера';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE r RECORD;
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'S' AND relname = 'compliance_writeoff_act_number_seq') THEN
                    FOR r IN
                        SELECT id FROM compliance_writeoff_act
                        WHERE status = 'formed' AND act_number IS NULL
                        ORDER BY created_at
                    LOOP
                        UPDATE compliance_writeoff_act
                        SET act_number = nextval('compliance_writeoff_act_number_seq')::text
                        WHERE id = r.id;
                    END LOOP;
                END IF;
            END $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        // no-op: снять присвоенные номера детерминированно нельзя (и не нужно).
    }
}

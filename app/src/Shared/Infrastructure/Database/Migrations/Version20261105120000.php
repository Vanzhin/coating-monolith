<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (Task 7, contract): дроп старой `compliance_tracked_obligation`. Read-сторона (дашборд/
 * карточка/сканер due-soon) перешла на `compliance_obligation_projection` ещё в Task 1-6 (новая таблица —
 * единственный источник проекции), персональные декларации перенесены на агрегат отдельным JSONB-полем
 * (Task 5, `personal_obligations`). Старая таблица с этого момента мёртвые данные — ни читателя, ни писателя
 * у неё больше нет (доменная сущность-носитель удалена, её контракт-ключ вынесен в
 * {@see \App\Compliance\Domain\ValueObject\ObligationKey}).
 *
 * На проде применять ПОСЛЕ подтверждения паритета новой/старой таблицы (см. docs/plans — отдельный поздний
 * прогон, не в общем migrate-first гейте). Идемпотентно; `down()` восстанавливает ПУСТУЮ таблицу (схема как
 * перед дропом) для откат-безопасности — данные обратно не возвращает (это снос, не expand/contract).
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: дроп старой compliance_tracked_obligation (contract, Task 7).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_tracked_obligation');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_tracked_obligation (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                requirement_name VARCHAR(1000) NOT NULL,
                label VARCHAR(1000) NOT NULL,
                type VARCHAR(32) NOT NULL,
                cadence JSONB NOT NULL,
                quantity JSONB DEFAULT NULL,
                department_id VARCHAR(64) NOT NULL,
                last_fulfilled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                next_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0,
                active BOOLEAN NOT NULL DEFAULT FALSE,
                origin VARCHAR(16) NOT NULL,
                mode VARCHAR(16) DEFAULT NULL,
                item_id VARCHAR(64) DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_tracked_obligation_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_profile ON compliance_tracked_obligation (profile_compliance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_requirement ON compliance_tracked_obligation (requirement_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_next_due ON compliance_tracked_obligation (next_due_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_type ON compliance_tracked_obligation (type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_department ON compliance_tracked_obligation (department_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_active ON compliance_tracked_obligation (active)');
    }
}

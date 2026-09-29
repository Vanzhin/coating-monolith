<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Агрегат `Profile` (context Personnel) — карточка сотрудника: ФИО, связь с пользователем
 * платформы (user_ulid, уникален — один профиль на юзера), снимки-ссылки на должность/
 * организацию/отдел (jsonb {id,title}), размеры под СИЗ, табельный номер, дата приёма.
 * Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260929100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create personnel_profile table (employee card aggregate).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS personnel_profile (
                id VARCHAR(36) NOT NULL,
                user_ulid VARCHAR(26) NOT NULL,
                full_name JSONB NOT NULL,
                position JSONB NOT NULL,
                organization JSONB NOT NULL,
                department JSONB NOT NULL,
                personnel_number VARCHAR(50) DEFAULT NULL,
                hired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                sizes JSONB NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_personnel_profile_user ON personnel_profile (user_ulid)');
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_personnel_profile_position ON personnel_profile ((position->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_personnel_profile_department ON personnel_profile ((department->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_personnel_profile_organization ON personnel_profile ((organization->>'id'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS personnel_profile');
    }
}

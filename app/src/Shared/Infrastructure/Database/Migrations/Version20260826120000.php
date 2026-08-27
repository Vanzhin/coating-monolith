<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Контекст Certificates: индекс по expires_at для фасета «статус срока»
 * (действует / просрочен / бессрочный) в списке документов. Идемпотентно.
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add btree index on certificates_document.expires_at for the expiry-status facet.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_certificates_document_expires_at
                ON certificates_document (expires_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_certificates_document_expires_at');
    }
}

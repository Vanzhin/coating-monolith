<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Контекст Certificates: таблица издателей документов (лаборатория/институт/орган).
 * Идемпотентно.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificates_issuer table (issuer of certificates/conclusions).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS certificates_issuer (
                id UUID PRIMARY KEY,
                title VARCHAR(255) NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_certificates_issuer_title
                ON certificates_issuer (title)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS certificates_issuer');
    }
}

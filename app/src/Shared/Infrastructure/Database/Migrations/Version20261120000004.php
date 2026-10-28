<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * inquiry: убираем колонку answer. Подобранные системы больше не хранятся снимком — это живой
 * результат прогона распознанных параметров по каталогу (считается на показе). Домен Inquiries
 * про Coatings не знает. Идемпотентно (IF EXISTS).
 */
final class Version20261120000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inquiry: дроп колонки answer — подобранные системы считаются живьём, не хранятся';
    }

    public function up(Schema $schema): void
    {
        // Статус «matched» (подбор выполнен) больше не существует: как только параметры распознаны —
        // запрос готов к просмотру (Recognized), подбор живой. Переводим старые строки, иначе гидрация
        // enum падает. Идемпотентно.
        $this->addSql("UPDATE inquiry SET status = 'recognized' WHERE status = 'matched'");
        $this->addSql('ALTER TABLE inquiry DROP COLUMN IF EXISTS answer');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inquiry ADD COLUMN IF NOT EXISTS answer JSONB DEFAULT NULL');
    }
}

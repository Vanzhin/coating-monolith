<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: контейнерный тип требования (Выдача/Периодическая) на строке проекции — отдельно от типа
 * позиции. Смешанное material-требование (инвентарь+расходник) не даёт определить свой контейнер по типу
 * позиции: бейдж карточки дашборда должен читать именно его, а не тип первой встреченной строки. Nullable —
 * значение заполняет `app:compliance:rebuild-projection` (уже существующий шаг деплоя), на схему не влияет.
 */
final class Version20261106122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_obligation_projection.requirement_type (контейнерный тип требования, схема; данные — rebuild-projection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection ADD COLUMN IF NOT EXISTS requirement_type VARCHAR(32)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection DROP COLUMN IF EXISTS requirement_type');
    }
}

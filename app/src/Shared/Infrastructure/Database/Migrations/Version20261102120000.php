<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (Task 4, флип читателей): недостающая `quantity_unit` на `compliance_obligation_projection`.
 * Исходная таблица (Task 1) хранила только сумму `quantity` без единицы — для дашборда/карточки это было
 * достаточно, но форма «Выдать» (`/issue`) показывает и предзаполняет единицу измерения (пара/шт/комплект)
 * обязанности, а у персональных (вне нормы) позиций единица не выводима ни из чего другого (нет живой нормы).
 * Без этого поля флип readers на проекцию ломает `<select>`/hidden `items[i][unit]` на форме выдачи.
 * Проектор уже держит `Quantity` (amount+unit) на момент записи строки — не хватало только столбца. Идемпотентно.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: quantity_unit на compliance_obligation_projection (единица нормы для формы выдачи).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection ADD COLUMN IF NOT EXISTS quantity_unit VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection DROP COLUMN IF EXISTS quantity_unit');
    }
}

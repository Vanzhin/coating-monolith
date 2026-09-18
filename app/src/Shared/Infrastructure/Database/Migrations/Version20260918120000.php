<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data-only: карта fields Coating переходит на форму {label,kind} (вид поля для
 * презентера аудита). Схему audit_tracked_class не трогаем, только сид-строку.
 * Идемпотентно — UPDATE выставляет один и тот же блоб при повторном прогоне.
 */
final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add {label,kind} shape to Coating tracked fields config (audit presenter routing).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE audit_tracked_class
            SET fields = '{
                "title":{"label":"Название","kind":"scalar"},
                "description":{"label":"Описание","kind":"scalar"},
                "volumeSolid":{"label":"Сухой остаток, %","kind":"scalar"},
                "massDensity":{"label":"Плотность","kind":"scalar"},
                "base":{"label":"Основа","kind":"scalar"},
                "dftRange":{"label":"Толщина плёнки (DFT)","kind":"dft"},
                "applicationMinTemp":{"label":"Мин. температура нанесения","kind":"scalar"},
                "dryingMaxTemp":{"label":"Макс. температура сушки","kind":"scalar"},
                "dryToTouch":{"label":"Высыхание на отлип","kind":"duration_series"},
                "fullCure":{"label":"Полное отверждение","kind":"duration_series"},
                "minRecoatingInterval":{"label":"Мин. интервал перекрытия","kind":"recoating_tree"},
                "maxRecoatingInterval":{"label":"Макс. интервал перекрытия","kind":"recoating_tree"},
                "pack":{"label":"Фасовка","kind":"scalar"},
                "thinner":{"label":"Разбавитель","kind":"scalar"},
                "dryHeatExposure":{"label":"Сухой нагрев","kind":"thermal"},
                "isZincRich":{"label":"Цинк-наполненное","kind":"scalar"},
                "gloss":{"label":"Глянец","kind":"scalar"},
                "isTintable":{"label":"Колеруется","kind":"scalar"},
                "recoatingInterpolationModel":{"label":"Модель интерполяции перекрытия","kind":"scalar"},
                "immersionExposure":{"label":"Иммерсия","kind":"thermal"},
                "mixingRatio":{"label":"Пропорция смешивания","kind":"mixing"}
            }'::jsonb
            WHERE entity_class = 'App\Coatings\Domain\Aggregate\Coating\Coating'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE audit_tracked_class
            SET fields = '{"title":"Название","description":"Описание","volumeSolid":"Сухой остаток, %","massDensity":"Плотность","base":"Основа","dftRange":"Толщина плёнки (DFT)","applicationMinTemp":"Мин. температура нанесения","dryingMaxTemp":"Макс. температура сушки","dryToTouch":"Высыхание на отлип","fullCure":"Полное отверждение","minRecoatingInterval":"Мин. интервал перекрытия","maxRecoatingInterval":"Макс. интервал перекрытия","pack":"Фасовка","thinner":"Разбавитель","dryHeatExposure":"Сухой нагрев","isZincRich":"Цинк-наполненное","gloss":"Глянец","isTintable":"Колеруется","recoatingInterpolationModel":"Модель интерполяции перекрытия","immersionExposure":"Иммерсия","mixingRatio":"Пропорция смешивания"}'::jsonb
            WHERE entity_class = 'App\Coatings\Domain\Aggregate\Coating\Coating'
        SQL);
    }
}

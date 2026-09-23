<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

/**
 * Запроектированная система (план) — снимок слоёв выбранной CoatingSystem, засевается при создании
 * отчёта и не редактируется пользователем. Рядом с блоком «Нанесение» (факт) даёт сравнение
 * план-vs-факт. Структурно — та же таблица слоёв (переиспользует валидацию/проекцию Layers).
 */
final class SystemBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::System;
    }

    public function title(): string
    {
        return 'Система покрытия (план)';
    }

    public function fields(): array
    {
        return [
            new Field('layers', FieldType::Layers, 'Слои системы', itemFields: [
                new Field('material', FieldType::CoatingRef, 'Материал', required: true),
                new Field('dft_nominal', FieldType::Number, 'Номинальная ТСП', unit: 'мкм'),
                new Field('color', FieldType::Text, 'Цвет'),
                new Field('order', FieldType::Number, 'Слой'),
            ]),
            // Номинальная ТСП покрытия — сумма номиналов слоёв. Считает домен (CoatingSystem::totalDft),
            // засевается снимком при создании отчёта; computed — не редактируется, только отображается/выводится.
            new Field('dft_nominal_total', FieldType::Number, 'Номинальная ТСП покрытия', unit: 'мкм', computed: true),
        ];
    }
}

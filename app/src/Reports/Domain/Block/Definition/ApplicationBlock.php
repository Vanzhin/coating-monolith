<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

/**
 * Нанесение по слоям — транспонированная таблица (слои=колонки). Поле `layers` типа Layers: массив
 * строк-слоёв (≤4), каждый — параметры нанесения/контроля. material/color — текст-снимок (в 3b-4
 * станут CoatingRef/colorRef с пикером, засевом из системы и подсказками).
 */
final class ApplicationBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Application;
    }

    public function title(): string
    {
        return 'Нанесение по слоям';
    }

    public function fields(): array
    {
        return [
            new Field('layers', FieldType::Layers, 'Слои', itemFields: [
                new Field('date', FieldType::Date, 'Дата нанесения'),
                new Field('time', FieldType::TimeRange, 'Время нанесения'),
                new Field('material', FieldType::Text, 'Материал', required: true),
                new Field('color', FieldType::Text, 'Цвет'),
                new Field('batch_a', FieldType::Text, '№ партии, комп. А'),
                new Field('batch_b', FieldType::Text, '№ партии, комп. Б'),
                new Field('thinner', FieldType::Text, 'Разбавитель'),
                new Field('method', FieldType::Text, 'Метод нанесения'),
                new Field('nozzle', FieldType::Text, 'Сопло'),
                new Field('humidity', FieldType::Number, 'Отн. влажность', unit: '%'),
                new Field('air_temp', FieldType::Number, 'Температура воздуха', unit: '°C'),
                new Field('surface_temp', FieldType::Number, 'Температура поверхности', unit: '°C'),
                new Field('dew_point', FieldType::Number, 'Точка росы', unit: '°C'),
                new Field('wet_film', FieldType::Number, 'Толщина мокрого слоя', unit: 'мкм'),
                new Field('dry_film_range', FieldType::Text, 'Диапазон сухого слоя'),
                new Field('dry_film_mean', FieldType::Number, 'Средняя ТСП', unit: 'мкм'),
                new Field('visual_control', FieldType::TextArea, 'Визуальный контроль (ВИК)'),
                new Field('note', FieldType::Text, 'Примечание'),
            ]),
        ];
    }
}

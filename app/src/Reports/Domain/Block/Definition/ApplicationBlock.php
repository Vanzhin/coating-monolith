<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

/**
 * Нанесение по слоям — транспонированная таблица (слои=колонки). Поле `layers` типа Layers: массив
 * строк-слоёв (≤4), каждый — параметры нанесения/контроля. `material` — CoatingRef (снимок {id,title}
 * из каталога; id — бэклинк для засева/подсказок). `color` — пока текст (colorRef позже, вместе с
 * офлайн-кешем цветов).
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
            // Общие для нанесения (в акте указываются один раз): метод и насосная система/аппарат.
            // Список методов зеркалит Proposals\CoatingSystemApplicationMethod (без кросс-контекстной
            // связи — options блока это plain-строки, как у surface_prep).
            new Field('method', FieldType::Enum, 'Метод нанесения', options: [
                'Воздушное нанесение',
                'Воздушное или безвоздушное нанесение',
                'Безвоздушное нанесение',
                'Кисть, валик',
                'Воздушное или безвоздушное нанесение, кисть, валик',
                'Мастерок, кельма, шпатель, игольчатый валик',
            ]),
            new Field('pump_system', FieldType::Text, 'Аппарат / насосная система'),
            // Поля слоя визуально разбиты на подгруппы (group) и отображаются в порядке групп 1→3→2:
            //   1 — материал и нанесение, 3 — толщина и контроль, 2 — климатические параметры (в конец).
            // Проектор/валидатор работают по ключам, порядок и group на них не влияют.
            new Field('layers', FieldType::Layers, 'Слои', itemFields: [
                // Группа 1 — материал и нанесение. Период: полные дата+время начала/конца; в акт дата
                // (последняя) в {{..._date}}, интервал времени «ЧЧ:ММ–ЧЧ:ММ» в {{..._time}}.
                new Field('applied', FieldType::DateTimeRange, 'Дата и время нанесения (с / по)', group: 1),
                new Field('material', FieldType::CoatingRef, 'Материал', required: true, group: 1),
                new Field('color', FieldType::Text, 'Цвет', group: 1),
                new Field('batch_a', FieldType::Text, '№ партии, комп. А', group: 1),
                new Field('batch_b', FieldType::Text, '№ партии, комп. Б', group: 1),
                new Field('thinner', FieldType::Thinner, 'Разбавитель (название / № партии / %)', group: 1),
                new Field('nozzle', FieldType::Text, 'Сопло', group: 1),
                // Группа 3 — толщина и контроль.
                new Field('wet_film', FieldType::NumberRange, 'Толщина мокрого слоя (мин/макс), мкм', unit: 'мкм', group: 3, calculator: 'wet-film'),
                new Field('dry_film', FieldType::Thickness, 'Толщина сухого слоя (мин/макс/средняя), мкм', group: 3),
                new Field('visual_control', FieldType::TextArea, 'Визуальный контроль (ВИК)', group: 3),
                new Field('note', FieldType::Text, 'Примечание', group: 3),
                // Группа 2 — климатические параметры (отображается последней).
                new Field('humidity', FieldType::Number, 'Отн. влажность', unit: '%', percent: true, group: 2),
                new Field('air_temp', FieldType::Number, 'Температура воздуха', unit: '°C', group: 2),
                new Field('surface_temp', FieldType::Number, 'Температура поверхности', unit: '°C', group: 2),
                new Field('dew_point', FieldType::Number, 'Точка росы', unit: '°C', computed: true, group: 2, calculator: 'dew-point'),
            ]),
        ];
    }
}

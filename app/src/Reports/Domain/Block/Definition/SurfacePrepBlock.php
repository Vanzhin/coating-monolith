<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class SurfacePrepBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::SurfacePrep;
    }

    public function title(): string
    {
        return 'Подготовка поверхности';
    }

    public function fields(): array
    {
        return [
            new Field('rustGrade', FieldType::Enum, 'Класс ржавления', required: true, options: ['A', 'B', 'C', 'D'], standard: 'ISO 8501-1'),
            new Field('prepDegree', FieldType::Enum, 'Степень подготовки', required: true, options: ['Sa 1', 'Sa 2', 'Sa 2½', 'Sa 3', 'St 2', 'St 3'], standard: 'ISO 8501-1'),
            new Field('abrasive', FieldType::Text, 'Абразив'),
            new Field('roughness', FieldType::Text, 'Шероховатость (профиль)'),
            new Field('dedusting', FieldType::Enum, 'Обеспыливание, класс', options: ['1', '2', '3'], standard: 'ISO 8502-3'),
        ];
    }
}

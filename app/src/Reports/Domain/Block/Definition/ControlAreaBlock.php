<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

/**
 * Контролируемый участок — конкретная часть площади, на которой велись работы и контроль
 * (напр. «окрашена балка, эталон взят с нижней полки»). Описание + площадь.
 */
final class ControlAreaBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::ControlArea;
    }

    public function title(): string
    {
        return 'Контролируемый участок';
    }

    public function fields(): array
    {
        return [
            new Field('description', FieldType::TextArea, 'Участок', required: true),
            new Field('area', FieldType::Number, 'Площадь', unit: 'м²', positive: true),
        ];
    }
}

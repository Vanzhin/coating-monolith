<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class CommissionBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Commission;
    }

    public function title(): string
    {
        return 'Комиссия';
    }

    public function fields(): array
    {
        return [
            new Field('items', FieldType::ListRows, 'Члены комиссии', itemFields: [
                new Field('organization', FieldType::Text, 'Организация'),
                new Field('position', FieldType::Text, 'Должность'),
                new Field('name', FieldType::Text, 'ФИО', required: true),
                new Field('date', FieldType::Date, 'Дата'),
            ]),
        ];
    }
}

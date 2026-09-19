<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class InstrumentsBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Instruments;
    }

    public function title(): string
    {
        return 'Приборы контроля';
    }

    public function fields(): array
    {
        return [
            new Field('items', FieldType::ListRows, 'Приборы контроля', itemFields: [
                new Field('name', FieldType::Text, 'Наименование', required: true),
                new Field('serial', FieldType::Text, 'Серийный №'),
                new Field('sensor', FieldType::Text, 'Датчик'),
            ]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class ProcessBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Process;
    }

    public function title(): string
    {
        return 'Процесс работ: несоответствия и корректирующие действия';
    }

    public function fields(): array
    {
        return [
            new Field('items', FieldType::ListRows, 'Несоответствия', itemFields: [
                new Field('description', FieldType::TextArea, 'Несоответствие', required: true, rows: 5),
                new Field('action', FieldType::TextArea, 'Корректирующее действие', required: true, rows: 5),
            ]),
        ];
    }
}

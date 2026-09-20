<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class ConclusionBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Conclusion;
    }

    public function title(): string
    {
        return 'Выводы и заключение комиссии';
    }

    public function fields(): array
    {
        return [
            new Field('text', FieldType::TextArea, 'Выводы и заключение', required: true),
        ];
    }
}

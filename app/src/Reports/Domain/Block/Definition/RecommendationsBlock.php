<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

final class RecommendationsBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Recommendations;
    }

    public function title(): string
    {
        return 'Рекомендации';
    }

    public function fields(): array
    {
        return [
            // Список строк-рекомендаций; в акт — нумерованным списком. Каждая запись — textarea (4 строки).
            new Field('items', FieldType::StringList, 'Рекомендации', rows: 4),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Стилевые Twig-фильтры для тегов покрытий.
 *
 * tag.type|tag_bootstrap_color → Bootstrap contextual color name
 * (secondary / primary / success / …), который потом ставится в
 * class="badge text-bg-{color}" в шаблоне.
 *
 * Если в будущем понадобится тонкая настройка отдельным словам (например,
 * «бетон» всегда серый), добавим второй фильтр tag|tag_bootstrap_color с
 * override-map'ом по title. Пока хватает семантики по типу.
 */
final class CoatingTagExtension extends AbstractExtension
{
    /**
     * @var array<string, string>
     */
    private const TYPE_COLORS = [
        'general' => 'secondary',
        'CoatingCoatType' => 'primary',
        'CoatingProtectionType' => 'success',
    ];

    private const DEFAULT_COLOR = 'secondary';

    public function getFilters(): array
    {
        return [
            new TwigFilter('tag_bootstrap_color', [$this, 'colorForType']),
        ];
    }

    public function colorForType(?string $type): string
    {
        return self::TYPE_COLORS[$type] ?? self::DEFAULT_COLOR;
    }
}

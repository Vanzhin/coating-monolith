<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\ReplaceLayers;

use App\Shared\Application\Command\Command;

/**
 * Полностью заменяет состав слоёв системы. Порядок в $items определяет позиции 1..N.
 * Каждый элемент: ['coatingId' => uuid, 'dft' => int].
 */
final readonly class ReplaceLayersCommand extends Command
{
    /**
     * @param list<array{coatingId: string, dft: int}> $items
     */
    public function __construct(
        public string $systemId,
        public array $items,
    ) {
    }
}

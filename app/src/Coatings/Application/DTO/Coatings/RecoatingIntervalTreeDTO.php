<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Транспортный DTO для дерева интервалов перекрытия (структурно — дерево, не один узел).
 * Чистый контейнер: ни инвариантов, ни поведения. Доменная сборка — RecoatingTreeBuilder → RecoatingIntervalTree.
 */
class RecoatingIntervalTreeDTO
{
    /** @var list<DryingTimePointDTO> */
    public array $default = [];

    /** @var array<string, self> */
    public array $branches = [];
}

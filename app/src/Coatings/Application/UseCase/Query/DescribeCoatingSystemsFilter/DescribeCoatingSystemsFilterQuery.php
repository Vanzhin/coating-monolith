<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\DescribeCoatingSystemsFilter;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Shared\Application\Query\Query;

/**
 * Описать примитивный вход фильтра человекочитаемыми подписями (без веществ — их имена у
 * ChemicalResistance). Вызывают внешние контексты, чтобы показать распознанные параметры.
 */
final readonly class DescribeCoatingSystemsFilterQuery extends Query
{
    public function __construct(public CoatingSystemsFilterInput $input)
    {
    }
}

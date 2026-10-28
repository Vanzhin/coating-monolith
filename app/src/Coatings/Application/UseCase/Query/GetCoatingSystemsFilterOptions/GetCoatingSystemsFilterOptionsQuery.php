<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterOptions;

use App\Shared\Application\Query\Query;

/**
 * Полное дерево вариантов фильтра систем (подложки, среды, стандарты с категорией/долговечностью,
 * тепловые среды). Без аргументов — варианты статичны. Зовут внешние контексты (Inquiries), чтобы
 * построить форму правки параметров, не трогая домен Coatings.
 */
final readonly class GetCoatingSystemsFilterOptionsQuery extends Query
{
}

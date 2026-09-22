<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingCalcContext;

use App\Shared\Application\Query\Query;

/**
 * Контекст покрытия для калькуляторов (сухой остаток, фасовка, плотность, соотношение) по id —
 * ленивый засев калькуляторов на странице заполнения по клику на значок у поля слоя.
 */
readonly class GetCoatingCalcContextQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}

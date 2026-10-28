<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceNamesByIds;

use App\Shared\Application\Query\Query;

/**
 * Имена веществ по id (для показа распознанных параметров во внешних контекстах). Возвращает карту
 * id → каноническое имя; отсутствующие id просто не попадут в карту.
 */
final readonly class SubstanceNamesByIdsQuery extends Query
{
    /** @param list<string> $ids */
    public function __construct(public array $ids)
    {
    }
}

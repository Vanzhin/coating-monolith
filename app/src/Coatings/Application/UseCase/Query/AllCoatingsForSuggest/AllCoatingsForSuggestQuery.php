<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\AllCoatingsForSuggest;

use App\Shared\Application\Query\Query;

/**
 * Весь каталог покрытий одним ответом (лёгкие CoatingSuggestDTO) — для офлайн-выгрузки на
 * устройство (IndexedDB) и полного пикера. Без параметров: отдаём всё (≤1000 покрытий).
 */
final readonly class AllCoatingsForSuggestQuery extends Query
{
}

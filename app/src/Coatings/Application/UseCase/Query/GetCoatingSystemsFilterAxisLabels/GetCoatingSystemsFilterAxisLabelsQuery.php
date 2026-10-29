<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterAxisLabels;

use App\Shared\Application\Query\Query;

/**
 * Полный словарь подписей осей фильтра (ключ → русская подпись), независимо от значений. Для внешних
 * контекстов (дашборд точности Inquiries), чтобы подписывать оси, не зная схему фильтра Coatings.
 */
final readonly class GetCoatingSystemsFilterAxisLabelsQuery extends Query
{
}

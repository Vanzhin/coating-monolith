<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use Symfony\Component\Uid\Uuid;

/**
 * Read-only entity для DQL JOIN в FTS-запросах.
 * Данные обновляются триггером на coatings_coating; приложение их не пишет напрямую.
 */
class CoatingSearch
{
    // @phpstan-ignore-next-line — свойство читается только через DQL/ORM hydration
    private Uuid $coatingId;
    // @phpstan-ignore-next-line — свойство читается только через DQL/ORM hydration
    private string $searchVector;
}

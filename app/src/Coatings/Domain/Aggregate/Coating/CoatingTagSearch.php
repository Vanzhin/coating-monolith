<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

/**
 * Read-only entity для DQL JOIN в FTS-запросах по тегам.
 * Данные обновляются триггером на coatings_coating_tag; приложение их не пишет напрямую.
 */
class CoatingTagSearch
{
    // @phpstan-ignore-next-line — свойство читается только через DQL/ORM hydration
    private string $tagId;
    // @phpstan-ignore-next-line — свойство читается только через DQL/ORM hydration
    private string $searchVector;
}

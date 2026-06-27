<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

/**
 * Read-only entity для DQL JOIN в FTS-запросах по тегам.
 * Данные обновляются триггером на coatings_coating_tag; приложение их не пишет напрямую.
 */
class CoatingTagSearch
{
    private string $tagId;
    private string $searchVector;
}

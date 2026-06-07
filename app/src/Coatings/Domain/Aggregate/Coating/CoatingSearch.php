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
    private Uuid $coatingId;
    private string $searchVector;
}

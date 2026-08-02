<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use Doctrine\DBAL\Connection;

final class CoatingSystemSearchCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function upsert(CoatingSystem $system): void
    {
        $this->conn->executeStatement(
            <<<'SQL'
                INSERT INTO coating_system_search
                    (system_id, min_building_time_at_20_minutes, max_layer_application_min_temp, search_tsvector)
                VALUES
                    (:id, :sum, :max_temp, to_tsvector('russian', :doc))
                ON CONFLICT (system_id) DO UPDATE
                SET min_building_time_at_20_minutes = EXCLUDED.min_building_time_at_20_minutes,
                    max_layer_application_min_temp  = EXCLUDED.max_layer_application_min_temp,
                    search_tsvector                 = EXCLUDED.search_tsvector
            SQL,
            [
                'id'       => $system->getId(),
                'sum'      => $system->minBuildingTimeAt20Minutes(),
                'max_temp' => $system->maxLayerApplicationMinTemp(),
                'doc'      => $this->buildFullTextSearchDocument($system),
            ],
        );
    }

    public function delete(string $systemId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_search WHERE system_id = :id',
            ['id' => $systemId],
        );
    }

    private function buildFullTextSearchDocument(CoatingSystem $system): string
    {
        $parts = [$system->getTitle(), $system->getDescription()];
        foreach ($system->getLayers() as $layer) {
            $coating = $layer->getCoating();
            $parts[] = $coating->getManufacturer()->getTitle();
            $parts[] = $coating->getTitle();
        }
        foreach ($system->getTags() as $tag) {
            $parts[] = $tag->getTitle();
        }

        return implode(' ', array_filter($parts, static fn (string $p) => '' !== trim($p)));
    }
}

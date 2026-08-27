<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Application\Service\OperatingTemperatureSnapshot;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use Doctrine\DBAL\Connection;

final class CoatingSystemSearchCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function upsert(CoatingSystem $system, OperatingTemperatureSnapshot $temps): void
    {
        $this->conn->executeStatement(
            <<<'SQL'
                INSERT INTO coating_system_search
                    (system_id, min_application_time_at_20_minutes, max_layer_application_min_temp,
                     dry_heat_continuous_max, dry_heat_peak_max, immersion_continuous_max, immersion_peak_max,
                     search_tsvector)
                VALUES
                    (:id, :sum, :max_temp, :dry_cont, :dry_peak, :imm_cont, :imm_peak, to_tsvector('russian', :doc))
                ON CONFLICT (system_id) DO UPDATE
                SET min_application_time_at_20_minutes = EXCLUDED.min_application_time_at_20_minutes,
                    max_layer_application_min_temp  = EXCLUDED.max_layer_application_min_temp,
                    dry_heat_continuous_max         = EXCLUDED.dry_heat_continuous_max,
                    dry_heat_peak_max               = EXCLUDED.dry_heat_peak_max,
                    immersion_continuous_max        = EXCLUDED.immersion_continuous_max,
                    immersion_peak_max              = EXCLUDED.immersion_peak_max,
                    search_tsvector                 = EXCLUDED.search_tsvector
            SQL,
            [
                'id' => $system->getId(),
                'sum' => $system->minApplicationTimeAt20Minutes(),
                'max_temp' => $system->maxLayerApplicationMinTemp(),
                'dry_cont' => $temps->dryHeatContinuousMax,
                'dry_peak' => $temps->dryHeatPeakMax,
                'imm_cont' => $temps->immersionContinuousMax,
                'imm_peak' => $temps->immersionPeakMax,
                'doc' => $this->buildFullTextSearchDocument($system),
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

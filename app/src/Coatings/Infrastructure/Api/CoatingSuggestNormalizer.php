<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;

/**
 * Единый JSON-shape строки подсказки покрытия. Используют и SuggestAction (typeahead), и
 * CatalogAction (офлайн-выгрузка каталога) — один источник формата. Важно: ETag каталога = хеш
 * тела, поэтому два расходящихся сериализатора дали бы разные ETag на идентичных данных.
 */
final class CoatingSuggestNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CoatingSuggestDTO $coating): array
    {
        return [
            'id' => $coating->id,
            'title' => $coating->title,
            'base' => $coating->base,
            'dftMin' => $coating->dftMin,
            'dftMax' => $coating->dftMax,
            // Сухой остаток — для калькуляторов толщины плёнки и расхода.
            'volumeSolid' => $coating->volumeSolid,
            // Фасовка и плотность — для калькулятора расхода (вёдра, масса).
            'pack' => $coating->pack,
            'massDensity' => $coating->massDensity,
            // Соотношение смешивания для калькулятора инструментов (null у однокомпонентных).
            'mixingRatio' => null === $coating->mixingRatio
                ? null
                : ['volume' => $coating->mixingRatio->volume, 'mass' => $coating->mixingRatio->mass],
        ];
    }
}

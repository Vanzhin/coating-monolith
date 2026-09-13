<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Лёгкий DTO строки typeahead-подсказки покрытия. Только то, что нужно выпадающему списку:
 * id, готовый к показу title, база/ДСТ и соотношение смешивания (для калькулятора).
 * Без тяжёлых связей (теги/цвета/системы/химстойкость) — их строит полный CoatingDTO.
 */
class CoatingSuggestDTO
{
    public string $id;

    /** Готовая подпись: «Название (база, min–max мкм)». */
    public string $title;

    public string $base;
    public int $dftMin;
    public int $dftMax;

    /** Сухой остаток по объёму (%), 1..100 — для калькуляторов толщины плёнки и расхода. */
    public int $volumeSolid;

    /** Фасовка (объём тары, л) — для калькулятора расхода (сколько вёдер). */
    public float $pack;

    /** Плотность (кг/л) — для массы в калькуляторе расхода. */
    public float $massDensity;

    /** Соотношение смешивания (для калькулятора инструментов); null у однокомпонентных. */
    public ?MixingRatioDTO $mixingRatio = null;
}

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

    /** Соотношение смешивания (для калькулятора инструментов); null у однокомпонентных. */
    public ?MixingRatioDTO $mixingRatio = null;
}

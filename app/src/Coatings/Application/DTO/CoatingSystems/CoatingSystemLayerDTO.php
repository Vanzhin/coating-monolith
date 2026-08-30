<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

class CoatingSystemLayerDTO
{
    public string $id;
    public int $position;
    public int $dft;
    public string $coatingId;
    public string $coatingTitle;
    public string $coatingBase;
    public string $coatingBaseTitle;
    public bool $isZincRich;
    public string $manufacturerTitle;
    public int $dftMin;
    public int $dftMax;

    // Выбранный цвет слоя — обязателен. RAL nullable (у цвета может не быть кода RAL).
    public string $colorId;
    public string $colorName;
    public ?string $colorRal = null;
    public string $colorHex;
    // Готовая подпись «Название (RAL XXXX)» — единый формат отображения (см. Color::label()).
    public string $colorLabel;
}

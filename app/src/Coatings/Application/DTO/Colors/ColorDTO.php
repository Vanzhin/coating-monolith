<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Colors;

/**
 * Полное представление цвета для чтения/отображения (suggest, предвыбранные чипы, карточка).
 * Строится только из сущности `Color`, поэтому name/hex всегда заданы; `ral` опционален
 * (у кастомного цвета его нет). Write-путь покрытия несёт id цветов отдельно (StringCollection).
 */
final class ColorDTO
{
    public string $id;
    public string $name;
    public ?string $ral = null;
    public string $hex;
}

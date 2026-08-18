<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Color;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * HEX-представление цвета в формате #RRGGBB (нормализуется в верхний регистр).
 * Это реальный оттенок цвета — из него рисуется визуальный свотч.
 */
final readonly class HexColor
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper(trim($value));

        if (!preg_match('/^#[0-9A-F]{6}$/', $normalized)) {
            throw new AppException(sprintf('Некорректный HEX-цвет: «%s». Ожидается формат #RRGGBB.', $value));
        }

        $this->value = $normalized;
    }
}

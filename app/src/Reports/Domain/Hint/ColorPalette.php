<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

/**
 * Палитра покрытия для подсказки по цвету слоя: набор допустимых меток (название/RAL/label) + флаг
 * «колеруется в любой цвет». Сверка регистронезависимая. Держит evaluator независимым от агрегата
 * Coating — метки собирает Application из загруженного покрытия.
 */
final readonly class ColorPalette
{
    /** @param list<string> $labels */
    public function __construct(
        private bool $tintable,
        private array $labels,
    ) {
    }

    public function accepts(string $color): bool
    {
        if ($this->tintable) {
            return true;
        }
        $needle = mb_strtolower(trim($color));

        foreach ($this->labels as $label) {
            if (mb_strtolower(trim($label)) === $needle) {
                return true;
            }
        }

        return false;
    }
}

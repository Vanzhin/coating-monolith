<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Текстовое значение. Форматирование (даты, числа, локали) — забота вызывающего:
 * сюда приходит уже готовая строка. Оформление (жирный/курсив/цвет) берётся из шаблона.
 */
final readonly class TextValue implements TemplateValue
{
    public function __construct(public string $value)
    {
    }
}

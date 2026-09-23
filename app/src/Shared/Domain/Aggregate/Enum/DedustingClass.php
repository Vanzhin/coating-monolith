<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

/**
 * Класс обеспыливания поверхности (ISO 8502-3). `value` — короткий код (форма), `documentText()` —
 * полное для акта.
 */
enum DedustingClass: string implements DocumentTextEnum
{
    case Class1 = '1';
    case Class2 = '2';
    case Class3 = '3';

    public function documentText(): string
    {
        return sprintf('класс %s по количеству и размеру частиц пыли согласно ISO 8502-3.', $this->value);
    }
}

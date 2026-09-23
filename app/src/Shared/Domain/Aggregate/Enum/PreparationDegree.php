<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

/**
 * Степень подготовки поверхности (ISO 8501-1). `value` — короткий код (форма), `documentText()` —
 * полное предложение для акта.
 */
enum PreparationDegree: string implements DocumentTextEnum
{
    case Sa1 = 'Sa 1';
    case Sa2 = 'Sa 2';
    case Sa2Half = 'Sa 2½';
    case Sa3 = 'Sa 3';
    case St2 = 'St 2';
    case St3 = 'St 3';

    public function documentText(): string
    {
        return match ($this) {
            self::Sa1 => 'Лёгкая абразивоструйная очистка до степени Sa 1 по ISO 8501-1.',
            self::Sa2 => 'Абразивоструйная очистка до степени Sa 2 по ISO 8501-1.',
            self::Sa2Half => 'Абразивоструйная очистка до степени Sa 2½ по ISO 8501-1.',
            self::Sa3 => 'Абразивоструйная очистка до визуально чистой стали, степень Sa 3 по ISO 8501-1.',
            self::St2 => 'Ручная и механизированная очистка до степени St 2 по ISO 8501-1.',
            self::St3 => 'Тщательная ручная и механизированная очистка до степени St 3 по ISO 8501-1.',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

/**
 * Класс (степень) ржавления поверхности (ГОСТ Р ИСО 8501-1-2014). `value` — короткий код (форма),
 * `documentText()` — полное для акта (без развёрнутого описания — по требованию: только степень + стандарт).
 */
enum RustGrade: string implements DocumentTextEnum
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function documentText(): string
    {
        return sprintf('Степень %s по ГОСТ Р ИСО 8501-1-2014', $this->value);
    }
}

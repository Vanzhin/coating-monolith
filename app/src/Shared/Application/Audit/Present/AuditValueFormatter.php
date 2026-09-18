<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present;

/**
 * Один шаг цепочки {@see ValueHumanizer}: проверяет форму значения (structural,
 * не тип) и, если она своя, рендерит значение в человекочитаемую строку.
 * Path-агностично — про смысл конкретного поля форматтеру ничего не известно,
 * только про форму самого значения (scalar, список точек, дерево, …).
 */
interface AuditValueFormatter
{
    public function supports(mixed $value): bool;

    public function format(mixed $value): string;
}

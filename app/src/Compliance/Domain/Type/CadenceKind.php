<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Вид периодичности обязанности (4 вида):
 * - `Once` — однократно, срок не повторяется;
 * - `Periodic` — каждые N (месяцев/лет): число + единица периода (см. {@see PeriodUnit}); считается nextDueAt;
 * - `ByFact` — до износа: планового срока нет (меняется по факту), либо предел «не более N» задаётся в норме;
 * - `ByManufacturerDoc` — по документам изготовителя: конкретная дата задаётся при выдаче (Д3), не в норме.
 */
enum CadenceKind: string
{
    case Once = 'once';
    case Periodic = 'periodic';
    case ByFact = 'by_fact';
    case ByManufacturerDoc = 'by_manufacturer_doc';

    public function title(): string
    {
        return match ($this) {
            self::Once => 'однократно',
            self::Periodic => 'каждые N (мес./лет)',
            self::ByFact => 'до износа',
            self::ByManufacturerDoc => 'по документам изготовителя',
        };
    }

    /** Требует число + единицу периода (только `Periodic`). */
    public function requiresNumber(): bool
    {
        return self::Periodic === $this;
    }

    /** Периодический вид — система сама считает следующий срок (только `Periodic`). */
    public function isPeriodic(): bool
    {
        return self::Periodic === $this;
    }
}

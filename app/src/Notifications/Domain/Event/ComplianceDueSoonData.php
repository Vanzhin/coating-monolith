<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Данные события «сроки обязанностей сотрудника» для рендера. Дайджест на человека — список позиций. */
interface ComplianceDueSoonData
{
    public function employeeFio(): string;

    /** @return list<ComplianceDueItem> */
    public function items(): array;
}

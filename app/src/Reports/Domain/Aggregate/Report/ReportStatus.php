<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Report;

/**
 * Жизненный цикл отчёта: создан → в работе → на проверке → утверждён | отклонён.
 * «Утверждён» — терминально-замороженное состояние (мутации отчёта запрещены).
 */
enum ReportStatus: string
{
    case Created = 'created';
    case InWork = 'in_work';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создан',
            self::InWork => 'В работе',
            self::UnderReview => 'На проверке',
            self::Approved => 'Утверждён',
            self::Rejected => 'Отклонён',
        };
    }

    /** Утверждённый отчёт заморожен — редактировать нельзя. */
    public function isFrozen(): bool
    {
        return self::Approved === $this;
    }

    /**
     * Допустимые следующие статусы (логическая цепочка жизненного цикла).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::InWork],
            self::InWork => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Rejected => [self::InWork],
            self::Approved => [], // терминальный, заморожен
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}

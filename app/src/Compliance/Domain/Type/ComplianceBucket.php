<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Состояние обязанности — 4 бакета (Ok/Soon/Overdue/Missing). Канонический результат единого расчёта
 * {@see \App\Compliance\Domain\Service\ComplianceStatusResolver::bucketFor()} из подписи документа + хранимых
 * дат + нормы/наличия + текущего момента. НЕ хранится (меняется от хода времени) — выводится на чтении
 * (дашборд, карточка, проход уведомлений) ОДНИМ резолвером.
 *
 * Трёхцветный {@see ComplianceStatus} (Green/Yellow/Red) — производное представление этого бакета
 * ({@see ComplianceStatus::fromBucket()}): Overdue и Missing вместе = Red.
 */
enum ComplianceBucket: string
{
    case Ok = 'ok';
    case Soon = 'soon';
    case Overdue = 'overdue';
    case Missing = 'missing';

    public function label(ComplianceType $type): string
    {
        return match ($this) {
            self::Ok => 'в норме',
            self::Soon => 'подходит срок',
            self::Overdue => 'просрочено',
            self::Missing => ComplianceType::Material === $type ? 'не выдано' : 'не пройдено',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Soon => 1,
            self::Overdue => 2,
            self::Missing => 3,
        };
    }

    /** Проблемные состояния (для «только проблемы» и счёта проблем в разрезах). */
    public function isProblem(): bool
    {
        return self::Overdue === $this || self::Missing === $this;
    }

    public static function worseOf(self $a, self $b): self
    {
        return $a->severity() >= $b->severity() ? $a : $b;
    }
}

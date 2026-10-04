<?php

declare(strict_types=1);

namespace App\Compliance\Application\ReadModel;

use App\Compliance\Domain\Type\ComplianceType;

/**
 * Read-side светофор дашборда — 4 состояния (в отличие от доменного {@see \App\Compliance\Domain\Type\ComplianceStatus}
 * Green/Yellow/Red): «не выдано» (нет подписанного документа/не выполнено) отделено от «просрочено». Домен-инвариант
 * не трогаем: Missing+Overdue вместе = его Red. Выводится на чтении {@see ComplianceBucketResolver}.
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

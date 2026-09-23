<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

/**
 * Шероховатость поверхности (ISO 8503-2): грейд (тоньше тонкого / тонкий / средний / грубый / грубее
 * грубого) × компаратор G (grit) / S (shot). Крайние грейды — редкие. `value` — короткий код (форма),
 * `documentText()` — полное предложение для акта.
 */
enum SurfaceRoughness: string implements DocumentTextEnum
{
    case FinerFineG = 'Тоньше тонкого G';
    case FineG = 'Тонкий G';
    case MediumG = 'Средний G';
    case CoarseG = 'Грубый G';
    case CoarserCoarseG = 'Грубее грубого G';
    case FinerFineS = 'Тоньше тонкого S';
    case FineS = 'Тонкий S';
    case MediumS = 'Средний S';
    case CoarseS = 'Грубый S';
    case CoarserCoarseS = 'Грубее грубого S';

    public function documentText(): string
    {
        return match ($this) {
            self::FinerFineG => 'Тоньше тонкого G – мельче сегмента 1 компаратора G по ISO 8503-2.',
            self::FineG => 'Тонкий G – между 1 и 2 сегментами, исключая сегмент 2, компаратора G по ISO 8503-2.',
            self::MediumG => 'Средний G – между 2 и 3 сегментами, исключая сегмент 3, компаратора G по ISO 8503-2.',
            self::CoarseG => 'Грубый G – между 3 и 4 сегментами, исключая сегмент 4, компаратора G по ISO 8503-2.',
            self::CoarserCoarseG => 'Грубее грубого G – крупнее сегмента 4 компаратора G по ISO 8503-2.',
            self::FinerFineS => 'Тоньше тонкого S – мельче сегмента 1 компаратора S по ISO 8503-2.',
            self::FineS => 'Тонкий S – между 1 и 2 сегментами, исключая сегмент 2, компаратора S по ISO 8503-2.',
            self::MediumS => 'Средний S – между 2 и 3 сегментами, исключая сегмент 3, компаратора S по ISO 8503-2.',
            self::CoarseS => 'Грубый S – между 3 и 4 сегментами, исключая сегмент 4, компаратора S по ISO 8503-2.',
            self::CoarserCoarseS => 'Грубее грубого S – крупнее сегмента 4 компаратора S по ISO 8503-2.',
        };
    }
}

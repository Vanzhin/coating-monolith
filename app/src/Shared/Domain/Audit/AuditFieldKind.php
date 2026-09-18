<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

/**
 * Вид трекаемого поля — подсказка презентеру аудита, как маршрутизировать
 * структурный путь FieldChange в подпись+значение. Сам движок аудита (JsonDiff,
 * презентер) о доменных полях не знает — вид приходит из конфига TrackedClass.
 */
enum AuditFieldKind: string
{
    case Scalar = 'scalar';
    case DurationSeries = 'duration_series';
    case Dft = 'dft';
    case Thermal = 'thermal';
    case Mixing = 'mixing';
    case RecoatingTree = 'recoating_tree';

    /** Неизвестное или отсутствующее значение → Scalar, не бросает. */
    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Scalar;
    }
}

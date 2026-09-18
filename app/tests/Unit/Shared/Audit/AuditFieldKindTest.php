<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\AuditFieldKind;
use PHPUnit\Framework\TestCase;

final class AuditFieldKindTest extends TestCase
{
    /**
     * @return iterable<string, array{string, AuditFieldKind}>
     */
    public static function knownValues(): iterable
    {
        yield 'scalar' => ['scalar', AuditFieldKind::Scalar];
        yield 'duration_series' => ['duration_series', AuditFieldKind::DurationSeries];
        yield 'dft' => ['dft', AuditFieldKind::Dft];
        yield 'thermal' => ['thermal', AuditFieldKind::Thermal];
        yield 'mixing' => ['mixing', AuditFieldKind::Mixing];
        yield 'recoating_tree' => ['recoating_tree', AuditFieldKind::RecoatingTree];
    }

    /**
     * @dataProvider knownValues
     */
    public function test_from_string_resolves_known_value(string $value, AuditFieldKind $expected): void
    {
        self::assertSame($expected, AuditFieldKind::fromString($value));
    }

    public function test_from_string_falls_back_to_scalar_for_unknown_value(): void
    {
        self::assertSame(AuditFieldKind::Scalar, AuditFieldKind::fromString('bogus'));
    }

    public function test_from_string_falls_back_to_scalar_for_null(): void
    {
        self::assertSame(AuditFieldKind::Scalar, AuditFieldKind::fromString(null));
    }
}

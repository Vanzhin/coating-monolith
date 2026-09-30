<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class CadenceTest extends TestCase
{
    public function test_periodic_years_next_due(): void
    {
        $due = (new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Year))->nextDueFrom(new \DateTimeImmutable('2024-01-01'));
        self::assertSame('2026-01-01', $due?->format('Y-m-d'));
    }

    public function test_periodic_months_next_due(): void
    {
        $due = (new Cadence(CadenceKind::Periodic, 30, PeriodUnit::Month))->nextDueFrom(new \DateTimeImmutable('2024-01-01'));
        self::assertSame('2026-07-01', $due?->format('Y-m-d'));
    }

    public function test_non_periodic_kinds_have_no_due(): void
    {
        $base = new \DateTimeImmutable('2024-01-01');
        self::assertNull((new Cadence(CadenceKind::Once))->nextDueFrom($base));
        self::assertNull((new Cadence(CadenceKind::ByFact))->nextDueFrom($base));
        self::assertNull((new Cadence(CadenceKind::ByManufacturerDoc))->nextDueFrom($base));
    }

    public function test_periodic_without_number_throws(): void
    {
        $this->expectException(AppException::class);
        new Cadence(CadenceKind::Periodic, null, PeriodUnit::Year);
    }

    public function test_periodic_without_unit_throws(): void
    {
        $this->expectException(AppException::class);
        new Cadence(CadenceKind::Periodic, 2);
    }

    public function test_periodic_zero_number_throws(): void
    {
        $this->expectException(AppException::class);
        new Cadence(CadenceKind::Periodic, 0, PeriodUnit::Month);
    }

    public function test_number_and_unit_dropped_for_non_periodic(): void
    {
        $cadence = new Cadence(CadenceKind::Once, 5, PeriodUnit::Year);
        self::assertNull($cadence->number);
        self::assertNull($cadence->unit);
    }

    public function test_labels(): void
    {
        self::assertSame('однократно', (new Cadence(CadenceKind::Once))->label());
        self::assertSame('ежегодно', (new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year))->label());
        self::assertSame('каждые 2 года', (new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Year))->label());
        self::assertSame('каждые 5 лет', (new Cadence(CadenceKind::Periodic, 5, PeriodUnit::Year))->label());
        self::assertSame('ежемесячно', (new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Month))->label());
        self::assertSame('каждые 3 месяца', (new Cadence(CadenceKind::Periodic, 3, PeriodUnit::Month))->label());
        self::assertSame('по факту', (new Cadence(CadenceKind::ByFact))->label());
        self::assertSame('по документам изготовителя', (new Cadence(CadenceKind::ByManufacturerDoc))->label());
    }

    public function test_round_trip(): void
    {
        $cadence = new Cadence(CadenceKind::Periodic, 3, PeriodUnit::Year);
        self::assertSame($cadence->jsonSerialize(), Cadence::fromArray($cadence->jsonSerialize())->jsonSerialize());
    }
}

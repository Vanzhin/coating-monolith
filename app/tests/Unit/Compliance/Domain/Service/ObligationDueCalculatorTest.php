<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
use PHPUnit\Framework\TestCase;

final class ObligationDueCalculatorTest extends TestCase
{
    private ObligationDueCalculator $c;
    private \DateTimeImmutable $issued;

    protected function setUp(): void
    {
        $this->c = new ObligationDueCalculator();
        $this->issued = new \DateTimeImmutable('2026-01-10');
    }

    public function test_periodic_adds_period(): void
    {
        $due = $this->c->nextDue(new Cadence(CadenceKind::Periodic, 2, PeriodUnit::Year), $this->issued, null);
        self::assertSame('2028-01-10', $due?->format('Y-m-d'));
    }

    public function test_once_and_by_fact_have_no_due(): void
    {
        self::assertNull($this->c->nextDue(new Cadence(CadenceKind::Once), $this->issued, null));
        self::assertNull($this->c->nextDue(new Cadence(CadenceKind::ByFact), $this->issued, null));
    }

    public function test_by_fact_with_limit_adds_period(): void
    {
        $due = $this->c->nextDue(new Cadence(CadenceKind::ByFact, 30, PeriodUnit::Month), $this->issued, null);
        self::assertSame('2028-07-10', $due?->format('Y-m-d'));
    }

    public function test_by_fact_manual_date_overrides_limit(): void
    {
        $manual = new \DateTimeImmutable('2027-03-01');
        $due = $this->c->nextDue(new Cadence(CadenceKind::ByFact, 30, PeriodUnit::Month), $this->issued, $manual);
        self::assertEquals($manual, $due);
    }

    public function test_manufacturer_doc_uses_manual_date(): void
    {
        $manual = new \DateTimeImmutable('2027-03-01');
        self::assertEquals($manual, $this->c->nextDue(new Cadence(CadenceKind::ByManufacturerDoc), $this->issued, $manual));
        self::assertNull($this->c->nextDue(new Cadence(CadenceKind::ByManufacturerDoc), $this->issued, null));
    }

    public function test_not_fulfilled_has_no_due(): void
    {
        self::assertNull($this->c->nextDue(new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), null, null));
    }
}

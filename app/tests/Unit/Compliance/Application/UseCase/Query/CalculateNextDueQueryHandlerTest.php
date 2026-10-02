<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\UseCase\Query;

use App\Compliance\Application\UseCase\Query\CalculateNextDue\CalculateNextDueQuery;
use App\Compliance\Application\UseCase\Query\CalculateNextDue\CalculateNextDueQueryHandler;
use PHPUnit\Framework\TestCase;

final class CalculateNextDueQueryHandlerTest extends TestCase
{
    private CalculateNextDueQueryHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CalculateNextDueQueryHandler();
    }

    public function test_periodic_year(): void
    {
        $r = ($this->handler)(new CalculateNextDueQuery('2026-10-01', 'periodic', 1, 'year'));
        self::assertSame('2027-10-01', $r->nextDue);
    }

    public function test_periodic_month(): void
    {
        $r = ($this->handler)(new CalculateNextDueQuery('2026-10-01', 'periodic', 2, 'month'));
        self::assertSame('2026-12-01', $r->nextDue);
    }

    public function test_once_has_no_next(): void
    {
        $r = ($this->handler)(new CalculateNextDueQuery('2026-10-01', 'once', null, null));
        self::assertNull($r->nextDue);
    }
}

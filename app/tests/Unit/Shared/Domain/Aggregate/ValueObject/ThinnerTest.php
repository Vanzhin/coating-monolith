<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\Thinner;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ThinnerTest extends TestCase
{
    public function test_valid_thinner(): void
    {
        $t = Thinner::fromArray(['name' => 'Литум растворитель №10', 'batch' => 'LO00-4437.2.1', 'percent' => 7]);
        self::assertSame('Литум растворитель №10', $t->getName());
        self::assertSame('LO00-4437.2.1', $t->getBatch());
        self::assertSame(7.0, (float) $t->getPercent());
    }

    public function test_percent_over_100_rejected(): void
    {
        $this->expectException(AppException::class);
        Thinner::fromArray(['name' => 'X', 'batch' => 'Y', 'percent' => 150]);
    }

    public function test_negative_percent_rejected(): void
    {
        $this->expectException(AppException::class);
        Thinner::fromArray(['name' => 'X', 'batch' => 'Y', 'percent' => -1]);
    }
}

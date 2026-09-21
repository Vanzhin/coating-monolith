<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Domain\Aggregate\ValueObject\CoatingThickness;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class CoatingThicknessTest extends TestCase
{
    public function test_valid_thickness(): void
    {
        $t = CoatingThickness::fromArray(['min' => 88, 'max' => 297, 'mean' => 125]);
        self::assertSame(88.0, (float) $t->getMin());
        self::assertSame(297.0, (float) $t->getMax());
        self::assertSame(125.0, (float) $t->getMean());
        self::assertSame(['min' => 88.0, 'max' => 297.0, 'mean' => 125.0], $t->jsonSerialize());
    }

    public function test_min_greater_than_max_rejected(): void
    {
        $this->expectException(AppException::class);
        CoatingThickness::fromArray(['min' => 300, 'max' => 100, 'mean' => 200]);
    }

    public function test_negative_rejected(): void
    {
        $this->expectException(AppException::class);
        CoatingThickness::fromArray(['min' => -1, 'max' => 100, 'mean' => 50]);
    }

    public function test_zero_mean_rejected(): void
    {
        $this->expectException(AppException::class);
        CoatingThickness::fromArray(['min' => 10, 'max' => 100, 'mean' => 0]);
    }
}

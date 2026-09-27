<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class HeatingSchemeTest extends TestCase
{
    public function test_all_sides_exposes_every_face(): void
    {
        $scheme = HeatingScheme::allSides();
        self::assertTrue($scheme->top && $scheme->bottom && $scheme->left && $scheme->right);
    }

    public function test_three_sided_closes_the_top(): void
    {
        // балка под плитой: верх закрыт плитой, остальные три обогреваются
        $scheme = HeatingScheme::threeSided();
        self::assertFalse($scheme->top);
        self::assertTrue($scheme->bottom && $scheme->left && $scheme->right);
    }

    public function test_rejects_scheme_without_any_heated_face(): void
    {
        $this->expectException(AppException::class);
        new HeatingScheme(false, false, false, false);
    }
}

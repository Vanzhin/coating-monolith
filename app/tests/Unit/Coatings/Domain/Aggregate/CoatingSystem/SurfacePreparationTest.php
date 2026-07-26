<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SurfacePreparationTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $sp = new SurfacePreparation('Sa 2 1/2', 'Абразивоструйная очистка', 'ИСО 8501-1');
        self::assertSame('Sa 2 1/2', $sp->grade);
        self::assertSame('Абразивоструйная очистка', $sp->description);
        self::assertSame('ИСО 8501-1', $sp->standard);
    }

    public function test_standard_is_optional(): void
    {
        $sp = new SurfacePreparation('Wa 2', 'Гидроструйная');
        self::assertNull($sp->standard);
    }

    public function test_empty_grade_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('', 'x');
    }

    public function test_grade_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation(str_repeat('x', 31), '');
    }

    public function test_description_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', str_repeat('x', 501));
    }

    public function test_empty_standard_string_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', '', '');
    }

    public function test_standard_too_long_throws(): void
    {
        $this->expectException(AppException::class);
        new SurfacePreparation('Sa 3', '', str_repeat('x', 51));
    }
}

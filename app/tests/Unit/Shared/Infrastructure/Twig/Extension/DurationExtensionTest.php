<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Twig\Extension;

use App\Shared\Infrastructure\Twig\Extension\DurationExtension;
use Carbon\CarbonInterval;
use PHPUnit\Framework\TestCase;

class DurationExtensionTest extends TestCase
{
    private DurationExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new DurationExtension();
        CarbonInterval::setLocale('ru');
    }

    public function test_zero_minutes(): void
    {
        $this->assertSame('—', $this->ext->formatMinutes(null));
    }

    public function test_small_number_minutes(): void
    {
        // 12 минут
        $this->assertStringContainsString('12', $this->ext->formatMinutes(12));
        $this->assertStringContainsString('мин', $this->ext->formatMinutes(12));
    }

    public function test_cascade_into_hours(): void
    {
        // 150 минут = 2 часа 30 минут
        $out = $this->ext->formatMinutes(150);
        $this->assertStringContainsString('2', $out);
        $this->assertStringContainsString('ч', $out);
    }

    public function test_cascade_into_days(): void
    {
        // 14400 минут = 10 суток (Carbon cascades через неделю → "1 неделя 3 дня").
        // Проверяем, что cascade сработал: в выводе нет "минут" и нет необработанного "14400".
        $out = $this->ext->formatMinutes(14400);
        $this->assertStringNotContainsString('минут', $out);
        $this->assertStringNotContainsString('14400', $out);
    }

    public function test_accepts_carbon_interval(): void
    {
        $ci = CarbonInterval::hours(2)->minutes(30);
        $out = $this->ext->format($ci);
        $this->assertStringContainsString('2', $out);
    }
}

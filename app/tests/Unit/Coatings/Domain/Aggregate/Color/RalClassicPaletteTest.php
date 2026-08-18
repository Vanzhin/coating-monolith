<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Color;

use App\Coatings\Domain\Aggregate\Color\RalClassicPalette;
use App\Coatings\Domain\Aggregate\Color\RalColor;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RalClassicPaletteTest extends TestCase
{
    public function test_get_returns_color_by_exact_code(): void
    {
        $ral = RalClassicPalette::require('RAL 7040');

        self::assertInstanceOf(RalColor::class, $ral);
        self::assertSame('RAL 7040', $ral->code);
    }

    public function test_lookup_is_tolerant_to_input_format(): void
    {
        $canonical = RalClassicPalette::require('RAL 7040');

        self::assertEquals($canonical, RalClassicPalette::require('ral 7040'));
        self::assertEquals($canonical, RalClassicPalette::require('RAL7040'));
        self::assertEquals($canonical, RalClassicPalette::require('  7040 '));
    }

    public function test_try_get_returns_null_for_unknown_code(): void
    {
        self::assertNull(RalClassicPalette::tryGet('RAL 0000'));
    }

    public function test_require_throws_for_unknown_code(): void
    {
        $this->expectException(AppException::class);
        RalClassicPalette::require('RAL 0000');
    }

    public function test_all_is_not_empty(): void
    {
        self::assertNotEmpty(RalClassicPalette::all());
    }

    public function test_search_matches_by_code_fragment(): void
    {
        $codes = array_map(static fn (RalColor $c) => $c->code, RalClassicPalette::search('7040'));

        self::assertContains('RAL 7040', $codes);
    }

    public function test_search_matches_by_name_fragment_case_insensitive(): void
    {
        $codes = array_map(static fn (RalColor $c) => $c->code, RalClassicPalette::search('СЕРЫЙ'));

        self::assertContains('RAL 7040', $codes);
    }
}

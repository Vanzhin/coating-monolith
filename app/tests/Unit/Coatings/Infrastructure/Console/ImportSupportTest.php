<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Console;

use App\Coatings\Infrastructure\Console\ImportSupport;
use PHPUnit\Framework\TestCase;

class ImportSupportTest extends TestCase
{
    public function test_normalize_lowercases_and_collapses_spaces(): void
    {
        $this->assertSame('литапрайм цинк 80', ImportSupport::normalizeTitle('  ЛИТАПРАЙМ   Цинк 80 '));
    }

    public function test_normalize_treats_nbsp_as_space(): void
    {
        $this->assertSame('литакоут фрост', ImportSupport::normalizeTitle("Литакоут\u{00A0}Фрост"));
    }

    public function test_normalize_folds_yo_to_e(): void
    {
        $this->assertSame(ImportSupport::normalizeTitle('тёрка'), ImportSupport::normalizeTitle('терка'));
    }

    public function test_normalize_folds_latin_c_to_cyrillic(): void
    {
        // «Литатанк АС» (латинская C в источнике) == «ЛИТАТАНК АC» (в БД)
        $this->assertSame(
            ImportSupport::normalizeTitle('ЛИТАТАНК АC'),
            ImportSupport::normalizeTitle('Литатанк АС'),
        );
    }

    public function test_max_dft_from_range(): void
    {
        $this->assertSame(80, ImportSupport::maxDft('60-80'));
    }

    public function test_max_dft_from_single_value(): void
    {
        $this->assertSame(60, ImportSupport::maxDft('60'));
    }

    public function test_max_dft_ignores_surrounding_text(): void
    {
        $this->assertSame(180, ImportSupport::maxDft('160-180 мкм'));
    }

    public function test_max_dft_returns_null_for_non_numeric(): void
    {
        $this->assertNull(ImportSupport::maxDft('По ПТМ'));
    }

    public function test_max_dft_returns_null_for_empty(): void
    {
        $this->assertNull(ImportSupport::maxDft(''));
    }
}

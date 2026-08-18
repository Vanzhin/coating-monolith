<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Gloss;
use PHPUnit\Framework\TestCase;

final class GlossTest extends TestCase
{
    public function test_has_five_levels(): void
    {
        self::assertCount(5, Gloss::cases());
    }

    public function test_titles_are_russian(): void
    {
        self::assertSame('Глубокоматовый', Gloss::DEAD_MATTE->title());
        self::assertSame('Матовый', Gloss::MATTE->title());
        self::assertSame('Полуматовый', Gloss::SEMI_MATTE->title());
        self::assertSame('Полуглянцевый', Gloss::SEMI_GLOSS->title());
        self::assertSame('Глянцевый', Gloss::GLOSS->title());
    }
}

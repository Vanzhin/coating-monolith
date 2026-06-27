<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use PHPUnit\Framework\TestCase;

final class CoatingTagTest extends TestCase
{
    public function testTypeGeneralConstantValue(): void
    {
        self::assertSame('general', CoatingTag::TYPE_GENERAL);
    }
}

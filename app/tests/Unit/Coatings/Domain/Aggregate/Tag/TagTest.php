<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Tag;

use App\Coatings\Domain\Aggregate\Tag\Tag;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function test_type_general_constant_value(): void
    {
        self::assertSame('general', Tag::TYPE_GENERAL);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\Gender;
use PHPUnit\Framework\TestCase;

final class GenderTest extends TestCase
{
    public function test_male_title(): void
    {
        $this->assertSame('мужской', Gender::Male->title());
    }

    public function test_female_title(): void
    {
        $this->assertSame('женский', Gender::Female->title());
    }
}

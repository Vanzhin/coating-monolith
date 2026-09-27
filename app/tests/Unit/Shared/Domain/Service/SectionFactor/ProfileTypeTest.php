<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Service\SectionFactor\ProfileType;
use PHPUnit\Framework\TestCase;

final class ProfileTypeTest extends TestCase
{
    public function test_covers_all_seven_profile_types(): void
    {
        self::assertCount(7, ProfileType::cases());
    }

    public function test_human_readable_label(): void
    {
        self::assertSame('Двутавр', ProfileType::I_BEAM->label());
        self::assertSame('Круг', ProfileType::ROUND_BAR->label());
    }
}

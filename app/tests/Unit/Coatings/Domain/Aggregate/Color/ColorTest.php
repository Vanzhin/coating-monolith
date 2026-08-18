<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Color;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Color\RalClassicPalette;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ColorTest extends TestCase
{
    public function test_ral_color_derives_hex_from_palette(): void
    {
        $color = new Color($this->id(), 'Мой серый', 'RAL 7040');

        self::assertSame('RAL 7040', $color->getRal());
        self::assertSame(RalClassicPalette::require('RAL 7040')->hex->value, $color->getHex());
        self::assertSame('Мой серый', $color->getName());
    }

    public function test_ral_color_ignores_client_hex_and_uses_palette(): void
    {
        $color = new Color($this->id(), 'x', 'RAL 7040', '#000000');

        self::assertSame(RalClassicPalette::require('RAL 7040')->hex->value, $color->getHex());
    }

    public function test_ral_lookup_is_tolerant_to_input_format(): void
    {
        $color = new Color($this->id(), 'x', '7040');

        self::assertSame('RAL 7040', $color->getRal());
    }

    public function test_unknown_ral_code_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Color($this->id(), 'x', 'RAL 0000');
    }

    public function test_custom_color_requires_hex(): void
    {
        $this->expectException(AppException::class);
        new Color($this->id(), 'Кастомный', null, null);
    }

    public function test_custom_color_stores_hex_and_has_no_ral(): void
    {
        $color = new Color($this->id(), 'Кастомный', null, '#123abc');

        self::assertNull($color->getRal());
        self::assertSame('#123ABC', $color->getHex());
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Color($this->id(), '   ', null, '#123456');
    }

    private function id(): Uuid
    {
        return Uuid::v4();
    }
}

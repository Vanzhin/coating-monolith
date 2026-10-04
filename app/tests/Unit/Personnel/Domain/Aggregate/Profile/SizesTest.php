<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class SizesTest extends TestCase
{
    public function test_empty_has_all_null_fields(): void
    {
        $sizes = Sizes::empty();

        $this->assertNull($sizes->clothing);
        $this->assertNull($sizes->shoes);
        $this->assertNull($sizes->headgear);
        $this->assertNull($sizes->respirator);
        $this->assertNull($sizes->gloves);
        $this->assertNull($sizes->height);
        $this->assertNull($sizes->gender);
    }

    public function test_blank_input_normalizes_to_null(): void
    {
        $sizes = Sizes::fromInput(clothing: '   ', shoes: '', headgear: null);

        $this->assertNull($sizes->clothing);
        $this->assertNull($sizes->shoes);
        $this->assertNull($sizes->headgear);
    }

    public function test_parses_integer_and_decimal(): void
    {
        $sizes = Sizes::fromInput(clothing: '  52  ', shoes: '42.5', gloves: '9');

        $this->assertSame(52, $sizes->clothing?->value());
        $this->assertSame(42.5, $sizes->shoes?->value());
        $this->assertSame(9, $sizes->gloves?->value());
    }

    public function test_comma_decimal_is_accepted(): void
    {
        $sizes = Sizes::fromInput(shoes: '4,5');

        $this->assertSame(4.5, $sizes->shoes?->value());
    }

    public function test_non_numeric_throws(): void
    {
        $this->expectException(AppException::class);
        Sizes::fromInput(clothing: '52-54');
    }

    public function test_zero_throws(): void
    {
        $this->expectException(AppException::class);
        Sizes::fromInput(height: '0');
    }

    public function test_negative_throws(): void
    {
        $this->expectException(AppException::class);
        Sizes::fromInput(height: '-5');
    }

    public function test_round_trip_via_from_array(): void
    {
        $sizes = Sizes::fromInput('52', '42.5', '58', '3', '9', '180', Gender::Male);

        $restored = Sizes::fromArray($sizes->jsonSerialize());

        $this->assertSame($sizes->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_from_array_drops_legacy_non_numeric(): void
    {
        $sizes = Sizes::fromArray(['clothing' => '48фвацуйва', 'shoes' => '42', 'height' => 0]);

        $this->assertNull($sizes->clothing); // легаси-мусор не роняет загрузку
        $this->assertSame(42, $sizes->shoes?->value());
        $this->assertNull($sizes->height); // 0 не положительное
    }

    public function test_json_serialize_shape(): void
    {
        $sizes = Sizes::fromInput(clothing: '52', gender: Gender::Male);

        $this->assertSame(
            [
                'clothing' => 52,
                'shoes' => null,
                'headgear' => null,
                'respirator' => null,
                'gloves' => null,
                'height' => null,
                'gender' => 'male',
            ],
            $sizes->jsonSerialize(),
        );
    }

    public function test_gender_round_trip(): void
    {
        $sizes = Sizes::fromInput(gender: Gender::Female);

        $restored = Sizes::fromArray($sizes->jsonSerialize());

        $this->assertSame(Gender::Female, $restored->gender);
    }
}

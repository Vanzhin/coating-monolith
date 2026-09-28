<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use PHPUnit\Framework\TestCase;

final class SizesTest extends TestCase
{
    public function test_empty_has_all_null_fields(): void
    {
        $sizes = Sizes::empty();

        $this->assertNull($sizes->clothing);
        $this->assertNull($sizes->shoes);
        $this->assertNull($sizes->headgear);
        $this->assertNull($sizes->gasMask);
        $this->assertNull($sizes->respirator);
        $this->assertNull($sizes->gloves);
        $this->assertNull($sizes->height);
        $this->assertNull($sizes->gender);
    }

    public function test_blank_strings_normalize_to_null(): void
    {
        $sizes = new Sizes(
            clothing: '   ',
            shoes: '',
            headgear: '  ',
            gasMask: '',
            respirator: '   ',
            gloves: '',
            height: '  ',
        );

        $this->assertNull($sizes->clothing);
        $this->assertNull($sizes->shoes);
        $this->assertNull($sizes->headgear);
        $this->assertNull($sizes->gasMask);
        $this->assertNull($sizes->respirator);
        $this->assertNull($sizes->gloves);
        $this->assertNull($sizes->height);
    }

    public function test_values_are_trimmed(): void
    {
        $sizes = new Sizes(clothing: '  52-54  ', shoes: ' 42 ', height: ' 180 ');

        $this->assertSame('52-54', $sizes->clothing);
        $this->assertSame('42', $sizes->shoes);
        $this->assertSame('180', $sizes->height);
    }

    public function test_round_trip_via_from_array(): void
    {
        $sizes = new Sizes(
            clothing: '52-54',
            shoes: '42',
            headgear: '56',
            gasMask: '2',
            respirator: 'M',
            gloves: '9',
            height: '180',
            gender: Gender::Male,
        );

        $restored = Sizes::fromArray($sizes->jsonSerialize());

        $this->assertSame($sizes->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_round_trip_of_empty(): void
    {
        $sizes = Sizes::empty();

        $restored = Sizes::fromArray($sizes->jsonSerialize());

        $this->assertSame($sizes->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_gender_round_trip(): void
    {
        $sizes = new Sizes(gender: Gender::Female);

        $restored = Sizes::fromArray($sizes->jsonSerialize());

        $this->assertSame(Gender::Female, $restored->gender);
    }

    public function test_gender_null_when_absent(): void
    {
        $sizes = Sizes::fromArray([]);

        $this->assertNull($sizes->gender);
    }

    public function test_json_serialize_shape(): void
    {
        $sizes = new Sizes(clothing: '52-54', gender: Gender::Male);

        $this->assertSame(
            [
                'clothing' => '52-54',
                'shoes' => null,
                'headgear' => null,
                'gasMask' => null,
                'respirator' => null,
                'gloves' => null,
                'height' => null,
                'gender' => 'male',
            ],
            $sizes->jsonSerialize(),
        );
    }
}

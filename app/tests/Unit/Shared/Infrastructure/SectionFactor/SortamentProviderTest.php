<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\SectionFactor;

use App\Shared\Infrastructure\SectionFactor\SortamentProvider;
use PHPUnit\Framework\TestCase;

final class SortamentProviderTest extends TestCase
{
    public function test_provides_valid_json_with_version_and_four_typed_sortaments(): void
    {
        $data = json_decode((new SortamentProvider())->json(), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('version', $data);
        self::assertSame(['i_beam', 'channel', 'angle', 'rect_hollow'], array_keys($data['types']));
    }

    public function test_i_beam_leaf_uses_our_section_field_names(): void
    {
        $data = json_decode((new SortamentProvider())->json(), true);

        // каскад ГОСТ Р 57837: Тип Б → Номер 20 → Вариант 1 = двутавр 20Б1
        $leaf = $data['types']['i_beam']['standards']['gost-r-57837-2017']['tree']['Б']['20']['1'];
        self::assertSame(200, $leaf['height']);
        self::assertSame(100, $leaf['flangeWidth']);
        self::assertSame(5.5, $leaf['webThickness']);
    }

    public function test_cascade_levels_are_declared_per_standard(): void
    {
        $data = json_decode((new SortamentProvider())->json(), true);

        self::assertSame(['Тип', 'Номер', 'Вариант'], $data['types']['i_beam']['standards']['gost-r-57837-2017']['levels']);
        self::assertSame(['Серия', 'Номер'], $data['types']['channel']['standards']['gost-8240-97']['levels']);
        self::assertSame(['Размер'], $data['types']['angle']['standards']['gost-8509-93']['levels']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\ValueObject;

use App\Personnel\Domain\ValueObject\Reference;
use PHPUnit\Framework\TestCase;

class ReferenceTest extends TestCase
{
    public function test_from_array_round_trip_preserves_id_and_title(): void
    {
        $reference = new Reference('11111111-1111-1111-1111-111111111111', 'Маляр');

        $restored = Reference::fromArray($reference->jsonSerialize());

        self::assertSame($reference->id, $restored->id);
        self::assertSame($reference->title, $restored->title);
    }

    public function test_json_serialize_returns_id_and_title(): void
    {
        $reference = new Reference('id-1', 'Цех №1');

        self::assertSame(['id' => 'id-1', 'title' => 'Цех №1'], $reference->jsonSerialize());
    }

    public function test_from_array_defaults_missing_keys_to_empty_string(): void
    {
        $reference = Reference::fromArray([]);

        self::assertSame('', $reference->id);
        self::assertSame('', $reference->title);
    }
}

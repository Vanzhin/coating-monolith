<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject;

use App\Compliance\Domain\ValueObject\ObligationKey;
use PHPUnit\Framework\TestCase;

final class ObligationKeyTest extends TestCase
{
    public function test_key_of_joins_requirement_and_item_with_pipe(): void
    {
        self::assertSame('req-42|item-7', ObligationKey::keyOf('req-42', 'item-7'));
    }

    public function test_key_of_trims_suffix(): void
    {
        self::assertSame('req-1|item-1', ObligationKey::keyOf('req-1', '  item-1  '));
    }

    public function test_requirement_id_is_prefix_before_pipe(): void
    {
        self::assertSame('req-42', ObligationKey::requirementIdOf('req-42|item-7'));
    }

    public function test_requirement_id_of_throws_without_separator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ObligationKey::requirementIdOf('нет-разделителя');
    }

    public function test_key_belongs_to_requirement_matches_own_prefix(): void
    {
        self::assertTrue(ObligationKey::keyBelongsToRequirement('req-1|item-1', 'req-1'));
    }

    public function test_key_belongs_to_requirement_rejects_other_requirement(): void
    {
        self::assertFalse(ObligationKey::keyBelongsToRequirement('req-1|item-1', 'req-2'));
    }

    public function test_key_belongs_to_requirement_rejects_prefix_without_pipe_boundary(): void
    {
        // "req-1" не должен матчить требование "req-" (строгая граница — только через '|').
        self::assertFalse(ObligationKey::keyBelongsToRequirement('req-1|item-1', 'req'));
    }
}

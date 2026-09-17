<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class ChangeSetTest extends TestCase
{
    public function test_empty(): void
    {
        self::assertTrue((new ChangeSet())->isEmpty());
    }

    public function test_round_trip(): void
    {
        $set = new ChangeSet(FieldChange::set('a', 1, 2), FieldChange::remove('b', 'x'));
        $json = $set->jsonSerialize();
        self::assertSame([['op' => 'set', 'path' => 'a', 'old' => 1, 'new' => 2], ['op' => 'remove', 'path' => 'b', 'old' => 'x']], $json);
        self::assertSame('a', ChangeSet::fromArray($json)->all()[0]->path);
    }
}

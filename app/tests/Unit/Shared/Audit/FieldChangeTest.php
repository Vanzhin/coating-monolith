<?php
declare(strict_types=1);
namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class FieldChangeTest extends TestCase
{
    public function testSetRoundTrip(): void
    {
        $c = FieldChange::set('title', 'X', 'Y');
        self::assertSame(['op' => 'set', 'path' => 'title', 'old' => 'X', 'new' => 'Y'], $c->jsonSerialize());
        $b = FieldChange::fromArray($c->jsonSerialize());
        self::assertSame([ChangeOp::Set, 'title', 'X', 'Y'], [$b->op, $b->path, $b->old, $b->new]);
    }

    public function testAddOmitsOld(): void
    {
        $c = FieldChange::add('children.immersion', ['default' => []]);
        self::assertSame(['op' => 'add', 'path' => 'children.immersion', 'new' => ['default' => []]], $c->jsonSerialize());
        self::assertSame(ChangeOp::Add, FieldChange::fromArray($c->jsonSerialize())->op);
    }

    public function testRemoveOmitsNew(): void
    {
        $c = FieldChange::remove('default', ['temperature_at' => 35]);
        self::assertSame(['op' => 'remove', 'path' => 'default', 'old' => ['temperature_at' => 35]], $c->jsonSerialize());
        self::assertSame(ChangeOp::Remove, FieldChange::fromArray($c->jsonSerialize())->op);
    }
}

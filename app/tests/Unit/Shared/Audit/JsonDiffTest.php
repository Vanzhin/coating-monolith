<?php
declare(strict_types=1);
namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\JsonDiff;
use PHPUnit\Framework\TestCase;

final class JsonDiffTest extends TestCase
{
    private JsonDiff $d;
    protected function setUp(): void { $this->d = new JsonDiff(); }

    public function testScalarSet(): void
    {
        $o = $this->d->diff('X', 'Y', 'title');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'title', 'X', 'Y'], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }

    public function testEqualProducesNothing(): void
    {
        self::assertSame([], $this->d->diff(5, 5, 'x'));
        $mk = static fn (): object => new class implements \JsonSerializable { public function jsonSerialize(): array { return ['a' => 1]; } };
        self::assertSame([], $this->d->diff($mk(), $mk(), 'vo'));
    }

    public function testNestedMapDrillsToScalar(): void
    {
        $o = $this->d->diff(['min' => 100, 'max' => 350], ['min' => 120, 'max' => 350], 'dftRange');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'dftRange.min'], [$o[0]->op, $o[0]->path]);
    }

    public function testMapKeyAddedThenRemoved(): void
    {
        $old = ['children' => []];
        $new = ['children' => ['immersion' => ['x' => 1]]];
        $o = $this->d->diff($old, $new, 'tree');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Add, 'tree.children.immersion'], [$o[0]->op, $o[0]->path]);
        self::assertSame(['x' => 1], $o[0]->new);

        $o2 = $this->d->diff($new, $old, 'tree');
        self::assertSame([ChangeOp::Remove, 'tree.children.immersion'], [$o2[0]->op, $o2[0]->path]);
    }

    public function testListElementAddRemove(): void
    {
        $old = [['t' => 20, 'm' => 240], ['t' => 35, 'm' => 120], ['t' => 40, 'm' => 90]];
        $new = [['t' => 20, 'm' => 360], ['t' => 40, 'm' => 90]]; // 20 изменён, 35 удалён, 40 без изменений
        $o = $this->d->diff($old, $new, 'default');
        $ops = array_map(static fn ($c) => [$c->op, $c->path], $o);
        self::assertCount(3, $o); // remove{20,240}, remove{35,120}, add{20,360}
        self::assertContains([ChangeOp::Remove, 'default'], $ops);
        self::assertContains([ChangeOp::Add, 'default'], $ops);
    }

    public function testVoNormalizedThenDiffed(): void
    {
        $mk = static fn (int $b): object => new class($b) implements \JsonSerializable {
            public function __construct(private int $b) {}
            public function jsonSerialize(): array { return ['a' => 1, 'b' => $this->b]; }
        };
        $o = $this->d->diff($mk(2), $mk(9), 'vo');
        self::assertSame([ChangeOp::Set, 'vo.b'], [$o[0]->op, $o[0]->path]);
    }

    public function testEmptyListGainsElements(): void
    {
        $o = $this->d->diff([], [1, 2, 3], 'tags');
        self::assertCount(3, $o);
        $ops = array_map(static fn ($c) => [$c->op, $c->path, $c->new], $o);
        self::assertContains([ChangeOp::Add, 'tags', 1], $ops);
        self::assertContains([ChangeOp::Add, 'tags', 2], $ops);
        self::assertContains([ChangeOp::Add, 'tags', 3], $ops);
    }
}

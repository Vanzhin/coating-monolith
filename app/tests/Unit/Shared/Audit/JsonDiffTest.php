<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\JsonDiff;
use PHPUnit\Framework\TestCase;

final class JsonDiffTest extends TestCase
{
    private JsonDiff $d;

    protected function setUp(): void
    {
        $this->d = new JsonDiff();
    }

    public function test_scalar_set(): void
    {
        $o = $this->d->diff('X', 'Y', 'title');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'title', 'X', 'Y'], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }

    public function test_equal_produces_nothing(): void
    {
        self::assertSame([], $this->d->diff(5, 5, 'x'));
        $mk = static fn (): object => new class implements \JsonSerializable {
            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return ['a' => 1];
            }
        };
        self::assertSame([], $this->d->diff($mk(), $mk(), 'vo'));
    }

    public function test_nested_map_drills_to_scalar(): void
    {
        $o = $this->d->diff(['min' => 100, 'max' => 350], ['min' => 120, 'max' => 350], 'dftRange');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'dftRange.min'], [$o[0]->op, $o[0]->path]);
    }

    public function test_map_key_added_then_removed(): void
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

    public function test_list_element_keyed_diff_uses_unique_scalar_field(): void
    {
        // T1: 't' — единственный общий уникальный скаляр во всех элементах old И new →
        // выбирается ключом идентичности. Правка внутри элемента (m: 240→360 у t=20)
        // теперь даёт точечный set по под-пути, а не remove+add всего элемента целиком
        // (старое поведение этого теста, актуальное до keyed-diff).
        $old = [['t' => 20, 'm' => 240], ['t' => 35, 'm' => 120], ['t' => 40, 'm' => 90]];
        $new = [['t' => 20, 'm' => 360], ['t' => 40, 'm' => 90]]; // 20 изменён, 35 удалён, 40 без изменений
        $o = $this->d->diff($old, $new, 'default');
        self::assertCount(2, $o); // set{20.m: 240→360}, remove{35}; 40 не изменился — не попал в дифф
        $shapes = array_map(static fn ($c) => [$c->op, $c->path, $c->old, $c->new], $o);
        self::assertContains([ChangeOp::Set, 'default.20.m', 240, 360], $shapes);
        self::assertContains([ChangeOp::Remove, 'default.35', ['t' => 35, 'm' => 120], null], $shapes);
    }

    public function test_vo_normalized_then_diffed(): void
    {
        $mk = static fn (int $b): object => new class($b) implements \JsonSerializable {
            public function __construct(private int $b)
            {
            }

            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return ['a' => 1, 'b' => $this->b];
            }
        };
        $o = $this->d->diff($mk(2), $mk(9), 'vo');
        self::assertSame([ChangeOp::Set, 'vo.b'], [$o[0]->op, $o[0]->path]);
    }

    public function test_empty_list_gains_elements(): void
    {
        $o = $this->d->diff([], [1, 2, 3], 'tags');
        self::assertCount(3, $o);
        $ops = array_map(static fn ($c) => [$c->op, $c->path, $c->new], $o);
        self::assertContains([ChangeOp::Add, 'tags', 1], $ops);
        self::assertContains([ChangeOp::Add, 'tags', 2], $ops);
        self::assertContains([ChangeOp::Add, 'tags', 3], $ops);
    }

    public function test_keyed_list_point_edited_gives_pointwise_set(): void
    {
        // Кейс 1 брифа: правка одной точки серии → ровно один set по под-пути точки,
        // а не remove+add всего списка/элемента.
        $old = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
        ];
        $new = [
            ['temperature_at' => 5, 'time_in_minutes' => 900, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
        ];
        $o = $this->d->diff($old, $new, 'f');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'f.5.time_in_minutes', 960, 900], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }

    public function test_keyed_list_point_added_gives_pointwise_add(): void
    {
        // Кейс 2 брифа: новая точка → ровно один add по под-пути точки.
        $old = [['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false]];
        $newPoint = ['temperature_at' => 15, 'time_in_minutes' => 240, 'is_calculated' => false];
        $new = [$old[0], $newPoint];
        $o = $this->d->diff($old, $new, 'f');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Add, 'f.15', $newPoint], [$o[0]->op, $o[0]->path, $o[0]->new]);
    }

    public function test_keyed_list_point_removed_gives_pointwise_remove(): void
    {
        // Кейс 3 брифа: убранная точка → ровно один remove по под-пути точки.
        $removedPoint = ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false];
        $old = [['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false], $removedPoint];
        $new = [$old[0]];
        $o = $this->d->diff($old, $new, 'f');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Remove, 'f.10', $removedPoint], [$o[0]->op, $o[0]->path, $o[0]->old]);
    }

    public function test_scalar_list_still_falls_back_to_equality(): void
    {
        // Кейс 4 брифа: список скаляров — элементы не мапы, ключа идентичности не бывает,
        // поведение как раньше (remove старого + add нового, оба по базовому пути).
        $o = $this->d->diff(['a', 'b'], ['a', 'c'], 'f');
        self::assertCount(2, $o);
        $shapes = array_map(static fn ($c) => [$c->op, $c->path, $c->old, $c->new], $o);
        self::assertContains([ChangeOp::Remove, 'f', 'b', null], $shapes);
        self::assertContains([ChangeOp::Add, 'f', null, 'c'], $shapes);
    }

    public function test_map_list_without_unique_key_falls_back_to_equality(): void
    {
        // Кейс 5 брифа: у элементов old есть дубли по каждому полю (a и b повторяются) —
        // ни одно поле не уникально внутри old, кандидата на ключ идентичности нет →
        // fallback к глубокому равенству, как раньше (remove/add элементов целиком).
        $old = [['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'x']];
        $new = [['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'z']];
        $o = $this->d->diff($old, $new, 'f');
        self::assertCount(2, $o); // remove{a:1,b:x} (вторая копия), add{a:1,b:z}
        $shapes = array_map(static fn ($c) => [$c->op, $c->path, $c->old, $c->new], $o);
        self::assertContains([ChangeOp::Remove, 'f', ['a' => 1, 'b' => 'x'], null], $shapes);
        self::assertContains([ChangeOp::Add, 'f', null, ['a' => 1, 'b' => 'z']], $shapes);
    }

    public function test_recoating_tree_default_series_point_edited_gives_pointwise_set(): void
    {
        // Кейс 6 брифа: дерево перекрытия — правка точки внутри default-серии композитно
        // проходит map-drill (default/children) → keyed-diff списка → point-diff.
        $old = ['default' => [['temperature_at' => 20, 'time_in_minutes' => 540, 'is_calculated' => false]], 'children' => []];
        $new = ['default' => [['temperature_at' => 20, 'time_in_minutes' => 480, 'is_calculated' => false]], 'children' => []];
        $o = $this->d->diff($old, $new, 'mri');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'mri.default.20.time_in_minutes', 540, 480], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }

    public function test_keyed_diff_is_generic_and_not_tied_to_temperature_field(): void
    {
        // Кейс 8 брифа: ключ идентичности — "id", а не "temperature_at" — доказывает,
        // что движок структурный, а не привязан к конкретному доменному полю.
        $old = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $new = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'c']];
        $o = $this->d->diff($old, $new, 'f');
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, 'f.2.name', 'b', 'c'], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }

    public function test_keyed_diff_without_path_prefix_has_no_leading_dot(): void
    {
        // Minor из ревью: diff() без префикса пути — сегмент по ключу идентичности не
        // должен начинаться с точки (симметрично diffMap, где '' === $path даёт (string) $key).
        $old = [
            ['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
        ];
        $new = [
            ['temperature_at' => 5, 'time_in_minutes' => 900, 'is_calculated' => false],
            ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false],
        ];
        $o = $this->d->diff($old, $new);
        self::assertCount(1, $o);
        self::assertSame([ChangeOp::Set, '5.time_in_minutes', 960, 900], [$o[0]->op, $o[0]->path, $o[0]->old, $o[0]->new]);
    }
}

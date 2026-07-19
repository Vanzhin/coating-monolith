<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use PHPUnit\Framework\TestCase;

final class RecoatingIntervalTreeTest extends TestCase
{
    public function test_leaf_stores_default(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $tree = new RecoatingIntervalTree($series);

        $this->assertSame($series, $tree->default);
        $this->assertSame([], $tree->getChildren());
        $this->assertSame('default', $tree->key);
    }

    public function test_nested_tree_stores_children(): void
    {
        $epSeries = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $envDefault = new DryingTimeSeries(new TimeAtTemperature(20, 7));
        $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14));

        $envBranch = new RecoatingIntervalTree(
            $envDefault,
            'atmospheric',
            new RecoatingIntervalTree($epSeries, 'EP'),
        );
        $root = new RecoatingIntervalTree(
            $globalDefault,
            'default',
            $envBranch,
        );

        $children = $root->getChildren();
        $this->assertArrayHasKey('atmospheric', $children);
        $atmChildren = $children['atmospheric']->getChildren();
        $this->assertArrayHasKey('ep', $atmChildren);
        $this->assertSame($epSeries, $atmChildren['ep']->default);
    }

    public function test_key_is_normalized_to_lowercase_trimmed(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));

        $tree = new RecoatingIntervalTree($series, '  Atmospheric  ');

        $this->assertSame('atmospheric', $tree->key);
    }

    public function test_with_child_returns_new_instance_and_does_not_mutate_original(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));
        $root = new RecoatingIntervalTree($series);
        $child = new RecoatingIntervalTree($series, 'atmospheric');

        $updated = $root->withChild($child);

        $this->assertNotSame($root, $updated);
        $this->assertSame([], $root->getChildren(), 'оригинал остался пустым');
        $this->assertArrayHasKey('atmospheric', $updated->getChildren());
    }

    public function test_without_child_returns_new_instance_and_does_not_mutate_original(): void
    {
        $series = new DryingTimeSeries(new TimeAtTemperature(20, 10));
        $root = new RecoatingIntervalTree(
            $series,
            'default',
            new RecoatingIntervalTree($series, 'atmospheric'),
        );

        $updated = $root->withoutChild('atmospheric');

        $this->assertNotSame($root, $updated);
        $this->assertArrayHasKey('atmospheric', $root->getChildren());
        $this->assertSame([], $updated->getChildren());
    }

    public function test_json_serialize_produces_nested_structure(): void
    {
        $epSeries = new DryingTimeSeries(new TimeAtTemperature(20, 30));
        $envDefault = new DryingTimeSeries(new TimeAtTemperature(20, 7));
        $globalDefault = new DryingTimeSeries(new TimeAtTemperature(20, 14));

        $tree = new RecoatingIntervalTree(
            $globalDefault,
            'default',
            new RecoatingIntervalTree(
                $envDefault,
                'atmospheric',
                new RecoatingIntervalTree($epSeries, 'EP'),
            ),
        );

        $expected = [
            'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
            'children' => [
                'atmospheric' => [
                    'default' => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                    'children' => [
                        'ep' => [
                            'default' => [['temperature_at' => 20, 'time_in_minutes' => 30, 'is_calculated' => false]],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, json_decode(json_encode($tree), true));
    }

    public function test_from_array_restores_nested_structure(): void
    {
        $raw = [
            'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
            'children' => [
                'atmospheric' => [
                    'default' => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                    'children' => [
                        'ep' => [
                            'default' => [['temperature_at' => 20, 'time_in_minutes' => 30, 'is_calculated' => false]],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ];

        $tree = RecoatingIntervalTree::fromArray($raw);
        $children = $tree->getChildren();

        $this->assertSame(14, $tree->default->points[0]->timeInMinutes);
        $this->assertSame(7, $children['atmospheric']->default->points[0]->timeInMinutes);
        $this->assertSame(30, $children['atmospheric']->getChildren()['ep']->default->points[0]->timeInMinutes);
        $this->assertSame($raw, json_decode(json_encode($tree), true));
    }

    public function test_from_array_uses_outer_key_as_authoritative(): void
    {
        $raw = [
            'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
            'children' => [
                'atmospheric' => [
                    'default' => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                    'children' => [],
                ],
            ],
        ];

        $tree = RecoatingIntervalTree::fromArray($raw);

        $this->assertSame('default', $tree->key);
        $this->assertArrayHasKey('atmospheric', $tree->getChildren());
        $this->assertSame('atmospheric', $tree->getChildren()['atmospheric']->key);
    }

    public function test_from_array_ignores_legacy_inner_key_field(): void
    {
        // Старые записи в БД содержат поле 'key' внутри узла; внешний ключ родительского
        // children-словаря авторитетен, а внутреннее 'key' молча игнорируется (даже если несовпадает).
        $raw = [
            'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
            'children' => [
                'atmospheric' => [
                    'key' => 'immersion', // mismatch — игнорируется
                    'default' => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                    'children' => [],
                ],
            ],
        ];

        $tree = RecoatingIntervalTree::fromArray($raw);

        $this->assertArrayHasKey('atmospheric', $tree->getChildren());
        $this->assertSame('atmospheric', $tree->getChildren()['atmospheric']->key);
    }

    public function test_from_array_without_children_key(): void
    {
        $raw = [
            'default' => [['temperature_at' => 20, 'time_in_minutes' => 14, 'is_calculated' => false]],
        ];

        $tree = RecoatingIntervalTree::fromArray($raw);

        $this->assertSame([], $tree->getChildren());
    }

    public function test_from_array_throws_on_missing_default(): void
    {
        $raw = [
            'children' => [
                'atmospheric' => [
                    'default' => [['temperature_at' => 20, 'time_in_minutes' => 7, 'is_calculated' => false]],
                    'children' => [],
                ],
            ],
        ];

        $threw = false;
        try {
            RecoatingIntervalTree::fromArray($raw);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected fromArray to throw on missing "default" key');
    }
}

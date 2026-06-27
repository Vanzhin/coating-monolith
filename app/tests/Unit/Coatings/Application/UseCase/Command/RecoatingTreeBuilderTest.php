<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Application\UseCase\Command\RecoatingTreeBuilder;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RecoatingTreeBuilderTest extends TestCase
{
    public function testReturnsNullWhenNodeAndChildrenAreEmpty(): void
    {
        $tree = (new RecoatingTreeBuilder())->build(new RecoatingIntervalTreeDTO());
        $this->assertNull($tree);
    }

    public function testBuildsFlatTreeFromDefaultOnly(): void
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = [$this->point(20, 60)];

        $tree = (new RecoatingTreeBuilder())->build($dto);

        $this->assertNotNull($tree);
        $this->assertSame('default', $tree->key);
        $this->assertSame([], $tree->getChildren());
        $this->assertSame(60, $tree->default->points[0]->timeInMinutes);
    }

    public function testBuildsNestedBranches(): void
    {
        $root = new RecoatingIntervalTreeDTO();
        $root->default = [$this->point(20, 60)];
        $env = new RecoatingIntervalTreeDTO();
        $env->default = [$this->point(20, 30)];
        $root->branches['atmospheric'] = $env;

        $tree = (new RecoatingTreeBuilder())->build($root);

        $this->assertNotNull($tree);
        $this->assertArrayHasKey('atmospheric', $tree->getChildren());
        $this->assertSame(30, $tree->getChildren()['atmospheric']->default->points[0]->timeInMinutes);
    }

    public function testThrowsWhenDefaultEmptyButChildrenPresent(): void
    {
        $root = new RecoatingIntervalTreeDTO();
        $env = new RecoatingIntervalTreeDTO();
        $env->default = [$this->point(20, 30)];
        $root->branches['atmospheric'] = $env;

        $this->expectException(AppException::class);
        (new RecoatingTreeBuilder())->build($root);
    }

    public function testBuildMinTreeAcceptsPositiveDuration(): void
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = [$this->point(20, 60)];

        $tree = (new RecoatingTreeBuilder())->buildMinTree($dto);

        $this->assertNotNull($tree);
        $this->assertSame(60, $tree->default->points[0]->timeInMinutes);
    }

    public function testBuildMinTreeThrowsWhenPointIsNull(): void
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = [$this->pointWithNull(20)];

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/\+20°C/');
        (new RecoatingTreeBuilder())->buildMinTree($dto);
    }

    public function testBuildMinTreeThrowsWhenPointIsZero(): void
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = [$this->point(5, 0)];

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/\+5°C/');
        (new RecoatingTreeBuilder())->buildMinTree($dto);
    }

    public function testBuildMinTreeThrowsWhenNestedPointIsNull(): void
    {
        $root = new RecoatingIntervalTreeDTO();
        $root->default = [$this->point(20, 60)];
        $env = new RecoatingIntervalTreeDTO();
        $env->default = [$this->pointWithNull(20)];
        $root->branches['atmospheric'] = $env;

        $this->expectException(AppException::class);
        (new RecoatingTreeBuilder())->buildMinTree($root);
    }

    public function testBuildDoesNotValidateDurationForMax(): void
    {
        $dto = new RecoatingIntervalTreeDTO();
        $dto->default = [$this->pointWithNull(20)];

        // build() (max-side) должен пропускать null без исключения
        $tree = (new RecoatingTreeBuilder())->build($dto);
        $this->assertNotNull($tree);
        $this->assertNull($tree->default->points[0]->timeInMinutes);
    }

    private function point(int $temp, int $minutes): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $temp;
        $p->time_in_minutes = $minutes;
        $p->is_calculated = false;
        return $p;
    }

    private function pointWithNull(int $temp): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $temp;
        $p->time_in_minutes = null;
        $p->is_calculated = false;
        return $p;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Application\Service\CoatingTimeMatrixBuilder;
use PHPUnit\Framework\TestCase;

final class CoatingTimeMatrixBuilderTest extends TestCase
{
    public function testColumnsStepBy10PlusReference23(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-10, 50));

        // -10, 0, 10, 20, 30, 40, 50 + 23 (лабораторная), отсортировано.
        self::assertSame([-10, 0, 10, 20, 23, 30, 40, 50], $matrix['columns']);
    }

    public function testColumnsAppendMaxWhenStepDoesNotAlign(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-5, 45));

        // -5, 5, 15, 25, 35, 45 + 23 → отсортировано.
        self::assertSame([-5, 5, 15, 23, 25, 35, 45], $matrix['columns']);
    }

    public function testColumnsAppendMaxWhenGapAtEnd(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-10, 47));

        // -10, 0, 10, 20, 30, 40, 47 + 23 (в диапазоне).
        self::assertSame([-10, 0, 10, 20, 23, 30, 40, 47], $matrix['columns']);
    }

    public function testReferenceTempSkippedWhenOutsideRange(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(30, 50));

        // 23 вне [30, 50] — не добавляется.
        self::assertSame([30, 40, 50], $matrix['columns']);
    }

    public function testDefinedPointTemperatureAlwaysPresentInColumns(): void
    {
        // Регрессия: app_min=5, step-10 даёт [5,15,25,35,45,50]+23 = [5,15,23,25,35,45,50].
        // Точка на 20 без этого фикса потерялась бы между 15 и 23 → секция скипалась
        // как «all empty».
        $coating = $this->coating(5, 50, dryToTouch: [$this->point(20, 60)]);
        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);

        self::assertContains(20, $matrix['columns']);
        self::assertSame(60, $matrix['rows'][0]['values'][20]['minutes']);
    }

    public function testDefinedPointOutsideRangeIsIgnored(): void
    {
        // Точка на 60 при drying_max=50 не должна расширять колонки за max.
        // (Такой сценарий сам по себе нарушает инвариант домена, но builder
        // работает с DTO и должен быть defensive.)
        $coating = $this->coating(0, 50, dryToTouch: [$this->point(60, 30)]);
        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);

        self::assertNotContains(60, $matrix['columns']);
    }

    public function testReferenceTempNotDuplicatedIfStepAlignsWithIt(): void
    {
        // Если max = 23, шаг попадает: 3, 13, 23. 23 уже там.
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(3, 23));

        self::assertSame([3, 13, 23], $matrix['columns']);
    }

    public function testExactPointGivesRawValue(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        $row = $matrix['rows'][0];

        self::assertSame('Сухой на отлип', $row['label']);
        self::assertSame(['minutes' => 60, 'is_calculated' => false], $row['values'][20]);
    }

    public function testLinearInterpolationBetweenPoints(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(0, 100),
            $this->point(20, 20),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        // 10°C = ровно между 0 и 20; 100→20 → 60.
        self::assertSame(['minutes' => 60, 'is_calculated' => true], $matrix['rows'][0]['values'][10]);
    }

    public function testOutsideRangeIsNull(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
            $this->point(30, 40),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][0]);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][40]);
    }

    public function testUnlimitedBoundKillsInterpolation(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
            $this->point(30, 0),  // 0 = unlimited
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][20 + 5] ?? ['minutes' => null, 'is_calculated' => false]);
    }

    public function testEmptyDryToTouchProducesNoRow(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: []);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        $labels = array_column($matrix['rows'], 'label');
        self::assertNotContains('Сухой на отлип', $labels);
    }

    public function testRecoatingEnvBranchesGetOwnRows(): void
    {
        $minTree = new RecoatingIntervalTreeDTO();
        $minTree->default = [$this->point(20, 240)];

        $envBranch = new RecoatingIntervalTreeDTO();
        $envBranch->default = [$this->point(20, 120)];
        $minTree->branches['immersion'] = $envBranch;

        $coating = $this->coating(0, 50, minRecoatingTree: $minTree);
        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);

        $labels = array_column($matrix['rows'], 'label');
        self::assertContains('Интервал перекрытия (мин)', $labels);
        self::assertContains('Интервал перекрытия (мин), эксплуатация при погружении', $labels);
    }

    /** @param list<DryingTimePointDTO> $dryToTouch */
    private function coating(
        int $appMin,
        int $dryingMax,
        array $dryToTouch = [],
        array $fullCure = [],
        ?RecoatingIntervalTreeDTO $minRecoatingTree = null,
    ): CoatingDTO {
        $c = new CoatingDTO();
        $c->applicationMinTemp = $appMin;
        $c->dryingMaxTemp = $dryingMax;
        $c->dryToTouch = $dryToTouch;
        $c->fullCure = $fullCure;
        $c->minRecoatingInterval = $minRecoatingTree ?? new RecoatingIntervalTreeDTO();
        $c->maxRecoatingInterval = null;
        return $c;
    }

    private function point(int $tempAt, ?int $timeInMinutes, bool $isCalculated = false): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $tempAt;
        $p->time_in_minutes = $timeInMinutes;
        $p->is_calculated = $isCalculated;
        return $p;
    }
}

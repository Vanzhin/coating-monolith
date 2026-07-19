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
    public function test_columns_step_by10_from_min_to_max(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-10, 50));

        // -10, 0, 10, 20, 30, 40, 50 — все совпадают с шагом; 0 и 20 обязательные,
        // но они уже там.
        self::assertSame([-10, 0, 10, 20, 30, 40, 50], $matrix['columns']);
    }

    public function test_columns_add_mandatory_temps_between_step(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-5, 45));

        // step: -5, 5, 15, 25, 35, 45. Обязательные 0 и 20 в диапазоне → вставляются.
        self::assertSame([-5, 0, 5, 15, 20, 25, 35, 45], $matrix['columns']);
    }

    public function test_columns_append_max_when_gap_at_end(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(-10, 47));

        // step: -10, 0, 10, 20, 30, 40 + max 47. 0 и 20 уже там.
        self::assertSame([-10, 0, 10, 20, 30, 40, 47], $matrix['columns']);
    }

    public function test_mandatory_temps_skipped_when_outside_range(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(30, 50));

        // 0 и 20 вне [30, 50] — не добавляются.
        self::assertSame([30, 40, 50], $matrix['columns']);
    }

    public function test_only_one_mandatory_in_range(): void
    {
        $matrix = (new CoatingTimeMatrixBuilder())->build($this->coating(10, 30));

        // step: 10, 20, 30. 0 вне диапазона; 20 уже там.
        self::assertSame([10, 20, 30], $matrix['columns']);
    }

    public function test_exact_point_gives_raw_value(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        $row = $matrix['rows'][0];

        self::assertSame('Сухой на отлип', $row['label']);
        self::assertSame(['minutes' => 60, 'is_calculated' => false], $row['values'][20]);
    }

    public function test_linear_interpolation_between_points(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(0, 100),
            $this->point(20, 20),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        // 10°C = ровно между 0 и 20; 100→20 → 60.
        self::assertSame(['minutes' => 60, 'is_calculated' => true], $matrix['rows'][0]['values'][10]);
    }

    public function test_outside_range_is_null(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
            $this->point(30, 40),
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][0]);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][40]);
    }

    public function test_unlimited_bound_kills_interpolation(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: [
            $this->point(20, 60),
            $this->point(30, 0),  // 0 = unlimited
        ]);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        self::assertSame(['minutes' => null, 'is_calculated' => false], $matrix['rows'][0]['values'][20 + 5] ?? ['minutes' => null, 'is_calculated' => false]);
    }

    public function test_empty_dry_to_touch_produces_no_row(): void
    {
        $coating = $this->coating(0, 50, dryToTouch: []);

        $matrix = (new CoatingTimeMatrixBuilder())->build($coating);
        $labels = array_column($matrix['rows'], 'label');
        self::assertNotContains('Сухой на отлип', $labels);
    }

    public function test_recoating_env_branches_get_own_rows(): void
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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Application\Service\CoatingCompareMatrixBuilder;
use App\Coatings\Application\Service\CoatingTimeMatrixBuilder;
use PHPUnit\Framework\TestCase;

final class CoatingCompareMatrixBuilderTest extends TestCase
{
    public function testColumnsAreUnionAcrossSubjects(): void
    {
        $a = $this->coating(0, 20, dryToTouch: [$this->point(20, 60)]);
        $b = $this->coating(10, 30, dryToTouch: [$this->point(20, 90)]);

        $sections = $this->builder()->build([$a, $b]);

        // a: 0,10,20  b: 10,20,30 (0 вне диапазона у b) → union: 0,10,20,30.
        self::assertSame([0, 10, 20, 30], $sections[0]['columns']);
    }

    public function testDiffColumnDetectedWhenValuesMismatch(): void
    {
        $a = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);
        $b = $this->coating(0, 30, dryToTouch: [$this->point(20, 90)]);

        $sections = $this->builder()->build([$a, $b]);
        $section = $this->findSection($sections, 'Сухой на отлип');

        self::assertNotNull($section);
        self::assertArrayHasKey(20, $section['diffColumns']);
        self::assertArrayNotHasKey(0, $section['diffColumns']); // оба null
    }

    public function testNoDiffWhenValuesMatch(): void
    {
        $a = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);
        $b = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);

        $sections = $this->builder()->build([$a, $b]);
        $section = $this->findSection($sections, 'Сухой на отлип');

        self::assertSame([], $section['diffColumns']);
    }

    public function testMissingSubjectRowGetsNullCells(): void
    {
        // a has dryToTouch, b doesn't → секция dryToTouch есть, у b всё null.
        $a = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);
        $b = $this->coating(0, 30);

        $sections = $this->builder()->build([$a, $b]);
        $section = $this->findSection($sections, 'Сухой на отлип');

        self::assertNotNull($section);
        self::assertSame(60, $section['rows'][0]['values'][20]['minutes']);
        self::assertNull($section['rows'][1]['values'][20]['minutes']);
        // 20°C: значения (60, null) → различаются → diff
        self::assertArrayHasKey(20, $section['diffColumns']);
    }

    public function testFullyEmptySectionSkipped(): void
    {
        // Оба subject'а без fullCure → секция «Полное отверждение» не появляется.
        $a = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);
        $b = $this->coating(0, 30, dryToTouch: [$this->point(20, 60)]);

        $sections = $this->builder()->build([$a, $b]);

        self::assertNull($this->findSection($sections, 'Полное отверждение'));
    }

    public function testEmptySubjectsListReturnsEmpty(): void
    {
        self::assertSame([], $this->builder()->build([]));
    }

    public function testEnvBaseBranchGetsOwnSection(): void
    {
        $treeA = new RecoatingIntervalTreeDTO();
        $treeA->default = [$this->point(20, 240)];
        $envA = new RecoatingIntervalTreeDTO();
        $envA->default = [$this->point(20, 100)];
        $baseA = new RecoatingIntervalTreeDTO();
        $baseA->default = [$this->point(20, 60)];
        $envA->branches['pur'] = $baseA;
        $treeA->branches['atmospheric'] = $envA;

        $a = $this->coating(0, 30, minRecoatingTree: $treeA);

        $sections = $this->builder()->build([$a, $a]);

        self::assertNotNull($this->findSection(
            $sections,
            'Интервал перекрытия (мин), атмосферная эксплуатация → Полиуретановое',
        ));
    }

    private function builder(): CoatingCompareMatrixBuilder
    {
        return new CoatingCompareMatrixBuilder(new CoatingTimeMatrixBuilder());
    }

    private function findSection(array $sections, string $label): ?array
    {
        foreach ($sections as $s) {
            if ($s['label'] === $label) {
                return $s;
            }
        }
        return null;
    }

    /** @param list<DryingTimePointDTO> $dryToTouch */
    private function coating(
        int $appMin,
        int $dryingMax,
        array $dryToTouch = [],
        ?RecoatingIntervalTreeDTO $minRecoatingTree = null,
    ): CoatingDTO {
        $c = new CoatingDTO();
        $c->applicationMinTemp = $appMin;
        $c->dryingMaxTemp = $dryingMax;
        $c->dryToTouch = $dryToTouch;
        $c->fullCure = [];
        $c->minRecoatingInterval = $minRecoatingTree ?? new RecoatingIntervalTreeDTO();
        $c->maxRecoatingInterval = null;
        return $c;
    }

    private function point(int $tempAt, ?int $timeInMinutes): DryingTimePointDTO
    {
        $p = new DryingTimePointDTO();
        $p->temperature_at = $tempAt;
        $p->time_in_minutes = $timeInMinutes;
        $p->is_calculated = false;
        return $p;
    }
}

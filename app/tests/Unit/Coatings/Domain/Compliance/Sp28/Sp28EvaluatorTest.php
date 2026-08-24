<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Compliance\Compliance;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Sp28\Sp28Evaluator;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class Sp28EvaluatorTest extends TestCase
{
    public function test_supports_only_sp28(): void
    {
        $evaluator = new Sp28Evaluator();

        self::assertTrue($evaluator->supports(ComplianceStandard::SP_28));
        self::assertFalse($evaluator->supports(ComplianceStandard::ISO_12944));
    }

    public function test_carbon_epoxy_finish_reaches_strong_outdoor(): void
    {
        // Углеродистая сталь, финиш эпоксид (группа IV по Ц.7), ТСП 200 мкм.
        // INDOOR: STRONG требует 240 → MEDIUM. OUTDOOR: STRONG(IV,200) достигнут. LIQUID: MEDIUM(220) мимо → WEAK.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::EP, 200]]);

        self::assertEquals(
            [
                new Compliance(ComplianceStandard::SP_28, 'medium', 'indoor'),
                new Compliance(ComplianceStandard::SP_28, 'strong', 'outdoor'),
                new Compliance(ComplianceStandard::SP_28, 'weak', 'liquid'),
            ],
            (new Sp28Evaluator())->evaluate($system),
        );
    }

    public function test_carbon_polyurethane_thick_reaches_strong_everywhere(): void
    {
        // Полиуретан (группа IV), ТСП 300 мкм — закрывает Сильноагрессивную по всем условиям.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::PUR, 300]]);

        self::assertEquals(
            [
                new Compliance(ComplianceStandard::SP_28, 'strong', 'indoor'),
                new Compliance(ComplianceStandard::SP_28, 'strong', 'outdoor'),
                new Compliance(ComplianceStandard::SP_28, 'strong', 'liquid'),
            ],
            (new Sp28Evaluator())->evaluate($system),
        );
    }

    public function test_finish_group_determines_result_multilayer(): void
    {
        // Грунт эпоксид + финиш акрил (группа II): группу задаёт верхний слой (II).
        // Для углеродистой стали WEAK требует группу III → ни одной метки.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80],
            [CoatingBase::AY, 120],
        ]);

        self::assertSame([], (new Sp28Evaluator())->evaluate($system));
    }

    public function test_thin_epoxy_reaches_only_weak_indoor(): void
    {
        // Группа III, но 130 мкм: INDOOR WEAK(120) проходит, MEDIUM(160) нет;
        // OUTDOOR/LIQUID WEAK требуют 160 → мимо.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::EP, 130]]);

        self::assertEquals(
            [new Compliance(ComplianceStandard::SP_28, 'weak', 'indoor')],
            (new Sp28Evaluator())->evaluate($system),
        );
    }

    public function test_group_one_alkyd_not_marked(): void
    {
        // Алкид = группа I; для углеродистой стали минимум группа III → маркировки нет.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::AK, 300]]);

        self::assertSame([], (new Sp28Evaluator())->evaluate($system));
    }

    public function test_polyaspartic_finish_not_marked(): void
    {
        // PAS группу по СП не получает → системе по СП маркировка не присваивается.
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::PAS, 300]]);

        self::assertSame([], (new Sp28Evaluator())->evaluate($system));
    }

    public function test_non_metal_substrate_not_marked(): void
    {
        $system = $this->makeSystem(Substrate::CONCRETE, [[CoatingBase::EP, 300]]);

        self::assertSame([], (new Sp28Evaluator())->evaluate($system));
    }

    public function test_galvanized_epoxy(): void
    {
        // Цинк, эпоксид (группа IV), 200 мкм: INDOOR MEDIUM(160), OUTDOOR MEDIUM(120),
        // LIQUID MEDIUM(IV,180) достигнут; STRONG у цинка «не применять» — правил нет.
        $system = $this->makeSystem(Substrate::STEEL_GALVANIZED, [[CoatingBase::EP, 200]]);

        self::assertEquals(
            [
                new Compliance(ComplianceStandard::SP_28, 'medium', 'indoor'),
                new Compliance(ComplianceStandard::SP_28, 'medium', 'outdoor'),
                new Compliance(ComplianceStandard::SP_28, 'medium', 'liquid'),
            ],
            (new Sp28Evaluator())->evaluate($system),
        );
    }

    public function test_empty_system_not_marked(): void
    {
        self::assertSame([], (new Sp28Evaluator())->evaluate($this->makeSystem(Substrate::STEEL_CARBON, [])));
    }

    // --- helpers ---

    /**
     * @param list<array{0: CoatingBase, 1: int}> $layers [(base, dft)]; последний слой — финиш
     */
    private function makeSystem(Substrate $substrate, array $layers): CoatingSystem
    {
        $system = new CoatingSystem(
            Uuid::v7(),
            'Test System',
            'description',
            $substrate,
            new SurfaceTreatment(Uuid::v7(), 'Abrasive blast', 'Sa 2.5', null, Substrate::cases()),
        );

        foreach ($layers as [$base, $dft]) {
            $system->appendLayer($this->makeCoating($base), $dft);
        }

        return $system;
    }

    private function makeCoating(CoatingBase $base): Coating
    {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50,
            1.2,
            $base,
            new DftRange(new PositiveNumberRange(40, 500), 250, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            null,
            1.0,
            null,
            $manufacturer,
            new CoatingSpecification($this->createMock(UniqueTitleCoatingSpecification::class)),
            50,
        );
    }
}

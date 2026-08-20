<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance;

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
use App\Coatings\Domain\Compliance\Iso12944\Iso12944Evaluator;
use App\Coatings\Domain\Compliance\Iso12944\Iso12944Rule;
use App\Coatings\Domain\Compliance\Iso12944\PrimerType;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class Iso12944EvaluatorTest extends TestCase
{
    public function test_supports_only_iso(): void
    {
        self::assertTrue((new Iso12944Evaluator([]))->supports(ComplianceStandard::ISO_12944));
    }

    public function test_matches_when_system_satisfies_rule(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 160,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        self::assertEquals(
            [new Compliance(ComplianceStandard::ISO_12944, 'C3', 'HIGH')],
            $evaluator->evaluate($system),
        );
    }

    public function test_no_match_when_ndft_insufficient(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 200,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        self::assertSame([], $evaluator->evaluate($system));
    }

    public function test_no_match_when_primer_binder_not_allowed(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::PUR],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::EP, 80, true]]);

        self::assertSame([], $evaluator->evaluate($system));
    }

    public function test_no_match_when_followup_binder_not_allowed(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 160,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        self::assertSame([], $evaluator->evaluate($system));
    }

    public function test_no_match_when_primer_type_differs(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [[CoatingBase::EP, 80, false]]);

        self::assertSame([], $evaluator->evaluate($system));
    }

    public function test_no_match_when_substrate_differs(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        )]);

        $system = $this->makeSystem(Substrate::STEEL_GALVANIZED, [[CoatingBase::EP, 80, true]]);

        self::assertSame([], $evaluator->evaluate($system));
    }

    public function test_empty_system_returns_empty_list(): void
    {
        $evaluator = new Iso12944Evaluator([$this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP],
        )]);

        self::assertSame([], $evaluator->evaluate($this->makeSystem(Substrate::STEEL_CARBON, [])));
    }

    public function test_keeps_only_strongest_dominating_pairs(): void
    {
        $evaluator = new Iso12944Evaluator([
            $this->makeRule(
                substrate: Substrate::STEEL_CARBON,
                category: 'C4',
                durability: 'HIGH',
                primerType: PrimerType::OTHER,
                mnoc: 2,
                ndft: 160,
                primerBinders: [CoatingBase::EP],
                otherBinders: [CoatingBase::EP, CoatingBase::PUR],
            ),
            $this->makeRule(
                substrate: Substrate::STEEL_CARBON,
                category: 'C5',
                durability: 'VERY_HIGH',
                primerType: PrimerType::OTHER,
                mnoc: 3,
                ndft: 360,
                primerBinders: [CoatingBase::EP],
                otherBinders: [CoatingBase::EP, CoatingBase::PUR],
            ),
        ]);

        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 150, false],
            [CoatingBase::EP, 150, false],
            [CoatingBase::PUR, 60, false],
        ]);

        self::assertEquals(
            [new Compliance(ComplianceStandard::ISO_12944, 'C5', 'VERY_HIGH')],
            $evaluator->evaluate($system),
        );
    }

    // --- helpers (зеркалят прежний ComplianceEvaluatorTest) ---

    /**
     * @param list<array{0: CoatingBase, 1: int, 2: bool}> $layers [(base, dft, isZincRich)]
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

        foreach ($layers as [$base, $dft, $isZincRich]) {
            $system->appendLayer($this->makeCoating($base, 40, 500, $isZincRich), $dft);
        }

        return $system;
    }

    private function makeCoating(CoatingBase $base, int $dftMin, int $dftMax, bool $isZincRich = false): Coating
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
            new DftRange(new PositiveNumberRange($dftMin, $dftMax), (int) (($dftMin + $dftMax) / 2), ThicknessType::MIC),
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
            $isZincRich,
        );
    }

    /**
     * @param list<CoatingBase> $primerBinders
     * @param list<CoatingBase> $otherBinders
     */
    private function makeRule(
        Substrate $substrate,
        string $category,
        string $durability,
        PrimerType $primerType,
        int $mnoc,
        int $ndft,
        array $primerBinders,
        array $otherBinders,
    ): Iso12944Rule {
        return new Iso12944Rule(
            standard: ComplianceStandard::ISO_12944,
            substrate: $substrate,
            category: $category,
            durability: $durability,
            primerType: $primerType,
            mnoc: $mnoc,
            ndft: $ndft,
            primerBinders: $primerBinders,
            otherBinders: $otherBinders,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceMatch;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceMatches;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRule;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\PrimerType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ComplianceEvaluatorTest extends TestCase
{
    public function test_matches_when_system_satisfies_rule(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 160,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(1, $result);
        $matches = $result->toArray();
        self::assertContainsEquals(
            new ComplianceMatch(ComplianceStandard::ISO_12944, 'C3', 'HIGH'),
            $matches,
        );
    }

    public function test_no_match_when_ndft_insufficient(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 200,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    public function test_no_match_when_primer_binder_not_allowed(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::PUR],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    public function test_no_match_when_followup_binder_not_allowed(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 2,
            ndft: 160,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, true],
            [CoatingBase::PUR, 100, false],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    public function test_no_match_when_primer_type_differs(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, [
            [CoatingBase::EP, 80, false],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    public function test_no_match_when_substrate_differs(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP, CoatingBase::PUR],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_GALVANIZED, [
            [CoatingBase::EP, 80, true],
        ]);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    public function test_empty_system_returns_empty_result(): void
    {
        $rule = $this->makeRule(
            substrate: Substrate::STEEL_CARBON,
            category: 'C3',
            durability: 'HIGH',
            primerType: PrimerType::ZINC_RICH,
            mnoc: 1,
            ndft: 60,
            primerBinders: [CoatingBase::EP],
            otherBinders: [CoatingBase::EP],
        );

        $evaluator = new ComplianceEvaluator([$rule]);
        $system = $this->makeSystem(Substrate::STEEL_CARBON, []);

        $result = $evaluator->evaluate($system);
        self::assertInstanceOf(ComplianceMatches::class, $result);
        self::assertCount(0, $result);
    }

    // --- helpers ---

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
            new CoatingSystemChainValidator(),
        );

        foreach ($layers as [$base, $dft, $isZincRich]) {
            $coating = $this->makeCoating($base, 40, 500, $isZincRich);
            $system->appendLayer($coating, $dft);
        }

        return $system;
    }

    private function makeCoating(CoatingBase $base, int $dftMin, int $dftMax, bool $isZincRich = false): Coating
    {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        $spec = new CoatingSpecification(
            $this->createMock(UniqueTitleCoatingSpecification::class),
        );

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
            $spec,
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
    ): ComplianceRule {
        return new ComplianceRule(
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

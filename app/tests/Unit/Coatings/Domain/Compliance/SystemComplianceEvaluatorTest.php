<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Compliance\Compliance;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;
use App\Coatings\Domain\Compliance\Iso12944\Iso12944Facets;
use App\Coatings\Domain\Compliance\StandardEvaluator;
use App\Coatings\Domain\Compliance\SystemComplianceEvaluator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SystemComplianceEvaluatorTest extends TestCase
{
    public function test_empty_patronage_returns_empty(): void
    {
        $evaluator = new SystemComplianceEvaluator([]);

        self::assertSame([], $evaluator->evaluate($this->makeEmptySystem()));
    }

    public function test_aggregates_results_from_all_evaluators_in_order(): void
    {
        $a = new Compliance(ComplianceStandard::ISO_12944, 'C3', 'HIGH');
        $b = new Compliance(ComplianceStandard::ISO_12944, 'C5', 'MEDIUM');

        $evaluator = new SystemComplianceEvaluator([
            $this->fakeEvaluator([$a]),
            $this->fakeEvaluator([]),
            $this->fakeEvaluator([$b]),
        ]);

        self::assertSame([$a, $b], $evaluator->evaluate($this->makeEmptySystem()));
    }

    /**
     * @param list<Compliance> $out
     */
    private function fakeEvaluator(array $out): StandardEvaluator
    {
        return new class($out) implements StandardEvaluator {
            /** @param list<Compliance> $out */
            public function __construct(private array $out)
            {
            }

            public function supports(ComplianceStandard $standard): bool
            {
                return true;
            }

            public function evaluate(CoatingSystem $system): array
            {
                return $this->out;
            }

            public function facets(): StandardFacets
            {
                return new Iso12944Facets();
            }
        };
    }

    private function makeEmptySystem(): CoatingSystem
    {
        return new CoatingSystem(
            Uuid::v7(),
            'Test System',
            'description',
            Substrate::STEEL_CARBON,
            new SurfaceTreatment(Uuid::v7(), 'Abrasive blast', 'Sa 2.5', null, Substrate::cases()),
        );
    }
}

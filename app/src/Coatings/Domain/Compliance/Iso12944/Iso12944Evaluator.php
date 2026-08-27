<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Compliance\Compliance;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;
use App\Coatings\Domain\Compliance\StandardEvaluator;

/**
 * Оценщик ISO 12944 (ГОСТ 34667.5—2021). Матчит физические факты системы против правил ISO
 * (Iso12944RuleBook — приложения C/D/E) и отдаёт результат как список Compliance
 * (primary = категория коррозивности, secondary = долговечность). Правила ISO — статические
 * доменные данные; свёртка до сильнейших пар — Iso12944Matches::strongestOnly.
 */
final readonly class Iso12944Evaluator implements StandardEvaluator
{
    /** @var list<Iso12944Rule> */
    private array $rules;

    /**
     * @param list<Iso12944Rule>|null $rules переопределяется в юнит-тестах;
     *                                       в проде — Iso12944RuleBook::rules()
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? Iso12944RuleBook::rules();
    }

    public function supports(ComplianceStandard $standard): bool
    {
        return ComplianceStandard::ISO_12944 === $standard;
    }

    public function facets(): StandardFacets
    {
        return new Iso12944Facets();
    }

    /**
     * @return list<Compliance>
     */
    public function evaluate(CoatingSystem $system): array
    {
        if (0 === $system->layerCount()) {
            return [];
        }

        $first = $system->firstLayer()->getCoating();
        $primerType = $first->isZincRich() ? PrimerType::ZINC_RICH : PrimerType::OTHER;
        $ndft = $system->totalDft();
        $mnoc = $system->layerCount();
        $primerBase = $first->getBase();
        $followupBases = [];
        foreach ($system->followupLayers() as $layer) {
            $followupBases[] = $layer->getCoating()->getBase();
        }

        $matches = new Iso12944Matches();
        foreach ($this->rules as $rule) {
            if ($rule->substrate !== $system->getSubstrate()) {
                continue;
            }
            if ($rule->primerType !== $primerType) {
                continue;
            }
            if ($mnoc < $rule->mnoc) {
                continue;
            }
            if ($ndft < $rule->ndft) {
                continue;
            }
            // null-список связующих = без ограничения (CX/Im4: ГОСТ 34667.9 связующие не задаёт).
            if (null !== $rule->primerBinders && !in_array($primerBase, $rule->primerBinders, true)) {
                continue;
            }
            if (null !== $rule->otherBinders) {
                $mismatch = false;
                foreach ($followupBases as $base) {
                    if (!in_array($base, $rule->otherBinders, true)) {
                        $mismatch = true;
                        break;
                    }
                }
                if ($mismatch) {
                    continue;
                }
            }
            $matches->add(new Iso12944Match($rule->standard, $rule->category, $rule->durability));
        }

        return array_map(
            static fn (Iso12944Match $m): Compliance => new Compliance($m->standard, $m->category, $m->durability),
            $matches->strongestOnly()->toArray(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final class ComplianceEvaluator
{
    /**
     * @param iterable<ComplianceRule> $rules
     */
    public function __construct(private readonly iterable $rules)
    {
    }

    public function evaluate(CoatingSystem $system): ComplianceMatches
    {
        $result = new ComplianceMatches();

        if (0 === $system->layerCount()) {
            return $result;
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
            if (!in_array($primerBase, $rule->primerBinders, true)) {
                continue;
            }
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
            $result->add(new ComplianceMatch(
                $rule->standard,
                $rule->category,
                $rule->durability,
            ));
        }

        return $result->strongestOnly();
    }
}

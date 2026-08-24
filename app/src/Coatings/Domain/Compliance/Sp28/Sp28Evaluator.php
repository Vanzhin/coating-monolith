<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Compliance\Compliance;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\StandardFacets;
use App\Coatings\Domain\Compliance\StandardEvaluator;

/**
 * Оценщик соответствия систем требованиям СП 28.13330.2017 (металл, таблицы Ц.1 + Ц.7).
 * Группа системы = группа финишного (верхнего) слоя по его связующему (Ц.7); матч против Ц.1 идёт
 * по паре (основание × толщина) с субсумпцией: высшая группа закрывает требования низших. Наружу —
 * по одной сильнейшей достижимой степени на каждое условие эксплуатации. Долговечности в СП нет.
 */
final readonly class Sp28Evaluator implements StandardEvaluator
{
    public function supports(ComplianceStandard $standard): bool
    {
        return ComplianceStandard::SP_28 === $standard;
    }

    public function facets(): StandardFacets
    {
        return new Sp28Facets();
    }

    /**
     * @return list<Compliance>
     */
    public function evaluate(CoatingSystem $system): array
    {
        if (0 === $system->layerCount()) {
            return [];
        }

        $group = self::groupOf($system->finishLayer()->getCoating()->getBase());
        if (null === $group) {
            return [];
        }

        $substrate = $system->getSubstrate();
        $dft = $system->totalDft();

        /** @var array<string, SpAggressivity> $strongest сильнейшая достижимая степень на условие */
        $strongest = [];
        foreach (Sp28RuleBook::rules() as $rule) {
            if ($rule->substrate !== $substrate) {
                continue;
            }
            if ($group < $rule->group || $dft < $rule->dft) {
                continue;
            }
            $key = $rule->condition->value;
            if (!isset($strongest[$key]) || $rule->degree->rank() > $strongest[$key]->rank()) {
                $strongest[$key] = $rule->degree;
            }
        }

        $out = [];
        foreach (SpExploitation::cases() as $condition) {
            $degree = $strongest[$condition->value] ?? null;
            if (null !== $degree) {
                $out[] = new Compliance(ComplianceStandard::SP_28, $degree->value, $condition->value);
            }
        }

        return $out;
    }

    /**
     * Группа ЛКП по Ц.7 (металл) из связующего финишного слоя — верхняя группа, на которую способно
     * связующее по стандарту: эпоксидные / полиуретановые / фторполимерные заявлены как III–IV → IV;
     * этилсиликатные и полисилоксановые — III; акриловые — II; алкидные — I. null — тип, которому Ц.7
     * группу не присваивает (полиаспартатные) → система по СП не маркируется.
     */
    private static function groupOf(CoatingBase $base): ?int
    {
        return match ($base) {
            CoatingBase::AK => 1,
            CoatingBase::AY => 2,
            CoatingBase::ESI, CoatingBase::PS => 3,
            CoatingBase::EP, CoatingBase::PUR, CoatingBase::FEVE => 4,
            CoatingBase::PAS => null,
        };
    }
}

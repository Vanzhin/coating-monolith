<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;

/**
 * Требования СП 28.13330.2017, таблица Ц.1 «Защитные покрытия стальных конструкций из фасонного и
 * толстолистового проката», приведённые к виду (основание × условия × степень) → (группа, толщина).
 *
 * Свёртка: подтипы среды (газы группы А / малорастворимые соли / газы В,С,D / хорошо растворимые
 * соли) и дробление слабоагрессивной на -1/-2 сведены к МАКСИМУМУ требований на каждую пару
 * (условия × степень) — консервативно (без over-claim). Ячейки «без лакокрасочного покрытия» (цинк
 * закрывает уровень сам) и «не применять» правил не порождают. `IV-300…500` для жидких — берём 300.
 *
 * Колонки материала: STEEL_CARBON — углеродистая/низколегированная сталь без металлических покрытий;
 * STEEL_GALVANIZED — цинковые покрытия (горячее/термодиффузионное); STEEL_METALLIZED — цинковые и
 * алюминиевые газотермические покрытия. Группа хранится как int 1..4 (I..IV).
 */
final class Sp28RuleBook
{
    /**
     * @return list<Sp28Rule>
     */
    public static function rules(): array
    {
        return [
            // --- Углеродистая сталь без металлических покрытий ---
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::INDOOR, SpAggressivity::WEAK, 3, 120),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::INDOOR, SpAggressivity::MEDIUM, 3, 160),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::INDOOR, SpAggressivity::STRONG, 4, 240),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::OUTDOOR, SpAggressivity::WEAK, 3, 160),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::OUTDOOR, SpAggressivity::MEDIUM, 3, 160),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::OUTDOOR, SpAggressivity::STRONG, 4, 200),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::LIQUID, SpAggressivity::WEAK, 3, 160),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::LIQUID, SpAggressivity::MEDIUM, 4, 220),
            new Sp28Rule(Substrate::STEEL_CARBON, SpExploitation::LIQUID, SpAggressivity::STRONG, 4, 300),

            // --- Цинковые покрытия (WEAK внутри/снаружи — «без ЛКП», правил нет; STRONG — «не применять») ---
            new Sp28Rule(Substrate::STEEL_GALVANIZED, SpExploitation::INDOOR, SpAggressivity::MEDIUM, 3, 160),
            new Sp28Rule(Substrate::STEEL_GALVANIZED, SpExploitation::OUTDOOR, SpAggressivity::MEDIUM, 3, 120),
            new Sp28Rule(Substrate::STEEL_GALVANIZED, SpExploitation::LIQUID, SpAggressivity::WEAK, 3, 160),
            new Sp28Rule(Substrate::STEEL_GALVANIZED, SpExploitation::LIQUID, SpAggressivity::MEDIUM, 4, 180),

            // --- Цинковые и алюминиевые газотермические покрытия (WEAK атмосфера — «без ЛКП») ---
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::INDOOR, SpAggressivity::MEDIUM, 3, 160),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::INDOOR, SpAggressivity::STRONG, 4, 240),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::OUTDOOR, SpAggressivity::MEDIUM, 3, 120),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::OUTDOOR, SpAggressivity::STRONG, 4, 240),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::LIQUID, SpAggressivity::WEAK, 3, 160),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::LIQUID, SpAggressivity::MEDIUM, 4, 200),
            new Sp28Rule(Substrate::STEEL_METALLIZED, SpExploitation::LIQUID, SpAggressivity::STRONG, 4, 240),
        ];
    }
}

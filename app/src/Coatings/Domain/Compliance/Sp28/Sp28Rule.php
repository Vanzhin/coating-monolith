<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;

/**
 * Одна строка требований СП 28.13330 (таблица Ц.1), уже свёрнутая по подтипам среды до максимума:
 * для пары (основание × условия эксплуатации × степень) — минимально необходимая группа ЛКП (I–IV
 * как int 1..4) и минимальная суммарная толщина покрытия, мкм.
 */
final readonly class Sp28Rule
{
    public function __construct(
        public Substrate $substrate,
        public SpExploitation $condition,
        public SpAggressivity $degree,
        public int $group,
        public int $dft,
    ) {
    }
}

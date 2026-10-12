<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Database\Seed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Инвариант сид-данных: у одного канонического вещества в пределах листа не может
 * быть двух РАЗНЫХ вердиктов. Такая пара (напр. R и NR) при загрузке схлопывается
 * UNIQUE (coating, substance) + ON CONFLICT ... keeping last — один вердикт молча
 * теряется. Если в docx строки отличались уточнением (концентрация «<20%» и т.п.),
 * их надо разводить в разные вещества, а не склеивать в один канон.
 */
final class SeedGradeConsistencyTest extends TestCase
{
    #[DataProvider('seedFiles')]
    public function test_no_canonical_has_conflicting_grades(string $file): void
    {
        $path = dirname(__DIR__, 6).'/src/ChemicalResistance/Infrastructure/Database/Seed/'.$file;
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $gradesByCanonical = [];
        foreach ($data['assessments'] as $a) {
            $gradesByCanonical[$a['substance']][$a['grade']] = true;
        }

        $conflicting = [];
        foreach ($gradesByCanonical as $canonical => $grades) {
            if (count($grades) > 1) {
                $conflicting[] = sprintf('«%s»: %s', $canonical, implode('/', array_keys($grades)));
            }
        }

        self::assertSame(
            [],
            $conflicting,
            "Канонические вещества с противоречивыми вердиктами в {$file}:\n".implode("\n", $conflicting),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function seedFiles(): array
    {
        return [
            'classic' => ['litatank_classic.json'],
            'standart' => ['litatank_standart.json'],
            'plus' => ['litatank_plus.json'],
        ];
    }
}

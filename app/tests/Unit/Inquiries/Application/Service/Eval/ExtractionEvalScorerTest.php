<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service\Eval;

use App\Inquiries\Application\Service\Eval\AxisAccuracy;
use App\Inquiries\Application\Service\Eval\ExtractionEvalReport;
use App\Inquiries\Application\Service\Eval\ExtractionEvalScorer;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use PHPUnit\Framework\TestCase;

final class ExtractionEvalScorerTest extends TestCase
{
    public function test_classifies_axes_across_approved(): void
    {
        $report = (new ExtractionEvalScorer())->score([
            // env совпал, температуру правили (90→80), подложки те же (другой порядок)
            RecognizedParameters::recognized(['environment' => 'special', 'substanceTemp' => 90, 'substrates' => ['a', 'b']])
                ->withManualEdits(['environment' => 'special', 'substanceTemp' => 80, 'substrates' => ['b', 'a']]),
            // env совпал, стандарт робот пропустил (человек задал)
            RecognizedParameters::recognized(['environment' => 'special'])
                ->withManualEdits(['environment' => 'special', 'standard' => 'SP_28']),
            // поиск робот дал лишний (человек убрал)
            RecognizedParameters::recognized(['search' => 'пропарка'])
                ->withManualEdits(['search' => null]),
            // пустая ось — не в игре, в счёт не идёт (но запрос учтён)
            RecognizedParameters::recognized(['category' => null]),
        ]);

        self::assertSame(4, $report->inquiriesScored);

        $env = $this->axis($report, 'environment');
        self::assertSame([2, 2, 0], [$env->total, $env->agreed, $env->mismatch]);
        self::assertSame(1.0, $env->accuracy());

        $temp = $this->axis($report, 'substanceTemp');
        self::assertSame([1, 0, 1], [$temp->total, $temp->agreed, $temp->mismatch]);
        self::assertSame(0.0, $temp->accuracy());

        self::assertSame(1, $this->axis($report, 'standard')->missed);
        self::assertSame(1, $this->axis($report, 'search')->extra);
        self::assertSame(1.0, $this->axis($report, 'substrates')->accuracy()); // порядок в массиве не промах

        self::assertNull($this->tryAxis($report, 'category')); // пустая ось не попала в отчёт
    }

    public function test_sorts_weakest_axis_first(): void
    {
        $report = (new ExtractionEvalScorer())->score([
            RecognizedParameters::recognized(['environment' => 'special', 'substanceTemp' => 90])
                ->withManualEdits(['environment' => 'special', 'substanceTemp' => 80]),
        ]);

        self::assertSame('substanceTemp', $report->axes[0]->axis); // 0% — сверху
        self::assertSame('environment', $report->axes[array_key_last($report->axes)]->axis); // 100% — снизу
    }

    private function axis(ExtractionEvalReport $report, string $name): AxisAccuracy
    {
        $found = $this->tryAxis($report, $name);
        self::assertNotNull($found, "ось {$name} отсутствует в отчёте");

        return $found;
    }

    private function tryAxis(ExtractionEvalReport $report, string $name): ?AxisAccuracy
    {
        foreach ($report->axes as $a) {
            if ($a->axis === $name) {
                return $a;
            }
        }

        return null;
    }
}

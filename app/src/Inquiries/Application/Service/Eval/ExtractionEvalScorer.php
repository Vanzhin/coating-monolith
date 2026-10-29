<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\Eval;

use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;

/**
 * Градусник точности извлечения. По одобренным запросам считает по каждой оси, насколько ответ
 * робота совпал с тем, что в итоге одобрил инженер. Модель НЕ зовёт — читает сохранённого робота
 * (RecognizedValue::robotValue) против эффективного значения. Ось, которую не заполнил ни робот, ни
 * человек, в конкретном запросе не учитывается (не в игре).
 */
final class ExtractionEvalScorer
{
    /**
     * @param iterable<RecognizedParameters> $approved
     */
    public function score(iterable $approved): ExtractionEvalReport
    {
        /** @var array<string, array{total: int, agreed: int, missed: int, extra: int, mismatch: int}> $tally */
        $tally = [];
        $scored = 0;

        foreach ($approved as $params) {
            ++$scored;
            foreach ($params->axisComparison() as $axis => $pair) {
                $robotEmpty = $this->isEmpty($pair['robot']);
                $humanEmpty = $this->isEmpty($pair['human']);
                if ($robotEmpty && $humanEmpty) {
                    continue; // ось не в игре для этого запроса
                }

                $tally[$axis] ??= ['total' => 0, 'agreed' => 0, 'missed' => 0, 'extra' => 0, 'mismatch' => 0];
                ++$tally[$axis]['total'];
                $bucket = match (true) {
                    $this->same($pair['robot'], $pair['human']) => 'agreed',
                    $robotEmpty => 'missed',
                    $humanEmpty => 'extra',
                    default => 'mismatch',
                };
                ++$tally[$axis][$bucket];
            }
        }

        $axes = [];
        foreach ($tally as $axis => $c) {
            $axes[] = new AxisAccuracy($axis, $c['total'], $c['agreed'], $c['missed'], $c['extra'], $c['mismatch']);
        }
        usort($axes, static fn (AxisAccuracy $a, AxisAccuracy $b) => $a->accuracy() <=> $b->accuracy()); // слабые сверху

        return new ExtractionEvalReport($axes, $scored);
    }

    private function isEmpty(mixed $value): bool
    {
        return null === $value || [] === $value || '' === $value;
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $this->normalize($a) === $this->normalize($b);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            sort($value); // массивы сравниваем как множества — порядок не важен
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Одно распознанное значение оси подбора: эффективное значение, кто его дал и что выдал робот изначально.
 * value/robotValue — непрозрачный примитив входа фильтра (string|int|bool|list<string>|null); типами
 * владеет CoatingSystemsFilterInput, здесь их не трактуем. robotValue сохраняет оригинал робота даже
 * после ручной правки — это топливо для измерения точности модели.
 *
 * @phpstan-type RecognizedScalar string|int|bool|list<string>|null
 */
final readonly class RecognizedValue
{
    /**
     * @param RecognizedScalar $value
     * @param RecognizedScalar $robotValue
     */
    private function __construct(
        public string|int|bool|array|null $value,
        public ParameterStrategy $origin,
        public string|int|bool|array|null $robotValue,
    ) {
    }

    /**
     * Значение при распознавании (модель или кэш). Робот = эффективному.
     *
     * @param RecognizedScalar $value
     */
    public static function recognized(string|int|bool|array|null $value, ParameterStrategy $origin = ParameterStrategy::Model): self
    {
        return new self($value, $origin, $value);
    }

    /**
     * Инженер перекрыл значение. Оригинал робота (robotValue) сохраняется — и при повторной правке тоже.
     *
     * @param RecognizedScalar $value
     */
    public function editedByHuman(string|int|bool|array|null $value): self
    {
        return new self($value, ParameterStrategy::Manual, $this->robotValue);
    }

    public function wasEdited(): bool
    {
        return ParameterStrategy::Manual === $this->origin;
    }
}

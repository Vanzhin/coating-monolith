<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use JsonSerializable;

abstract class NumberRange implements JsonSerializable
{
    // Свойства защищены (protected) и немутабельны (readonly)
    protected readonly int|float $min;
    protected readonly int|float $max;

    public function __construct(int|float $min, int|float $max)
    {
        // Сначала валидируем переданные значения
        $this->baseValidate($min, $max);

        // Позволяем дочерним классам выполнить свои проверки перед сохранением состояния
        $this->validate($min, $max);

        // Инициализируем свойства только если ВСЕ проверки пройдены успешны
        $this->min = $min;
        $this->max = $max;
    }

    /**
     * Базовая проверка, общая для всех диапазонов.
     */
    private function baseValidate(int|float $min, int|float $max): void
    {
        if ($max < $min) {
            throw new AppException('Максимальное значение не может быть менее минимального.');
        }
    }

    /**
     * Метод-хук для дочерних классов. Переопределяется в наследниках для кастомных правил.
     */
    protected function validate(int|float $min, int|float $max): void
    {
        // По умолчанию дополнительных проверок нет
    }

    public function getMin(): float|int
    {
        return $this->min;
    }

    public function getMax(): float|int
    {
        return $this->max;
    }

    /**
     * Безопасная "запись" минимума. Возвращает новый валидный экземпляр.
     */
    public function withMin(int|float $min): static
    {
        // Вызов static() создаст объект того класса, у которого метод был вызван (например CoatingThickness)
        return new static($min, $this->max);
    }

    /**
     * Безопасная "запись" максимума. Возвращает новый валидный экземпляр.
     */
    public function withMax(int|float $max): static
    {
        return new static($this->min, $max);
    }

    public function mean(): int|float
    {
        return ($this->min + $this->max) / 2;
    }

    public function isWithin(int|float $value): bool
    {
        return $value >= $this->min && $value <= $this->max;
    }

    public function jsonSerialize(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}

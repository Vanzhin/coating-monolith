<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Waybill\Fuel;

use App\Shared\Infrastructure\Exception\AppException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Fuel
{
    private string $type;

    public function __construct(
        private int|float|string $initial,
        private int|float|null $remaining,
        private ?Refuelings $refuelings,
        string $type,
    ) {
        $type = trim($type);
        if (1 === preg_match('/\A[\s\p{Z}]*\z/u', $type)) {
            throw new AppException('Укажите марку топлива путевого листа.');
        }
        try {
            $initialDecimal = BigDecimal::of((string) $initial);
        } catch (MathException $exception) {
            throw new AppException('Остаток топлива на начало должен быть неотрицательным числом.', previous: $exception);
        }
        if (!is_finite((float) $initial) || $initialDecimal->isNegative()) {
            throw new AppException('Остаток топлива на начало должен быть неотрицательным числом.');
        }
        if (null !== $remaining && (!is_finite((float) $remaining) || $remaining < 0)) {
            throw new AppException('Остаток топлива на конец должен быть неотрицательным числом.');
        }
        $available = null === $refuelings ? null : $initial + $refuelings->total();
        if (null !== $available && !is_finite((float) $available)) {
            throw new AppException('Количество доступного топлива должно быть конечным числом.');
        }
        $this->type = $type;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function initial(): int|float|string
    {
        return $this->initial;
    }

    public function remaining(): int|float
    {
        return $this->remaining ?? throw new AppException('Укажите остаток топлива на конец периода.');
    }

    public function remainingOrNull(): int|float|null
    {
        return $this->remaining;
    }

    public function refuelings(): Refuelings
    {
        return $this->refuelings ?? throw new AppException('Укажите количество заправленного топлива.');
    }

    public function refuelingsOrNull(): ?Refuelings
    {
        return $this->refuelings;
    }

    public function consumed(): int|float
    {
        if (is_string($this->initial) && !BigDecimal::of($this->initial)->isEqualTo((string) (float) $this->initial)) {
            throw new AppException('Точность начального остатка не позволяет рассчитать расход без потери данных.');
        }

        return $this->initial + $this->refuelings()->total() - $this->remaining();
    }
}
